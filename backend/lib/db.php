<?php
// Database helper - supports sqlite (default) and mysql via data/db_config.php
function get_db_config_path(): string {
    return __DIR__ . '/../data/db_config.php';
}

function load_db_config(): array {
    $path = get_db_config_path();
    if (is_file($path)) {
        $cfg = include $path;
        if (is_array($cfg)) return $cfg;
    }
    return [];
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = load_db_config();
    if (!empty($cfg['driver']) && $cfg['driver'] === 'mysql') {
        // require host, dbname, user, pass
        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? 3306;
        $dbname = $cfg['dbname'] ?? '';
        $user = $cfg['user'] ?? '';
        $pass = $cfg['pass'] ?? '';
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        // sqlite default
        $path = __DIR__ . '/../data/app.db';
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        $dsn = 'sqlite:' . $path;
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    ensure_tables($pdo);
    return $pdo;
}

function ensure_tables(PDO $pdo) {
    // Create minimal tables with SQL that depends on driver
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(191) PRIMARY KEY, v TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INT PRIMARY KEY AUTO_INCREMENT, username VARCHAR(191) UNIQUE, password VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $pdo->exec("CREATE TABLE IF NOT EXISTS backups (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL UNIQUE,
            path VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL,
            size BIGINT DEFAULT 0,
            status VARCHAR(50) DEFAULT 'active',
            notes TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        // sqlite
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS backups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            path TEXT NOT NULL,
            created_at TEXT NOT NULL,
            size INTEGER DEFAULT 0,
            status TEXT DEFAULT 'active',
            notes TEXT
        );");
    }
}

function get_data_dir(): string {
    $d = __DIR__ . '/../data';
    if (!is_dir($d)) mkdir($d, 0755, true);
    return $d;
}

function log_update(string $msg) {
    $d = get_data_dir();
    $file = $d . '/update.log';
    $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function setting_get(string $k, $default = null) {
    try {
        $pdo = get_db();
    } catch (Exception $e) {
        return $default;
    }
    $stmt = $pdo->prepare('SELECT v FROM settings WHERE k = :k');
    $stmt->execute([':k'=>$k]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : $v;
}

function setting_set(string $k, $v) {
    $pdo = get_db();
    $stmt = $pdo->prepare('REPLACE INTO settings (k,v) VALUES (:k,:v)');
    return $stmt->execute([':k'=>$k,':v'=>$v]);
}

function create_admin_if_missing() {
    $pdo = get_db();
    $stmt = $pdo->query('SELECT count(*) FROM users');
    if ($stmt->fetchColumn() == 0) {
        $pw = password_hash('admin', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username,password) VALUES (:u,:p)');
        $stmt->execute([':u'=>'admin', ':p'=>$pw]);
    }
}

function validate_user(string $user, string $pass): bool {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT password FROM users WHERE username = :u');
    $stmt->execute([':u'=>$user]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $hash = $row['password'] ?? null;
    if (!$hash) return false;
    return password_verify($pass, $hash);
}

function create_user(string $user, string $pass) {
    $pdo = get_db();
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (username,password) VALUES (:u,:p)');
    return $stmt->execute([':u'=>$user, ':p'=>$hash]);
}

function get_php_cli_path(): string {
    // 如果在 CLI 环境下，直接返回 PHP_BINARY
    if (php_sapi_name() === 'cli') {
        return PHP_BINARY;
    }
    
    // 在 FPM/CGI 环境下，尝试找到 PHP CLI 可执行文件
    $possiblePaths = [
        '/usr/bin/php',
        '/usr/local/bin/php',
        '/usr/bin/php8',
        '/usr/bin/php81',
        '/usr/bin/php82',
        '/usr/bin/php83',
        '/usr/bin/php84',
        '/opt/php/bin/php',
    ];
    
    // 尝试从 PHP_BINARY 推导（如果是 php-fpm，尝试同目录的 php）
    if (defined('PHP_BINARY') && PHP_BINARY) {
        $dir = dirname(PHP_BINARY);
        $possiblePaths[] = $dir . '/php';
        // 如果在 sbin 目录，尝试 bin 目录
        if (strpos($dir, '/sbin') !== false) {
            $binDir = str_replace('/sbin', '/bin', $dir);
            $possiblePaths[] = $binDir . '/php';
        }
    }
    
    // 检查每个可能的路径
    foreach ($possiblePaths as $path) {
        if (file_exists($path) && is_executable($path)) {
            return $path;
        }
    }
    
    // 最后尝试使用 which 命令
    $which = @shell_exec('which php 2>/dev/null');
    if ($which) {
        $which = trim($which);
        if (file_exists($which) && is_executable($which)) {
            return $which;
        }
    }
    
    // 如果都失败了，返回 PHP_BINARY 作为后备
    return PHP_BINARY;
}

// ==================== Backup Management Functions ====================

function get_backups_dir(): string {
    $d = __DIR__ . '/../../backups';
    if (!is_dir($d)) mkdir($d, 0755, true);
    return $d;
}

function create_backup_record(string $name, string $path, int $size = 0, string $notes = ''): int {
    $pdo = get_db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    
    // 使用 ISO 8601 格式的时间戳
    $now = date('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare(
        'INSERT INTO backups (name, path, created_at, size, status, notes) 
         VALUES (:name, :path, :created_at, :size, :status, :notes)'
    );
    $stmt->execute([
        ':name' => $name,
        ':path' => $path,
        ':created_at' => $now,
        ':size' => $size,
        ':status' => 'active',
        ':notes' => $notes
    ]);
    
    return (int)$pdo->lastInsertId();
}

function get_backups(string $status = 'active'): array {
    $pdo = get_db();
    if ($status === 'all') {
        $stmt = $pdo->query('SELECT * FROM backups ORDER BY created_at DESC');
    } else {
        $stmt = $pdo->prepare('SELECT * FROM backups WHERE status = :status ORDER BY created_at DESC');
        $stmt->execute([':status' => $status]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_backup_by_name(string $name): ?array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM backups WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $name]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function update_backup_status(string $name, string $status): bool {
    $pdo = get_db();
    $stmt = $pdo->prepare('UPDATE backups SET status = :status WHERE name = :name');
    return $stmt->execute([':status' => $status, ':name' => $name]);
}

function delete_backup_record(string $name): bool {
    $pdo = get_db();
    $stmt = $pdo->prepare('DELETE FROM backups WHERE name = :name');
    return $stmt->execute([':name' => $name]);
}

function calculate_dir_size(string $dir): int {
    $size = 0;
    if (!is_dir($dir)) return 0;
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($files as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    
    return $size;
}

function human_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB','MB','GB','TB'];
    $i = -1;
    do {
        $bytes /= 1024;
        $i++;
    } while($bytes >= 1024 && $i < count($units) - 1);
    return round($bytes, 2) . ' ' . $units[$i];
}
