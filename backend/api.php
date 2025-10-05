<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/init.php';
// ensure sessions are stored in project data dir
ini_set('session.save_path', __DIR__ . '/data/sessions');
if (!is_dir(__DIR__ . '/data/sessions')) mkdir(__DIR__ . '/data/sessions', 0755, true);
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'auth required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? [];
$action = $input['action'] ?? '';
$csrf = $input['csrf'] ?? '';
if (empty($csrf) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>'invalid csrf']);
    exit;
}

switch($action) {
    case 'list_backups':
        $backups = get_backups('active');
        $out = [];
        foreach ($backups as $backup) {
            $out[] = [
                'name' => $backup['name'],
                'mtime' => strtotime($backup['created_at']),
                'size_human' => human_size($backup['size']),
                'notes' => $backup['notes'] ?? ''
            ];
        }
        echo json_encode(['ok'=>true,'backups'=>$out]);
        break;
    case 'restore':
        $b = basename($input['backup'] ?? '');
        if (!$b) {
            echo json_encode(['ok'=>false,'msg'=>'backup name required']);
            break;
        }
        
        $backup = get_backup_by_name($b);
        if (!$backup || !is_dir($backup['path'])) {
            echo json_encode(['ok'=>false,'msg'=>'backup not found or path invalid']);
            break;
        }
        
        $path = $backup['path'];
        $cur = __DIR__ . '/../content';
        $tmpcur = __DIR__ . '/../content_tmp_' . time();
        
        if (is_dir($cur)) rename($cur, $tmpcur);
        if (!rename($path, $cur)) {
            recurse_copy($path, $cur);
            rrmdir($path);
        }
        
        // 更新备份状态为 restored
        update_backup_status($b, 'restored');
        
        if (function_exists('log_update')) log_update("ajax restore: $b by {$_SESSION['user']}");
        echo json_encode(['ok'=>true,'msg'=>'restored']);
        break;
    case 'delete_backup':
        $b = basename($input['backup'] ?? '');
        if (!$b) {
            echo json_encode(['ok'=>false,'msg'=>'backup name required']);
            break;
        }
        
        $backup = get_backup_by_name($b);
        if (!$backup) {
            echo json_encode(['ok'=>false,'msg'=>'backup not found in database']);
            break;
        }
        
        // 删除物理文件（如果存在）
        $path = $backup['path'];
        if (is_dir($path)) {
            rrmdir($path);
            if (function_exists('log_update')) log_update("ajax delete backup: $b (file deleted) by {$_SESSION['user']}");
        } else {
            if (function_exists('log_update')) log_update("ajax delete backup: $b (file not found, removing record only) by {$_SESSION['user']}");
        }
        
        // 无论物理文件是否存在，都删除数据库记录
        delete_backup_record($b);
        
        echo json_encode(['ok'=>true,'msg'=>'deleted']);
        break;
    case 'update':
        // 支持同步和异步更新
        $sync = !empty($input['sync']);
        $forceSync = !empty($input['forceSync']); // 强制同步（用于受限环境）
        
        if (function_exists('log_update')) log_update("ajax update requested by {$_SESSION['user']} (sync=" . ($sync ? 'true' : 'false') . ", forceSync=" . ($forceSync ? 'true' : 'false') . ")");
        
        if ($sync || $forceSync) {
            // 同步执行更新
            if (function_exists('log_update')) log_update("Running update synchronously...");
            
            // 增加超时时间，防止长时间运行的更新被中断
            @set_time_limit(300); // 5分钟
            @ini_set('max_execution_time', 300);
            
            $result = perform_update();
            echo json_encode($result);
        } else {
            // 后台异步执行更新
            $execLogFile = __DIR__ . '/data/update_exec.log';
            
            // 查找正确的 PHP CLI 二进制文件
            $phpBinary = PHP_BINARY;
            
            // 如果 PHP_BINARY 是 php-fpm，尝试找到正确的 CLI 版本
            if (strpos($phpBinary, 'php-fpm') !== false) {
                if (function_exists('log_update')) {
                    log_update("Detected PHP-FPM ($phpBinary), searching for PHP CLI...");
                }
                
                // 尝试常见的 PHP CLI 路径
                $possiblePaths = [
                    '/usr/bin/php',
                    '/usr/local/bin/php',
                    '/usr/bin/php8',
                    '/usr/bin/php81',
                    '/usr/bin/php82',
                    '/usr/local/bin/php8',
                    '/usr/local/bin/php81',
                    '/usr/local/bin/php82',
                    dirname($phpBinary) . '/php',
                    str_replace('sbin', 'bin', dirname($phpBinary)) . '/php',
                ];
                
                // 尝试从 php-fpm 路径推断 CLI 版本
                if (preg_match('/php-fpm(\d+\.\d+)?/', $phpBinary, $matches)) {
                    $version = $matches[1] ?? '';
                    if ($version) {
                        $possiblePaths[] = '/usr/bin/php' . str_replace('.', '', $version);
                        $possiblePaths[] = '/usr/local/bin/php' . str_replace('.', '', $version);
                    }
                }
                
                foreach ($possiblePaths as $path) {
                    if (file_exists($path) && is_executable($path)) {
                        $phpBinary = $path;
                        if (function_exists('log_update')) {
                            log_update("Found PHP CLI: $phpBinary");
                        }
                        break;
                    }
                }
                
                // 如果还是 php-fpm，尝试使用 which 命令
                if (strpos($phpBinary, 'php-fpm') !== false && function_exists('exec')) {
                    $output = [];
                    @exec('which php 2>/dev/null', $output);
                    if (!empty($output[0]) && file_exists($output[0])) {
                        $phpBinary = $output[0];
                        if (function_exists('log_update')) {
                            log_update("Found PHP CLI via 'which': $phpBinary");
                        }
                    }
                }
                
                // 最后的尝试：使用 command -v
                if (strpos($phpBinary, 'php-fpm') !== false && function_exists('exec')) {
                    $output = [];
                    @exec('command -v php 2>/dev/null', $output);
                    if (!empty($output[0]) && file_exists($output[0])) {
                        $phpBinary = $output[0];
                        if (function_exists('log_update')) {
                            log_update("Found PHP CLI via 'command -v': $phpBinary");
                        }
                    }
                }
                
                // 如果仍然是 php-fpm，回退到简单的 'php' 命令
                if (strpos($phpBinary, 'php-fpm') !== false) {
                    $phpBinary = 'php';
                    if (function_exists('log_update')) {
                        log_update("Falling back to 'php' command");
                    }
                }
            }
            
            if (function_exists('log_update')) {
                log_update("Using PHP binary: $phpBinary");
            }
            
            $updateScript = __DIR__ . '/update.php';
            
            // 确保日志目录存在
            if (!is_dir(__DIR__ . '/data')) {
                mkdir(__DIR__ . '/data', 0755, true);
            }
            
            // 写入执行时间戳到日志
            file_put_contents($execLogFile, "\n=== Update triggered at " . date('Y-m-d H:i:s') . " by {$_SESSION['user']} ===\n", FILE_APPEND);
            file_put_contents($execLogFile, "PHP Binary: $phpBinary\n", FILE_APPEND);
            
            // 检查是否有可用的后台执行方法
            $canExecBackground = false;
            $disabledFunctions = explode(',', ini_get('disable_functions'));
            $disabledFunctions = array_map('trim', $disabledFunctions);
            
            if (!in_array('exec', $disabledFunctions) && function_exists('exec')) {
                $canExecBackground = true;
            } elseif (!in_array('popen', $disabledFunctions) && function_exists('popen')) {
                $canExecBackground = true;
            } elseif (!in_array('proc_open', $disabledFunctions) && function_exists('proc_open')) {
                $canExecBackground = true;
            } elseif (!in_array('pcntl_fork', $disabledFunctions) && function_exists('pcntl_fork')) {
                $canExecBackground = true;
            }
            
            if (!$canExecBackground) {
                if (function_exists('log_update')) {
                    log_update("WARNING: All background execution methods are disabled. Disabled functions: " . ini_get('disable_functions'));
                }
                file_put_contents($execLogFile, "ERROR: Cannot execute in background - all methods disabled\n", FILE_APPEND);
                file_put_contents($execLogFile, "Disabled functions: " . ini_get('disable_functions') . "\n", FILE_APPEND);
                
                echo json_encode([
                    'ok' => false,
                    'msg' => 'restricted_environment',
                    'error' => 'Background execution is disabled in this hosting environment',
                    'suggestion' => 'Use synchronous update instead',
                    'disabledFunctions' => ini_get('disable_functions')
                ]);
                break;
            }
            
            // 尝试多种后台执行方法
            $success = false;
            $method = '';
            $error = '';
            
            // 方法1: 标准 exec 后台执行
            if (!in_array('exec', $disabledFunctions) && function_exists('exec')) {
                $cmd = escapeshellcmd($phpBinary) . ' ' . escapeshellarg($updateScript) . ' >> ' . escapeshellarg($execLogFile) . ' 2>&1 &';
                if (function_exists('log_update')) {
                    log_update("Method 1: Trying exec with command: $cmd");
                }
                file_put_contents($execLogFile, "Executing command: $cmd\n", FILE_APPEND);
                
                $output = [];
                $return_var = 0;
                @exec($cmd, $output, $return_var);
                
                if ($return_var === 0 || $return_var === 127) {
                    $success = true;
                    $method = 'exec';
                    if (function_exists('log_update')) {
                        log_update("Method 1 executed (return code: $return_var)");
                    }
                }
            }
            
            // 方法2: 使用 popen
            if (!$success && !in_array('popen', $disabledFunctions) && function_exists('popen')) {
                if (function_exists('log_update')) {
                    log_update("Method 2: Trying popen");
                }
                $cmd = "$phpBinary " . escapeshellarg($updateScript) . ' >> ' . escapeshellarg($execLogFile) . ' 2>&1 &';
                file_put_contents($execLogFile, "Executing via popen: $cmd\n", FILE_APPEND);
                $handle = @popen($cmd, 'r');
                if ($handle !== false) {
                    @pclose($handle);
                    $success = true;
                    $method = 'popen';
                    if (function_exists('log_update')) {
                        log_update("Method 2: popen succeeded");
                    }
                }
            }
            
            // 方法3: 使用 proc_open
            if (!$success && !in_array('proc_open', $disabledFunctions) && function_exists('proc_open')) {
                if (function_exists('log_update')) {
                    log_update("Method 3: Trying proc_open");
                }
                $descriptors = [
                    0 => ['pipe', 'r'],
                    1 => ['file', $execLogFile, 'a'],
                    2 => ['file', $execLogFile, 'a']
                ];
                file_put_contents($execLogFile, "Executing via proc_open: $phpBinary " . $updateScript . "\n", FILE_APPEND);
                $process = @proc_open("$phpBinary " . escapeshellarg($updateScript), $descriptors, $pipes, __DIR__);
                if (is_resource($process)) {
                    @fclose($pipes[0]);
                    // 不等待进程结束，让它在后台运行
                    $success = true;
                    $method = 'proc_open';
                    if (function_exists('log_update')) {
                        log_update("Method 3: proc_open succeeded");
                    }
                }
            }
            
            // 方法4: 直接在当前进程中异步执行（使用 pcntl_fork，如果可用）
            if (!$success && !in_array('pcntl_fork', $disabledFunctions) && function_exists('pcntl_fork')) {
                if (function_exists('log_update')) {
                    log_update("Method 4: Trying pcntl_fork");
                }
                $pid = @pcntl_fork();
                if ($pid == -1) {
                    $error = 'Could not fork process';
                } elseif ($pid) {
                    // 父进程
                    $success = true;
                    $method = 'pcntl_fork';
                    if (function_exists('log_update')) {
                        log_update("Method 4: pcntl_fork succeeded (child PID: $pid)");
                    }
                } else {
                    // 子进程
                    @ob_end_clean();
                    session_write_close();
                    file_put_contents($execLogFile, "Running via pcntl_fork in child process (PID: " . getmypid() . ")\n", FILE_APPEND);
                    $result = perform_update();
                    file_put_contents($execLogFile, "Update result: " . json_encode($result) . "\n", FILE_APPEND);
                    exit(0);
                }
            }
            
            // 如果所有方法都失败，记录错误
            if (!$success) {
                $error = 'All background execution methods failed. This may be a restricted hosting environment.';
                if (function_exists('log_update')) {
                    log_update("ERROR: $error");
                }
                file_put_contents($execLogFile, "ERROR: $error\n", FILE_APPEND);
                echo json_encode([
                    'ok' => false,
                    'msg' => 'execution_failed',
                    'error' => $error,
                    'suggestion' => 'Use synchronous update instead',
                    'disabledFunctions' => ini_get('disable_functions')
                ]);
                break;
            }
            
            if (function_exists('log_update')) {
                log_update("Background update started successfully using method: $method");
            }
            
            echo json_encode(['ok'=>true,'msg'=>'update started','method'=>$method,'phpBinary'=>$phpBinary]);
        }
        break;
        // 支持同步和异步更新
        $sync = !empty($input['sync']);
        if (function_exists('log_update')) log_update("ajax update requested by {$_SESSION['user']} (sync=" . ($sync ? 'true' : 'false') . ")");
        
        if ($sync) {
            // 同步执行更新
            $result = perform_update();
            echo json_encode($result);
        } else {
            // 后台异步执行更新
            $execLogFile = __DIR__ . '/data/update_exec.log';
            
            // 查找正确的 PHP CLI 二进制文件
            $phpBinary = PHP_BINARY;
            
            // 如果 PHP_BINARY 是 php-fpm，尝试找到正确的 CLI 版本
            if (strpos($phpBinary, 'php-fpm') !== false) {
                if (function_exists('log_update')) {
                    log_update("Detected PHP-FPM ($phpBinary), searching for PHP CLI...");
                }
                
                // 尝试常见的 PHP CLI 路径
                $possiblePaths = [
                    '/usr/bin/php',
                    '/usr/local/bin/php',
                    '/usr/bin/php8',
                    '/usr/bin/php81',
                    '/usr/bin/php82',
                    '/usr/local/bin/php8',
                    '/usr/local/bin/php81',
                    '/usr/local/bin/php82',
                    dirname($phpBinary) . '/php',
                    str_replace('sbin', 'bin', dirname($phpBinary)) . '/php',
                ];
                
                // 尝试从 php-fpm 路径推断 CLI 版本
                if (preg_match('/php-fpm(\d+\.\d+)?/', $phpBinary, $matches)) {
                    $version = $matches[1] ?? '';
                    if ($version) {
                        $possiblePaths[] = '/usr/bin/php' . str_replace('.', '', $version);
                        $possiblePaths[] = '/usr/local/bin/php' . str_replace('.', '', $version);
                    }
                }
                
                foreach ($possiblePaths as $path) {
                    if (file_exists($path) && is_executable($path)) {
                        $phpBinary = $path;
                        if (function_exists('log_update')) {
                            log_update("Found PHP CLI: $phpBinary");
                        }
                        break;
                    }
                }
                
                // 如果还是 php-fpm，尝试使用 which 命令
                if (strpos($phpBinary, 'php-fpm') !== false && function_exists('exec')) {
                    $output = [];
                    @exec('which php 2>/dev/null', $output);
                    if (!empty($output[0]) && file_exists($output[0])) {
                        $phpBinary = $output[0];
                        if (function_exists('log_update')) {
                            log_update("Found PHP CLI via 'which': $phpBinary");
                        }
                    }
                }
                
                // 最后的尝试：使用 command -v
                if (strpos($phpBinary, 'php-fpm') !== false && function_exists('exec')) {
                    $output = [];
                    @exec('command -v php 2>/dev/null', $output);
                    if (!empty($output[0]) && file_exists($output[0])) {
                        $phpBinary = $output[0];
                        if (function_exists('log_update')) {
                            log_update("Found PHP CLI via 'command -v': $phpBinary");
                        }
                    }
                }
                
                // 如果仍然是 php-fpm，回退到简单的 'php' 命令
                if (strpos($phpBinary, 'php-fpm') !== false) {
                    $phpBinary = 'php';
                    if (function_exists('log_update')) {
                        log_update("Falling back to 'php' command");
                    }
                }
            }
            
            if (function_exists('log_update')) {
                log_update("Using PHP binary: $phpBinary");
            }
            
            $updateScript = __DIR__ . '/update.php';
            
            // 确保日志目录存在
            if (!is_dir(__DIR__ . '/data')) {
                mkdir(__DIR__ . '/data', 0755, true);
            }
            
            // 写入执行时间戳到日志
            file_put_contents($execLogFile, "\n=== Update triggered at " . date('Y-m-d H:i:s') . " by {$_SESSION['user']} ===\n", FILE_APPEND);
            file_put_contents($execLogFile, "PHP Binary: $phpBinary\n", FILE_APPEND);
            
            // 尝试多种后台执行方法
            $success = false;
            $method = '';
            $error = '';
            
            // 方法1: 标准 exec 后台执行
            if (function_exists('exec')) {
                $cmd = escapeshellcmd($phpBinary) . ' ' . escapeshellarg($updateScript) . ' >> ' . escapeshellarg($execLogFile) . ' 2>&1 &';
                if (function_exists('log_update')) {
                    log_update("Method 1: Trying exec with command: $cmd");
                }
                file_put_contents($execLogFile, "Executing command: $cmd\n", FILE_APPEND);
                
                $output = [];
                $return_var = 0;
                @exec($cmd, $output, $return_var);
                
                if ($return_var === 0 || $return_var === 127) {
                    $success = true;
                    $method = 'exec';
                    if (function_exists('log_update')) {
                        log_update("Method 1 executed (return code: $return_var)");
                    }
                }
            }
            
            // 方法2: 使用 popen
            if (!$success && function_exists('popen')) {
                if (function_exists('log_update')) {
                    log_update("Method 2: Trying popen");
                }
                $cmd = "$phpBinary " . escapeshellarg($updateScript) . ' >> ' . escapeshellarg($execLogFile) . ' 2>&1 &';
                file_put_contents($execLogFile, "Executing via popen: $cmd\n", FILE_APPEND);
                $handle = @popen($cmd, 'r');
                if ($handle !== false) {
                    @pclose($handle);
                    $success = true;
                    $method = 'popen';
                    if (function_exists('log_update')) {
                        log_update("Method 2: popen succeeded");
                    }
                }
            }
            
            // 方法3: 使用 proc_open
            if (!$success && function_exists('proc_open')) {
                if (function_exists('log_update')) {
                    log_update("Method 3: Trying proc_open");
                }
                $descriptors = [
                    0 => ['pipe', 'r'],
                    1 => ['file', $execLogFile, 'a'],
                    2 => ['file', $execLogFile, 'a']
                ];
                file_put_contents($execLogFile, "Executing via proc_open: $phpBinary " . $updateScript . "\n", FILE_APPEND);
                $process = @proc_open("$phpBinary " . escapeshellarg($updateScript), $descriptors, $pipes, __DIR__);
                if (is_resource($process)) {
                    @fclose($pipes[0]);
                    // 不等待进程结束，让它在后台运行
                    $success = true;
                    $method = 'proc_open';
                    if (function_exists('log_update')) {
                        log_update("Method 3: proc_open succeeded");
                    }
                }
            }
            
            // 方法4: 直接在当前进程中异步执行（使用 pcntl_fork，如果可用）
            if (!$success && function_exists('pcntl_fork')) {
                if (function_exists('log_update')) {
                    log_update("Method 4: Trying pcntl_fork");
                }
                $pid = @pcntl_fork();
                if ($pid == -1) {
                    $error = 'Could not fork process';
                } elseif ($pid) {
                    // 父进程
                    $success = true;
                    $method = 'pcntl_fork';
                    if (function_exists('log_update')) {
                        log_update("Method 4: pcntl_fork succeeded (child PID: $pid)");
                    }
                } else {
                    // 子进程
                    @ob_end_clean();
                    session_write_close();
                    file_put_contents($execLogFile, "Running via pcntl_fork in child process (PID: " . getmypid() . ")\n", FILE_APPEND);
                    $result = perform_update();
                    file_put_contents($execLogFile, "Update result: " . json_encode($result) . "\n", FILE_APPEND);
                    exit(0);
                }
            }
            
            // 如果所有方法都失败，记录错误
            if (!$success) {
                $error = 'All background execution methods failed. exec, popen, proc_open, and pcntl_fork are not available or failed.';
                if (function_exists('log_update')) {
                    log_update("ERROR: $error");
                }
                file_put_contents($execLogFile, "ERROR: $error\n", FILE_APPEND);
                echo json_encode(['ok'=>false,'msg'=>$error,'suggestion'=>'Please enable exec, popen, or proc_open in PHP, or run update synchronously']);
                break;
            }
            
            if (function_exists('log_update')) {
                log_update("Background update started successfully using method: $method");
            }
            
            echo json_encode(['ok'=>true,'msg'=>'update started','method'=>$method,'phpBinary'=>$phpBinary]);
        }
        break;
    case 'set_backups':
        $val = $input['val'] ?? '1';
        setting_set('enable_backups', $val === '0' ? '0' : '1');
        echo json_encode(['ok'=>true,'val'=>setting_get('enable_backups')]);
        break;
    case 'get_log':
        $logFile = __DIR__ . '/data/update.log';
        if (file_exists($logFile)) {
            $lines = file($logFile);
            $lastLines = array_slice($lines, -300); // 获取最后300行
            echo json_encode(['ok'=>true,'log'=>implode('', $lastLines)]);
        } else {
            echo json_encode(['ok'=>true,'log'=>'no log yet']);
        }
        break;
    case 'get_exec_log':
        $execLogFile = __DIR__ . '/data/update_exec.log';
        if (file_exists($execLogFile)) {
            $content = file_get_contents($execLogFile);
            echo json_encode(['ok'=>true,'log'=>$content]);
        } else {
            echo json_encode(['ok'=>true,'log'=>'no exec log yet']);
        }
        break;
    default:
        echo json_encode(['ok'=>false,'msg'=>'unknown action']);
}
