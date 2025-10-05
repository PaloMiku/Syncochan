<?php
// Initialization and update logic
require_once __DIR__ . '/lib/db.php';

// if DB not configured yet, redirect to setup (but not for API calls)
$isApiCall = (strpos($_SERVER['REQUEST_URI'] ?? '', '/backend/api.php') !== false);
if (!is_file(__DIR__ . '/data/db_config.php') && php_sapi_name() !== 'cli' && !$isApiCall) {
    header('Location: backend/setup.php');
    exit;
}

function repo_zip_url($owner, $repo, $branch = 'main') {
    $mirror = setting_get('github_mirror', '');
    
    if (!empty($mirror)) {
        // 镜像源使用标准的 GitHub archive zip URL 格式
        // 例如: https://ghproxy.net/https://github.com/owner/repo/archive/refs/heads/main.zip
        $mirror = rtrim($mirror, '/');
        $githubUrl = "https://github.com/" . rawurlencode($owner) . "/" . rawurlencode($repo) . "/archive/refs/heads/" . rawurlencode($branch) . ".zip";
        return $mirror . "/" . $githubUrl;
    } else {
        // 官方 API 使用 zipball 路径
        return "https://api.github.com/repos/" . rawurlencode($owner) . "/" . rawurlencode($repo) . "/zipball/" . rawurlencode($branch);
    }
}

function extract_commit_hash_from_dirname($dirname) {
    // GitHub zip 目录名格式: owner-repo-hash (zipball) 或 repo-branch (archive)
    // 例如: PaloMiku-blog-public-a1b2c3d 或 blog-public-main
    
    // 尝试提取 hash (zipball 格式: repo-hash)
    if (preg_match('/-([a-f0-9]{7,40})$/i', $dirname, $matches)) {
        $hash = $matches[1];
        if (function_exists('log_update')) {
            log_update("[COMMIT HASH] Extracted from directory name: " . substr($hash, 0, 8));
        }
        return substr($hash, 0, 8);
    }
    
    // 如果无法提取 hash，返回 null
    return null;
}

function perform_update(array $opts = []): array {
    // opts: owner, repo, branch, token
    $owner = $opts['owner'] ?? setting_get('owner');
    $repo = $opts['repo'] ?? setting_get('repo');
    $branch = $opts['branch'] ?? setting_get('branch', 'main');
    $token = $opts['token'] ?? setting_get('token');

    $totalStartTime = microtime(true);
    if (function_exists('log_update')) {
        log_update("========================================");
        log_update("[UPDATE START] owner={$owner} repo={$repo} branch={$branch}");
        log_update("========================================");
    }

    if (!$owner || !$repo) {
        if (function_exists('log_update')) log_update("[UPDATE ERROR] owner/repo not configured");
        return ['ok'=>false,'msg'=>'owner/repo not configured'];
    }

    $url = repo_zip_url($owner, $repo, $branch);
    if (function_exists('log_update')) log_update("[UPDATE] Downloading from URL: {$url}");
    
    $tmpDir = sys_get_temp_dir() . '/ghsync_' . bin2hex(random_bytes(6));
    if (function_exists('log_update')) log_update("[UPDATE] Creating temp directory: {$tmpDir}");
    mkdir($tmpDir, 0755, true);
    $zipPath = $tmpDir . '/repo.zip';

    // lock to avoid concurrent updates
    $lockFile = __DIR__ . '/data/update.lock';
    if (function_exists('log_update')) log_update("[UPDATE] Acquiring lock: {$lockFile}");
    $lockFp = fopen($lockFile, 'c');
    if ($lockFp === false) {
        if (function_exists('log_update')) log_update("[UPDATE ERROR] Cannot open lock file");
        return ['ok'=>false,'msg'=>'cannot open lock'];
    }
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        if (function_exists('log_update')) log_update("[UPDATE ERROR] Lock already held - update in progress");
        fclose($lockFp);
        return ['ok'=>false,'msg'=>'update in progress'];
    }
    if (function_exists('log_update')) log_update("[UPDATE] Lock acquired successfully");
    // ensure we release lock at end
    $releaseLock = function() use ($lockFp, $lockFile) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        @unlink($lockFile);
    };

    // download
    $headers = [
        'User-Agent: PHP-GHSYNC/1.0'
    ];
    if ($token) $headers[] = 'Authorization: token ' . $token;

    if (function_exists('log_update')) log_update("[UPDATE] Starting curl download with token: " . ($token ? 'yes' : 'no'));
    
    // 首先检查文件大小
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_exec($ch);
    $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    
    $maxSize = 100 * 1024 * 1024; // 100MB 限制
    if ($contentLength > 0 && $contentLength > $maxSize) {
        if (function_exists('log_update')) {
            $sizeMB = round($contentLength / 1024 / 1024, 2);
            log_update("[UPDATE ERROR] Repository too large: {$sizeMB} MB (max 100 MB)");
        }
        $releaseLock();
        return ['ok'=>false,'msg'=>'repository too large (max 100MB)'];
    }
    
    if (function_exists('log_update') && $contentLength > 0) {
        $sizeMB = round($contentLength / 1024 / 1024, 2);
        log_update("[UPDATE] Expected download size: {$sizeMB} MB");
    }
    
    // 开始实际下载
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    // timeouts to avoid blocking indefinitely
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5分钟超时，适用于大文件
    
    $startTime = microtime(true);
    if (function_exists('log_update')) log_update("[UPDATE] Executing curl request...");
    $data = curl_exec($ch);
    $downloadTime = round(microtime(true) - $startTime, 2);
    
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $downloadSize = $data ? strlen($data) : 0;
    curl_close($ch);
    
    if (function_exists('log_update')) {
        $sizeStr = $downloadSize > 0 ? round($downloadSize / 1024 / 1024, 2) . ' MB' : '0 bytes';
        log_update("[UPDATE] Download completed in {$downloadTime}s - Size: {$sizeStr} - HTTP {$code}");
    }

    if ($data === false || $code >= 400) {
        if (function_exists('log_update')) log_update("[UPDATE ERROR] Download failed: HTTP {$code} - {$err}");
        $releaseLock();
        return ['ok'=>false,'msg'=>"download failed: $code $err"];
    }

    if (function_exists('log_update')) log_update("[UPDATE] Writing zip file to: {$zipPath}");
    $writeStart = microtime(true);
    file_put_contents($zipPath, $data);
    $writeTime = round(microtime(true) - $writeStart, 2);
    if (function_exists('log_update')) log_update("[UPDATE] Zip file written in {$writeTime}s");

    if (function_exists('log_update')) log_update("[UPDATE] Opening zip archive");
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        if (function_exists('log_update')) log_update("[UPDATE ERROR] Cannot open zip file");
        $releaseLock();
        return ['ok'=>false,'msg'=>'cannot open zip'];
    }
    
    $numFiles = $zip->numFiles;
    if (function_exists('log_update')) log_update("[UPDATE] Zip contains {$numFiles} files");

    $extractTo = $tmpDir . '/extracted';
    if (function_exists('log_update')) log_update("[UPDATE] Extracting to: {$extractTo}");
    mkdir($extractTo, 0755, true);
    
    $extractStart = microtime(true);
    $zip->extractTo($extractTo);
    $zip->close();
    $extractTime = round(microtime(true) - $extractStart, 2);
    if (function_exists('log_update')) log_update("[UPDATE] Extraction completed in {$extractTime}s");

    // find first directory inside extracted (repo root)
    if (function_exists('log_update')) log_update("[UPDATE] Scanning extracted directory");
    $children = array_values(array_diff(scandir($extractTo), ['.','..']));
    if (function_exists('log_update')) log_update("[UPDATE] Found " . count($children) . " items in extracted dir");
    
    if (count($children) == 0) {
        if (function_exists('log_update')) log_update("[UPDATE ERROR] Extracted directory is empty");
        $releaseLock();
        return ['ok'=>false,'msg'=>'zip empty'];
    }
    $repoRoot = $extractTo . '/' . $children[0];
    if (function_exists('log_update')) log_update("[UPDATE] Repository root: {$repoRoot}");

    // 从解压的目录名中提取 commit hash 用于备份命名
    if (function_exists('log_update')) log_update("[UPDATE] Extracting commit hash from directory name: {$children[0]}");
    $commitHash = extract_commit_hash_from_dirname($children[0]);
    
    // 检测是否使用镜像源
    $isMirrorSource = !empty(setting_get('github_mirror', ''));
    
    // 如果无法从目录名提取 hash，生成一个唯一的随机 hash
    if (!$commitHash) {
        // 使用随机字节生成 8 位十六进制哈希（确保唯一性）
        $uniqueId = bin2hex(random_bytes(4)); // 生成 8 位十六进制字符
        $commitHash = $uniqueId;
        if (function_exists('log_update')) {
            if ($isMirrorSource) {
                log_update("[UPDATE] Using mirror source - generated unique hash: {$commitHash} (mirror sources don't provide git commit hash)");
            } else {
                log_update("[UPDATE] Could not extract hash from dirname, generated unique ID: {$commitHash}");
            }
        }
    } else {
        if (function_exists('log_update')) log_update("[UPDATE] Commit hash: {$commitHash}");
    }

    $contentDir = __DIR__ . '/../content';
    $backupsDir = get_backups_dir();
    $backupName = 'backup_' . $commitHash;
    $backup = $backupsDir . '/' . $backupName;
    
    // 准备备份备注信息
    $backupNotes = "Auto backup before update";
    if ($isMirrorSource) {
        $backupNotes .= " (via mirror source)";
    }
    
    if (function_exists('log_update')) log_update("[UPDATE] Content dir: {$contentDir}, Backup: {$backup}");
    
    // rename old to backup, then move new to content
    $backupCreated = false;
    if (is_dir($contentDir)) {
        if (function_exists('log_update')) log_update("[UPDATE] Backing up existing content directory");
        $backupStart = microtime(true);
        
        if (!@rename($contentDir, $backup)) {
            if (function_exists('log_update')) log_update("[UPDATE] Rename failed, using recursive copy for backup");
            // try recursive move via copy
            $tmpOld = $tmpDir . '/old_backup';
            recurse_copy($contentDir, $tmpOld);
            rrmdir($contentDir);
            if (!@rename($tmpOld, $backup)) {
                recurse_copy($tmpOld, $backup);
                rrmdir($tmpOld);
            }
        }
        
        $backupTime = round(microtime(true) - $backupStart, 2);
        if (function_exists('log_update')) log_update("[UPDATE] Backup completed in {$backupTime}s");
        
        // Calculate backup size and create database record
        if (is_dir($backup)) {
            $backupCreated = true;
            $backupSize = calculate_dir_size($backup);
            $backupId = create_backup_record($backupName, $backup, $backupSize, $backupNotes);
            if (function_exists('log_update')) log_update("[UPDATE] Backup record created in database: ID={$backupId}, Size=" . round($backupSize / 1024 / 1024, 2) . "MB, Notes={$backupNotes}");
        }
    } else {
        if (function_exists('log_update')) log_update("[UPDATE] No existing content directory to backup");
    }
    // move extracted content (repoRoot) to content
    if (function_exists('log_update')) log_update("[UPDATE] Deploying new content from {$repoRoot} to {$contentDir}");
    $deployStart = microtime(true);
    $moved = false;
    
    if (@rename($repoRoot, $contentDir)) {
        $moved = true;
        if (function_exists('log_update')) log_update("[UPDATE] Content deployed via rename");
    } else {
        if (function_exists('log_update')) log_update("[UPDATE] Rename failed, using recursive copy for deployment");
        // try recursive copy
        mkdir($contentDir, 0755, true);
        recurse_copy($repoRoot, $contentDir);
        $moved = is_dir($contentDir);
        if (function_exists('log_update')) log_update("[UPDATE] Recursive copy completed, directory exists: " . ($moved ? 'yes' : 'no'));
    }
    
    $deployTime = round(microtime(true) - $deployStart, 2);
    if (function_exists('log_update')) log_update("[UPDATE] Deployment completed in {$deployTime}s");

    if (!$moved) {
        if (function_exists('log_update')) log_update("[UPDATE ERROR] Failed to deploy content, rolling back");
        // rollback: restore backup if exists
        if (is_dir($backup)) {
            if (is_dir($contentDir)) rrmdir($contentDir);
            rename($backup, $contentDir);
            // Delete the failed backup record
            if ($backupCreated) {
                delete_backup_record($backupName);
            }
            if (function_exists('log_update')) log_update("[UPDATE] Rollback completed");
        }
        $releaseLock();
        rrmdir($tmpDir);
        return ['ok'=>false,'msg'=>'failed to deploy content'];
    }

    // cleanup temp dir
    if (function_exists('log_update')) log_update("[UPDATE] Cleaning up temporary directory: {$tmpDir}");
    $cleanupStart = microtime(true);
    rrmdir($tmpDir);
    $cleanupTime = round(microtime(true) - $cleanupStart, 2);
    if (function_exists('log_update')) log_update("[UPDATE] Cleanup completed in {$cleanupTime}s");

    // Cleanup old backups (keep 5 most recent)
    if (function_exists('log_update')) log_update("[UPDATE] Cleaning up old backups");
    $allBackups = get_backups('active');
    if (count($allBackups) > 5) {
        if (function_exists('log_update')) log_update("[UPDATE] Found " . count($allBackups) . " backups, keeping 5 most recent");
        // Sort by created_at descending (most recent first)
        usort($allBackups, function($a, $b) {
            return strtotime($b['created_at']) <=> strtotime($a['created_at']);
        });
        
        // Remove backups beyond the 5 most recent
        $toRemove = array_slice($allBackups, 5);
        foreach ($toRemove as $oldBackup) {
            $oldPath = $oldBackup['path'];
            if (is_dir($oldPath)) {
                if (function_exists('log_update')) log_update("[UPDATE] Removing old backup: {$oldBackup['name']}");
                rrmdir($oldPath);
            }
            delete_backup_record($oldBackup['name']);
        }
    }
    
    // Also cleanup any orphaned content_backup_* directories in root (from old system)
    $bdir = __DIR__ . '/..';
    $oldBackups = glob($bdir . '/content_backup_*');
    if ($oldBackups !== false && count($oldBackups) > 0) {
        if (function_exists('log_update')) log_update("[UPDATE] Found " . count($oldBackups) . " old-style backups in root, removing...");
        foreach ($oldBackups as $old) {
            if (function_exists('log_update')) log_update("[UPDATE] Removing old backup: " . basename($old));
            rrmdir($old);
        }
    }

    $releaseLock();
    $totalTime = round(microtime(true) - $totalStartTime, 2);
    if (function_exists('log_update')) {
        log_update("[UPDATE SUCCESS] Update completed in {$totalTime}s: owner={$owner} repo={$repo} branch={$branch}");
        log_update("========================================");
    }
    return ['ok'=>true,'msg'=>'updated','backup'=>$backup ?? null];
}

function recurse_copy($src, $dst) {
    if (function_exists('log_update')) log_update("[COPY] Starting recursive copy from {$src} to {$dst}");
    $fileCount = 0;
    $dir = opendir($src);
    @mkdir($dst);
    while(false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                recurse_copy($src . '/' . $file, $dst . '/' . $file);
            }
            else {
                copy($src . '/' . $file, $dst . '/' . $file);
                $fileCount++;
                if ($fileCount % 100 == 0 && function_exists('log_update')) {
                    log_update("[COPY] Copied {$fileCount} files so far...");
                }
            }
        }
    }
    closedir($dir);
    if (function_exists('log_update')) log_update("[COPY] Completed copying {$fileCount} files from " . basename($src));
}

function rrmdir($dir) {
    if (!is_dir($dir)) return;
    if (function_exists('log_update')) log_update("[DELETE] Removing directory: {$dir}");
    $fileCount = 0;
    $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) {
        $path = "$dir/$file";
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            unlink($path);
            $fileCount++;
            if ($fileCount % 100 == 0 && function_exists('log_update')) {
                log_update("[DELETE] Deleted {$fileCount} files so far...");
            }
        }
    }
    rmdir($dir);
    if (function_exists('log_update')) log_update("[DELETE] Completed removing directory: " . basename($dir));
}
