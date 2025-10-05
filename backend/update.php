<?php
// 记录脚本开始执行
$execLogFile = __DIR__ . '/data/update_exec.log';
file_put_contents($execLogFile, "Script started at " . date('Y-m-d H:i:s') . " (PID: " . getmypid() . ")\n", FILE_APPEND);
file_put_contents($execLogFile, "PHP Version: " . PHP_VERSION . "\n", FILE_APPEND);
file_put_contents($execLogFile, "Script: " . __FILE__ . "\n", FILE_APPEND);
file_put_contents($execLogFile, "Working Directory: " . getcwd() . "\n", FILE_APPEND);

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/lib/db.php';

file_put_contents($execLogFile, "Required files loaded successfully\n", FILE_APPEND);

if (php_sapi_name() !== 'cli') {
    file_put_contents($execLogFile, "Running in non-CLI mode: " . php_sapi_name() . "\n", FILE_APPEND);
    if (!empty($_SERVER['PHP_AUTH_USER'])) {
        if (!validate_user($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            file_put_contents($execLogFile, "Authentication failed\n", FILE_APPEND);
            header('WWW-Authenticate: Basic realm="Update"');
            http_response_code(401);
            echo json_encode(['ok'=>false,'msg'=>'auth required']);
            exit;
        }
    }
} else {
    file_put_contents($execLogFile, "Running in CLI mode\n", FILE_APPEND);
}

$owner = $argv[1] ?? null;
$repo = $argv[2] ?? null;
$branch = $argv[3] ?? null;

file_put_contents($execLogFile, "Args - owner: $owner, repo: $repo, branch: $branch\n", FILE_APPEND);

$opts = [];
if ($owner) $opts['owner']=$owner;
if ($repo) $opts['repo']=$repo;
if ($branch) $opts['branch']=$branch;

file_put_contents($execLogFile, "Starting perform_update()...\n", FILE_APPEND);

try {
    $res = perform_update($opts);
    file_put_contents($execLogFile, "Update completed: " . json_encode($res) . "\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents($execLogFile, "Update failed with exception: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents($execLogFile, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    $res = ['ok' => false, 'msg' => $e->getMessage()];
}

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
}
echo json_encode($res);

file_put_contents($execLogFile, "Script finished at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
file_put_contents($execLogFile, "===================\n\n", FILE_APPEND);
