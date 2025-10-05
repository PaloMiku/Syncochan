<?php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/lib/db.php';

$secret = setting_get('webhook_secret');
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null);

if ($secret && $signature) {
    if (strpos($signature, 'sha1=') === 0) {
        $sig = substr($signature, 5);
        $hash = hash_hmac('sha1', $payload, $secret);
        if (!hash_equals($hash, $sig)) {
            http_response_code(403);
            echo 'invalid signature';
            exit;
        }
    } elseif (strpos($signature, 'sha256=') === 0) {
        $sig = substr($signature, 7);
        $hash = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($hash, $sig)) {
            http_response_code(403);
            echo 'invalid signature';
            exit;
        }
    }
}

// 记录 webhook 接收
if (function_exists('log_update')) {
    $event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? 'unknown';
    log_update("webhook received: event=$event from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

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

// 方法1: 尝试后台执行（如果支持）
if ($canExecBackground) {
    if (function_exists('log_update')) {
        log_update("webhook: trying background execution");
    }
    
    // 查找正确的 PHP CLI 二进制文件
    $phpBinary = PHP_BINARY;
    
    // 如果是 php-fpm，尝试找到 CLI 版本
    if (strpos($phpBinary, 'php-fpm') !== false) {
        $possiblePaths = [
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/usr/bin/php8',
            '/usr/bin/php81',
            '/usr/bin/php82',
            dirname($phpBinary) . '/php',
            str_replace('sbin', 'bin', dirname($phpBinary)) . '/php',
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                $phpBinary = $path;
                break;
            }
        }
        
        if (strpos($phpBinary, 'php-fpm') !== false) {
            $output = [];
            @exec('which php 2>/dev/null', $output);
            if (!empty($output[0]) && file_exists($output[0])) {
                $phpBinary = $output[0];
            } else {
                $phpBinary = 'php';
            }
        }
    }
    
    $success = false;
    
    // 尝试 exec
    if (!$success && !in_array('exec', $disabledFunctions) && function_exists('exec')) {
        $cmd = escapeshellcmd($phpBinary) . ' ' . escapeshellarg(__DIR__ . '/update.php') . ' >> ' . escapeshellarg(__DIR__ . '/data/update_exec.log') . ' 2>&1 &';
        @exec($cmd, $output, $return_var);
        if ($return_var === 0 || $return_var === 127) {
            $success = true;
            $method = 'exec';
            if (function_exists('log_update')) {
                log_update("webhook: background execution started via exec");
            }
        }
    }
    
    // 尝试 popen
    if (!$success && !in_array('popen', $disabledFunctions) && function_exists('popen')) {
        $cmd = "$phpBinary " . escapeshellarg(__DIR__ . '/update.php') . ' >> ' . escapeshellarg(__DIR__ . '/data/update_exec.log') . ' 2>&1 &';
        $handle = @popen($cmd, 'r');
        if ($handle !== false) {
            @pclose($handle);
            $success = true;
            $method = 'popen';
            if (function_exists('log_update')) {
                log_update("webhook: background execution started via popen");
            }
        }
    }
    
    // 尝试 proc_open
    if (!$success && !in_array('proc_open', $disabledFunctions) && function_exists('proc_open')) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', __DIR__ . '/data/update_exec.log', 'a'],
            2 => ['file', __DIR__ . '/data/update_exec.log', 'a']
        ];
        $process = @proc_open("$phpBinary " . escapeshellarg(__DIR__ . '/update.php'), $descriptors, $pipes, __DIR__);
        if (is_resource($process)) {
            @fclose($pipes[0]);
            $success = true;
            $method = 'proc_open';
            if (function_exists('log_update')) {
                log_update("webhook: background execution started via proc_open");
            }
        }
    }
    
    // 尝试 pcntl_fork
    if (!$success && !in_array('pcntl_fork', $disabledFunctions) && function_exists('pcntl_fork')) {
        $pid = @pcntl_fork();
        if ($pid == 0) {
            // 子进程
            @ob_end_clean();
            session_write_close();
            if (function_exists('log_update')) {
                log_update("webhook: executing update in forked child process");
            }
            $result = perform_update();
            if (function_exists('log_update')) {
                log_update("webhook: update completed in child - " . json_encode($result));
            }
            exit(0);
        } elseif ($pid > 0) {
            // 父进程
            $success = true;
            $method = 'pcntl_fork';
            if (function_exists('log_update')) {
                log_update("webhook: background execution started via pcntl_fork (PID: $pid)");
            }
        }
    }
    
    if ($success) {
        http_response_code(202);
        
        // 获取服务器信息
        $serverInfo = [
            'status' => 'accepted',
            'execution_mode' => 'background',
            'method' => $method,
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => "Update started in background using $method"
        ];
        
        header('Content-Type: application/json');
        echo json_encode($serverInfo, JSON_PRETTY_PRINT);
        
        if (function_exists('log_update')) {
            log_update("webhook: responded with background mode info - method: $method");
        }
        
        exit;
    }
}

// 方法2: 快速响应 + 同步执行（适用于受限环境）
if (function_exists('log_update')) {
    log_update("webhook: background execution not available, using fast-response synchronous execution");
}

// 收集环境信息
$restrictedInfo = [
    'status' => 'accepted',
    'execution_mode' => 'synchronous',
    'reason' => 'restricted_environment',
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'sapi_name' => php_sapi_name(),
    'disabled_functions' => ini_get('disable_functions'),
    'fastcgi_support' => function_exists('fastcgi_finish_request'),
    'litespeed_support' => function_exists('litespeed_finish_request'),
    'timestamp' => date('Y-m-d H:i:s'),
    'message' => 'Update will be processed synchronously (virtual hosting mode). Connection will be closed immediately.'
];

// 快速响应技术：立即发送响应给 GitHub，然后继续执行
http_response_code(202);
header('Content-Type: application/json');

$responseBody = json_encode($restrictedInfo, JSON_PRETTY_PRINT);
$responseLength = strlen($responseBody);

header('Content-Length: ' . $responseLength);
header('Connection: close');

echo $responseBody;

// 刷新所有输出缓冲区
if (ob_get_level() > 0) {
    ob_end_flush();
}
flush();

// 关闭与客户端的连接（如果支持）
$connectionClosed = false;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
    $connectionClosed = true;
    if (function_exists('log_update')) {
        log_update("webhook: connection closed via fastcgi_finish_request");
    }
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
    $connectionClosed = true;
    if (function_exists('log_update')) {
        log_update("webhook: connection closed via litespeed_finish_request");
    }
} else {
    if (function_exists('log_update')) {
        log_update("webhook: using standard flush (fastcgi/litespeed not available)");
    }
}

// 忽略用户中断，确保更新继续执行
ignore_user_abort(true);

// 增加超时时间
@set_time_limit(300); // 5分钟
@ini_set('max_execution_time', 300);

// 现在客户端已经收到响应，我们可以继续执行更新
if (function_exists('log_update')) {
    log_update("webhook: response sent, starting synchronous update");
}

try {
    $result = perform_update();
    if (function_exists('log_update')) {
        log_update("webhook: synchronous update completed - " . json_encode($result));
    }
} catch (Exception $e) {
    if (function_exists('log_update')) {
        log_update("webhook: synchronous update failed - " . $e->getMessage());
    }
}

