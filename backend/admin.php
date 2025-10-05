<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/init.php';

// ensure sessions are stored in project data dir
ini_set('session.save_path', __DIR__ . '/data/sessions');
if (!is_dir(__DIR__ . '/data/sessions')) mkdir(__DIR__ . '/data/sessions', 0755, true);
session_start();
create_admin_if_missing();

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// simple auth
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // 检查登录尝试限制
    if (!check_login_attempts($u, $ip)) {
        $err = 'too_many_attempts';
    } elseif (validate_user($u,$p)) {
        // 登录成功 - 清除失败记录并重新生成 Session ID
        clear_login_attempts($u, $ip);
        session_regenerate_id(true);
        $_SESSION['user'] = $u;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['user_ip'] = $ip;
        header('Location: admin.php'); exit;
    } else {
        // 登录失败 - 记录失败尝试
        log_failed_login($u, $ip);
        $err = 'invalid';
    }
}

if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

// require login for actions
if (!empty($_SESSION['user'])) {
    // 检查 Session 超时
    check_session_timeout();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // verify csrf
    if (empty($_POST['csrf']) || !hash_equals($csrf, $_POST['csrf'])) {
      // log minimal info for debugging (do not log tokens)
      if (function_exists('log_update')) {
        $sid = session_id();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        log_update("invalid csrf: action={" . ($_POST['action'] ?? '') . "} sid={$sid} ip={$ip}");
      }
      $msg = 'invalid csrf — 请尝试刷新页面并确保浏览器接受 cookie，然后重新提交。如仍然出现，请检查 PHP 会话配置 (session.save_path) 与服务器时间。';
    } else {
            // save settings
            if (isset($_POST['action']) && $_POST['action'] === 'save') {
                setting_set('owner', $_POST['owner'] ?? '');
                setting_set('repo', $_POST['repo'] ?? '');
                setting_set('branch', $_POST['branch'] ?? 'main');
                setting_set('token', $_POST['token'] ?? '');
                setting_set('github_mirror', $_POST['github_mirror'] ?? '');
                $msg = 'saved';
            }
            // save webhook settings
            if (isset($_POST['action']) && $_POST['action'] === 'save_webhook') {
                setting_set('webhook_secret', $_POST['webhook_secret'] ?? '');
                $msg = 'webhook settings saved';
            }
            // trigger update
            if (isset($_POST['action']) && $_POST['action'] === 'update') {
                $res = perform_update();
                $msg = json_encode($res);
            }
            // restore backup
            if (isset($_POST['action']) && $_POST['action'] === 'restore') {
                $b = basename($_POST['backup'] ?? '');
                $path = __DIR__ . '/../' . $b;
                if ($b && is_dir($path)) {
                    $cur = __DIR__ . '/../content';
                    $tmpcur = __DIR__ . '/../content_tmp_' . time();
                    if (is_dir($cur)) rename($cur, $tmpcur);
                    if (!rename($path, $cur)) {
                        recurse_copy($path, $cur);
                        rrmdir($path);
                    }
                    // Clean up temporary directory after successful restore
                    if (is_dir($tmpcur)) {
                        rrmdir($tmpcur);
                        if (function_exists('log_update')) log_update("restore: cleaned up temporary directory {$tmpcur}");
                    }
                    $msg = 'restored ' . htmlspecialchars($b);
                    if (function_exists('log_update')) log_update("restore: $b by {$_SESSION['user']}");
                } else $msg = 'backup not found';
            }
            // delete backup
            if (isset($_POST['action']) && $_POST['action'] === 'delete_backup') {
                $b = basename($_POST['backup'] ?? '');
                $path = __DIR__ . '/../' . $b;
                if ($b) {
                    if (is_dir($path)) {
                        rrmdir($path);
                        if (function_exists('log_update')) log_update("delete backup: $b (file deleted) by {$_SESSION['user']}");
                    } else {
                        if (function_exists('log_update')) log_update("delete backup: $b (file not found, would remove record if using database) by {$_SESSION['user']}");
                    }
                    $msg = 'deleted ' . htmlspecialchars($b);
                } else {
                    $msg = 'backup name required';
                }
            }
        }
  }

  // Post-Redirect-Get: if this was a non-AJAX form POST, redirect to avoid browser resubmit prompt
  $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
  $isAjaxJson = stripos($contentType, 'application/json') !== false || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAjaxJson) {
    if (function_exists('log_update')) log_update('prg redirect after non-AJAX POST by ' . ($_SESSION['user'] ?? 'unknown'));
    header('Location: admin.php');
    exit;
  }

  $owner = setting_get('owner','');
  $repo = setting_get('repo','');
  $branch = setting_get('branch','main');
  $token = setting_get('token','');
  $webhook = setting_get('webhook_secret','');
  $github_mirror = setting_get('github_mirror','');
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>SyncoChan - 后台管理</title>
  <link href="https://s4.zstatic.net/ajax/libs/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet">
  <link href="./assets/admin.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php if (empty($_SESSION['user'])): ?>
  <!-- 登录页面 -->
  <div class="login-container">
    <div class="login-card">
      <div class="login-form-section">
        <img src="./assets/images/logo.png" alt="SyncoChan Logo" class="login-logo">
        <h4 class="login-title">欢迎回来</h4>
        <?php if (!empty($err)): ?>
          <?php if ($err === 'too_many_attempts'): ?>
            <div class="alert alert-danger">登录尝试次数过多，请15分钟后再试</div>
          <?php elseif ($err === 'invalid'): ?>
            <div class="alert alert-danger">用户名或密码错误</div>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($_GET['timeout'])): ?>
          <div class="alert alert-warning">会话已超时，请重新登录</div>
        <?php endif; ?>
        <?php if (!empty($_GET['security'])): ?>
          <div class="alert alert-danger">安全验证失败，请重新登录</div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="login">
          <div class="mb-3">
            <label class="form-label fw-bold">用户名</label>
            <input class="form-control form-control-lg" name="username" placeholder="请输入用户名" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold">密码</label>
            <input type="password" class="form-control form-control-lg" name="password" placeholder="请输入密码" required>
          </div>
          <button class="btn btn-login w-100 btn-lg">🔐 登录</button>
        </form>
        <div class="text-center mt-4">
          <small class="text-muted">SyncoChan - GitHub 内容同步系统</small>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- 已登录页面 -->
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <img src="./assets/images/logo.png" alt="SyncoChan Logo" style="height: 60px;">
    <div>已登录：<?=htmlspecialchars($_SESSION['user'])?> <a href="?logout=1" class="btn btn-sm btn-outline-secondary">退出</a></div>
  </div>
  <div class="row">
    <div class="col-md-6">
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title">GitHub 仓库配置</h5>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=$csrf?>">
            <input type="hidden" name="action" value="save">
            
            <div class="mb-3">
              <label class="form-label fw-bold">仓库所有者 (Owner)</label>
              <input class="form-control" name="owner" value="<?=htmlspecialchars($owner)?>" placeholder="例如: github_username_or_org">
              <small class="form-text text-muted">GitHub 用户名或组织名称</small>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-bold">仓库名称 (Repository)</label>
              <input class="form-control" name="repo" value="<?=htmlspecialchars($repo)?>" placeholder="例如: my-blog">
              <small class="form-text text-muted">要同步的 GitHub 仓库名称</small>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-bold">分支名称 (Branch)</label>
              <input class="form-control" name="branch" value="<?=htmlspecialchars($branch)?>" placeholder="main">
              <small class="form-text text-muted">要同步的分支，通常为 main 或 master</small>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-bold">访问令牌 (Personal Access Token)</label>
              <input type="password" class="form-control" name="token" value="<?=htmlspecialchars($token)?>" placeholder="ghp_xxxxxxxxxxxx">
              <small class="form-text text-muted">在 GitHub Settings → Developer settings 中生成，需要 <code>repo</code> 权限</small>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-bold">GitHub 镜像源 (可选)</label>
              <input type="text" class="form-control" name="github_mirror" value="<?=htmlspecialchars($github_mirror)?>" placeholder="留空使用官方源，或输入镜像地址（如 https://ghproxy.net）">
              <small class="form-text text-muted">中国大陆用户如遇访问问题，可使用镜像源加速。留空则使用 GitHub 官方 API</small>
            </div>
            
            <button class="btn btn-success">💾 保存配置</button>
          </form>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title">🪝 Webhook 自动同步配置</h5>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=$csrf?>">
            <input type="hidden" name="action" value="save_webhook">
            
            <div class="mb-3">
              <label class="form-label fw-bold">Webhook 密钥 (Secret)</label>
              <input type="password" class="form-control" name="webhook_secret" value="<?=htmlspecialchars($webhook)?>" placeholder="自定义安全密钥">
              <small class="form-text text-muted">用于验证 GitHub Webhook 推送的安全密钥。建议使用复杂的随机字符串。留空则不验证签名（不推荐）。</small>
            </div>
            
            <?php 
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https://' : 'http://';
            $basePath = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/');
            $webhookUrl = $protocol . $_SERVER['HTTP_HOST'] . $basePath . '/backend/webhook.php';
            ?>
            
            <div class="alert alert-info" style="font-size: 0.9em;">
              <strong>📡 Webhook 回调地址</strong><br>
              <div class="input-group mt-2">
                <input type="text" class="form-control" value="<?=htmlspecialchars($webhookUrl)?>" readonly id="webhookUrlInput">
                <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText('<?=htmlspecialchars($webhookUrl, ENT_QUOTES)?>').then(() => alert('已复制到剪贴板'))">📋 复制</button>
              </div>
            </div>
            
            <?php 
            $lastCall = get_last_webhook_call();
            if ($lastCall):
            ?>
            <div class="alert <?=$lastCall['status'] === 'success' ? 'alert-success' : 'alert-danger'?>" style="font-size: 0.9em;">
              <strong>🕒 上次调用情况</strong><br>
              <table class="table table-sm table-borderless mb-0" style="font-size: 0.95em;">
                <tr>
                  <td style="width: 120px;"><strong>时间：</strong></td>
                  <td><?=htmlspecialchars($lastCall['created_at'])?></td>
                </tr>
                <tr>
                  <td><strong>事件类型：</strong></td>
                  <td><span class="badge bg-primary"><?=htmlspecialchars($lastCall['event_type'])?></span></td>
                </tr>
                <tr>
                  <td><strong>来源 IP：</strong></td>
                  <td><code><?=htmlspecialchars($lastCall['remote_addr'])?></code></td>
                </tr>
                <tr>
                  <td><strong>签名验证：</strong></td>
                  <td><?=$lastCall['signature_valid'] ? '<span class="badge bg-success">✓ 已验证</span>' : '<span class="badge bg-warning">未验证</span>'?></td>
                </tr>
                <tr>
                  <td><strong>状态：</strong></td>
                  <td><?=$lastCall['status'] === 'success' ? '<span class="badge bg-success">成功</span>' : '<span class="badge bg-danger">失败</span>'?></td>
                </tr>
                <?php if ($lastCall['error_message']): ?>
                <tr>
                  <td><strong>错误信息：</strong></td>
                  <td><code class="text-danger"><?=htmlspecialchars($lastCall['error_message'])?></code></td>
                </tr>
                <?php endif; ?>
              </table>
            </div>
            <?php else: ?>
            <div class="alert alert-secondary" style="font-size: 0.9em;">
              <strong>📭 暂无 Webhook 调用记录</strong><br>
              配置完成后，当 GitHub 仓库有推送时会自动触发同步。
            </div>
            <?php endif; ?>
            
            <div class="d-flex justify-content-between align-items-center">
              <button type="submit" class="btn btn-success">💾 保存 Webhook 配置</button>
              <button type="button" class="btn btn-outline-primary" id="btnViewWebhookLogs">📊 查看调用历史</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title">手动更新操作</h5>
          <div class="mb-2">
            <button id="btnUpdate" class="btn btn-primary">🔄 后台更新</button>
            <button id="btnUpdateSync" class="btn btn-secondary">⏳ 同步更新</button>
            <span id="updateStatus" class="ms-2"></span>
          </div>
          <div id="restrictedEnvNotice" class="alert alert-warning mt-2" style="display:none; font-size: 0.9em;">
            <strong>⚠️ 受限环境检测</strong><br>
            检测到您的主机环境限制了后台执行功能。建议使用"同步更新"按钮。
          </div>
          <small class="text-muted">
            💡 <strong>后台更新</strong>：在后台执行，页面立即响应（推荐）<br>
            💡 <strong>同步更新</strong>：页面会等待更新完成，适用于虚拟主机等受限环境
          </small>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title d-flex justify-content-between align-items-center">
            <span>📦 备份管理</span>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="enableBackupsToggle" <?php if (setting_get('enable_backups','1') !== '0') echo 'checked'; ?>>
              <label class="form-check-label" for="enableBackupsToggle">启用自动备份</label>
            </div>
          </h5>
          <div class="alert alert-info mb-3" style="font-size: 0.9em;">
            <strong>ℹ️ 关于备份：</strong><br>
            • 启用后，每次从 GitHub 同步更新前会自动创建备份<br>
            • 备份保存在 <code>backups/backup_*</code> 目录中<br>
            • 可以随时恢复到任意备份版本<br>
            • 建议定期清理旧备份以节省磁盘空间
          </div>
          <div id="backupsArea">
            <table class="table table-sm table-hover" id="backupsTable">
              <thead class="table-light"><tr><th>备份名称</th><th>Git Hash</th><th>创建时间</th><th>大小</th><th>操作</th></tr></thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h5 class="card-title d-flex justify-content-between align-items-center">
        <span>最近更新日志</span>
        <div>
          <button id="btnRefreshLog" class="btn btn-sm btn-outline-primary">刷新日志</button>
          <div class="form-check form-switch d-inline-block ms-2">
            <input class="form-check-input" type="checkbox" id="autoRefreshLog">
            <label class="form-check-label" for="autoRefreshLog">自动刷新</label>
          </div>
        </div>
      </h5>
      <pre id="logContent" style="max-height:500px;overflow:auto;font-size:0.85em;background:#f8f9fa;border:1px solid #dee2e6;padding:10px;"><?php echo htmlspecialchars(file_exists(__DIR__ . '/data/update.log') ? implode('', array_slice(file(__DIR__ . '/data/update.log'), -200)) : "no log\n"); ?></pre>
    </div>
  </div>

<?php endif; ?>

</div>

<script src="https://s4.zstatic.net/ajax/libs/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
<script>
  window.CSRF_TOKEN = '<?=$csrf?>';
</script>
<script src="./assets/admin.js"></script>
</body>
</html>
