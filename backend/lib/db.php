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
    try {
        if (!empty($cfg['driver']) && $cfg['driver'] === 'mysql') {
            // require host, dbname, user, pass
            $host = $cfg['host'] ?? '127.0.0.1';
            $port = $cfg['port'] ?? 3306;
            $dbname = $cfg['dbname'] ?? '';
            $user = $cfg['user'] ?? '';
            $pass = $cfg['pass'] ?? '';
            
            // 验证必要参数
            if (empty($dbname)) {
                throw new PDOException('数据库名称未配置');
            }
            if (empty($user)) {
                throw new PDOException('数据库用户名未配置');
            }
            
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5, // 5秒连接超时
            ]);
        } else {
            // sqlite default
            $path = __DIR__ . '/../data/app.db';
            $dir = dirname($path);
            
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new PDOException('无法创建数据目录：' . $dir);
                }
            }
            
            if (!is_writable($dir)) {
                throw new PDOException('数据目录不可写：' . $dir);
            }
            
            $dsn = 'sqlite:' . $path;
            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        ensure_tables($pdo, $cfg);
        return $pdo;
    } catch (PDOException $e) {
        // 记录详细错误到日志
        error_log('Database connection error: ' . $e->getMessage());
        error_log('DSN: ' . ($dsn ?? 'not set'));
        error_log('Driver: ' . ($cfg['driver'] ?? 'sqlite'));
        
        // 重新抛出异常，保留原始错误信息
        throw $e;
    }
}

function get_table_prefix(): string {
    $cfg = load_db_config();
    return $cfg['prefix'] ?? '';
}

function table_name(string $name): string {
    return get_table_prefix() . $name;
}

function ensure_tables(PDO $pdo, array $cfg = []) {
    // Create minimal tables with SQL that depends on driver
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $prefix = $cfg['prefix'] ?? '';
    
    if ($driver === 'mysql') {
        // 设置 InnoDB 使用 DYNAMIC 行格式以支持更长的索引
        $pdo->exec("SET SESSION innodb_strict_mode = 0;");
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}settings (
            k VARCHAR(191) PRIMARY KEY, 
            v TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}users (
            id INT PRIMARY KEY AUTO_INCREMENT, 
            username VARCHAR(191) UNIQUE, 
            password VARCHAR(255)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}backups (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL UNIQUE,
            path VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL,
            size BIGINT DEFAULT 0,
            status VARCHAR(50) DEFAULT 'active',
            notes TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}webhook_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            event_type VARCHAR(100) NOT NULL,
            remote_addr VARCHAR(100),
            user_agent TEXT,
            payload_size INT,
            signature_valid TINYINT(1) DEFAULT 0,
            status VARCHAR(50) DEFAULT 'success',
            error_message TEXT,
            created_at DATETIME NOT NULL,
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}login_attempts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(191) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            timestamp INT NOT NULL,
            INDEX idx_login_attempts (username, ip, timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    } else {
        // sqlite
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}settings (k TEXT PRIMARY KEY, v TEXT);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password TEXT);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}backups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            path TEXT NOT NULL,
            created_at TEXT NOT NULL,
            size INTEGER DEFAULT 0,
            status TEXT DEFAULT 'active',
            notes TEXT
        );");
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}webhook_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_type TEXT NOT NULL,
            remote_addr TEXT,
            user_agent TEXT,
            payload_size INTEGER,
            signature_valid INTEGER DEFAULT 0,
            status TEXT DEFAULT 'success',
            error_message TEXT,
            created_at TEXT NOT NULL
        );");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_webhook_created_at ON {$prefix}webhook_logs(created_at);");
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            ip TEXT NOT NULL,
            timestamp INTEGER NOT NULL
        );");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts ON {$prefix}login_attempts(username, ip, timestamp);");
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
    
    // 检查文件大小，超过10MB则归档
    if (file_exists($file) && filesize($file) > 10 * 1024 * 1024) {
        $archive = $d . '/update_' . date('Y-m-d_His') . '.log';
        rename($file, $archive);
        
        // 删除超过30天的旧日志
        $oldLogs = glob($d . '/update_*.log');
        if ($oldLogs !== false) {
            foreach ($oldLogs as $log) {
                if (file_exists($log) && filemtime($log) < time() - 30 * 86400) {
                    unlink($log);
                }
            }
        }
    }
    
    $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function setting_get(string $k, $default = null) {
    try {
        $pdo = get_db();
    } catch (Exception $e) {
        return $default;
    }
    $table = table_name('settings');
    $stmt = $pdo->prepare("SELECT v FROM {$table} WHERE k = :k");
    $stmt->execute([':k'=>$k]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : $v;
}

function setting_set(string $k, $v) {
    $pdo = get_db();
    $table = table_name('settings');
    $stmt = $pdo->prepare("REPLACE INTO {$table} (k,v) VALUES (:k,:v)");
    return $stmt->execute([':k'=>$k,':v'=>$v]);
}

function create_admin_if_missing() {
    $pdo = get_db();
    $table = table_name('users');
    $stmt = $pdo->query("SELECT count(*) FROM {$table}");
    if ($stmt->fetchColumn() == 0) {
        $pw = password_hash('admin', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO {$table} (username,password) VALUES (:u,:p)");
        $stmt->execute([':u'=>'admin', ':p'=>$pw]);
    }
}

function validate_user(string $user, string $pass): bool {
    $pdo = get_db();
    $table = table_name('users');
    $stmt = $pdo->prepare("SELECT password FROM {$table} WHERE username = :u");
    $stmt->execute([':u'=>$user]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $hash = $row['password'] ?? null;
    if (!$hash) return false;
    return password_verify($pass, $hash);
}

function check_login_attempts(string $username, string $ip): bool {
    $pdo = get_db();
    $now = time();
    $window = $now - 900; // 15分钟窗口
    $table = table_name('login_attempts');
    
    // 清理旧记录
    $pdo->prepare("DELETE FROM {$table} WHERE timestamp < :window")
        ->execute([':window' => $window]);
    
    // 检查失败次数
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM {$table} 
         WHERE (username = :username OR ip = :ip) AND timestamp > :window"
    );
    $stmt->execute([':username' => $username, ':ip' => $ip, ':window' => $window]);
    
    return $stmt->fetchColumn() < 5; // 15分钟内允许5次尝试
}

function log_failed_login(string $username, string $ip) {
    $pdo = get_db();
    $table = table_name('login_attempts');
    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (username, ip, timestamp) 
         VALUES (:username, :ip, :timestamp)"
    );
    $stmt->execute([
        ':username' => $username,
        ':ip' => $ip,
        ':timestamp' => time()
    ]);
}

function clear_login_attempts(string $username, string $ip) {
    $pdo = get_db();
    $table = table_name('login_attempts');
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE username = :username OR ip = :ip");
    $stmt->execute([':username' => $username, ':ip' => $ip]);
}

function check_session_timeout() {
    $timeout = 3600; // 1小时超时
    
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > $timeout) {
            session_unset();
            session_destroy();
            header('Location: admin.php?timeout=1');
            exit;
        }
    }
    
    $_SESSION['last_activity'] = time();
    
    // 验证 IP 地址（可选，防止 session 劫持）
    if (isset($_SESSION['user_ip'])) {
        if ($_SESSION['user_ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
            session_unset();
            session_destroy();
            header('Location: admin.php?security=1');
            exit;
        }
    }
}

function create_user(string $user, string $pass) {
    $pdo = get_db();
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $table = table_name('users');
    $stmt = $pdo->prepare("INSERT INTO {$table} (username,password) VALUES (:u,:p)");
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
    $table = table_name('backups');
    
    // 使用 ISO 8601 格式的时间戳
    $now = date('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (name, path, created_at, size, status, notes) 
         VALUES (:name, :path, :created_at, :size, :status, :notes)"
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
    $table = table_name('backups');
    if ($status === 'all') {
        $stmt = $pdo->query("SELECT * FROM {$table} ORDER BY created_at DESC");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE status = :status ORDER BY created_at DESC");
        $stmt->execute([':status' => $status]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_backup_by_name(string $name): ?array {
    $pdo = get_db();
    $table = table_name('backups');
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE name = :name LIMIT 1");
    $stmt->execute([':name' => $name]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function update_backup_status(string $name, string $status): bool {
    $pdo = get_db();
    $table = table_name('backups');
    $stmt = $pdo->prepare("UPDATE {$table} SET status = :status WHERE name = :name");
    return $stmt->execute([':status' => $status, ':name' => $name]);
}

function delete_backup_record(string $name): bool {
    $pdo = get_db();
    $table = table_name('backups');
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE name = :name");
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

// ==================== Webhook Logs Management Functions ====================

function log_webhook_call(string $eventType, ?string $remoteAddr, ?string $userAgent, int $payloadSize, bool $signatureValid, string $status = 'success', ?string $errorMessage = null): int {
    $pdo = get_db();
    $now = date('Y-m-d H:i:s');
    $table = table_name('webhook_logs');
    
    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (event_type, remote_addr, user_agent, payload_size, signature_valid, status, error_message, created_at) 
         VALUES (:event_type, :remote_addr, :user_agent, :payload_size, :signature_valid, :status, :error_message, :created_at)"
    );
    $stmt->execute([
        ':event_type' => $eventType,
        ':remote_addr' => $remoteAddr,
        ':user_agent' => $userAgent,
        ':payload_size' => $payloadSize,
        ':signature_valid' => $signatureValid ? 1 : 0,
        ':status' => $status,
        ':error_message' => $errorMessage,
        ':created_at' => $now
    ]);
    
    return (int)$pdo->lastInsertId();
}

function get_webhook_logs(int $limit = 50, int $offset = 0): array {
    $pdo = get_db();
    $table = table_name('webhook_logs');
    $stmt = $pdo->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_webhook_logs_count(): int {
    $pdo = get_db();
    $table = table_name('webhook_logs');
    $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
    return (int)$stmt->fetchColumn();
}

function get_last_webhook_call(): ?array {
    $pdo = get_db();
    $table = table_name('webhook_logs');
    $stmt = $pdo->query("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function clear_webhook_logs(): bool {
    $pdo = get_db();
    $table = table_name('webhook_logs');
    $stmt = $pdo->prepare("DELETE FROM {$table}");
    return $stmt->execute();
}

function clear_old_webhook_logs(int $daysToKeep = 30): int {
    $pdo = get_db();
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));
    $table = table_name('webhook_logs');
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE created_at < :cutoff");
    $stmt->execute([':cutoff' => $cutoffDate]);
    return $stmt->rowCount();
}
