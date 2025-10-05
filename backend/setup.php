<?php
require_once __DIR__ . '/lib/db.php';
// first-run setup: choose db driver and create admin
$cfgPath = __DIR__ . '/data/db_config.php';

// Check if already initialized
$alreadyInitialized = is_file($cfgPath);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prevent re-initialization
    if ($alreadyInitialized) {
        $error = '系统已经初始化，无法重复设置';
    } else {
        $driver = $_POST['driver'] ?? 'sqlite';
        $cfg = ['driver'=>$driver];
        
        // Validate MySQL configuration if selected
        if ($driver === 'mysql') {
            $cfg['host'] = $_POST['host'] ?? '127.0.0.1';
            $cfg['port'] = intval($_POST['port'] ?? 3306);
            $cfg['dbname'] = $_POST['dbname'] ?? '';
            $cfg['user'] = $_POST['user'] ?? '';
            $cfg['pass'] = $_POST['pass'] ?? '';
            $cfg['prefix'] = $_POST['prefix'] ?? '';
            
            // Check if required fields are filled
            if (empty($cfg['dbname']) || empty($cfg['user'])) {
                $error = '请填写数据库名称和用户名';
            }
        } else {
            // SQLite also supports prefix
            $cfg['prefix'] = $_POST['prefix'] ?? '';
        }
        
        if (empty($error)) {
            // Validate admin credentials
            $u = trim($_POST['admin_user'] ?? '');
            $p = $_POST['admin_pass'] ?? '';
            $p_confirm = $_POST['admin_pass_confirm'] ?? '';
            
            if (empty($u) || empty($p)) {
                $error = '请设置管理员用户名和密码';
            } elseif ($p !== $p_confirm) {
                $error = '两次输入的密码不一致';
            } elseif (strlen($p) < 6) {
                $error = '密码长度至少为 6 位字符';
            } else {
                // Save database config
                if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0755, true);
                file_put_contents($cfgPath, '<?php return ' . var_export($cfg, true) . ';');

                try {
                    // reload functions
                    require_once __DIR__ . '/lib/db.php';
                    create_admin_if_missing();
                    create_user($u, $p);
                    
                    $success = '初始化成功！正在跳转到登录页面...';
                    header('Refresh: 2; URL=admin.php');
                } catch (PDOException $e) {
                    // 记录详细错误到日志
                    error_log('Database connection failed during setup: ' . $e->getMessage());
                    error_log('Error Code: ' . $e->getCode());
                    error_log('Config: driver=' . $cfg['driver'] . ', host=' . ($cfg['host'] ?? 'N/A') . ', dbname=' . ($cfg['dbname'] ?? 'N/A'));
                    
                    // 向用户显示详细错误信息以便排查
                    $errorCode = $e->getCode();
                    $errorMsg = $e->getMessage();
                    
                    if ($driver === 'mysql') {
                        // MySQL/MariaDB 错误分类
                        if (strpos($errorMsg, 'Access denied') !== false) {
                            $error = '数据库连接失败：用户名或密码错误<br>';
                            $error .= '<small class="text-muted">请检查数据库用户名和密码是否正确</small>';
                        } elseif (strpos($errorMsg, 'Unknown database') !== false) {
                            $error = '数据库连接失败：数据库不存在<br>';
                            $error .= '<small class="text-muted">请先创建数据库「' . htmlspecialchars($cfg['dbname'] ?? '') . '」</small>';
                        } elseif (strpos($errorMsg, 'Connection refused') !== false || strpos($errorMsg, 'Can\'t connect') !== false) {
                            $error = '数据库连接失败：无法连接到数据库服务器<br>';
                            $error .= '<small class="text-muted">请检查：<br>1. 数据库服务是否已启动<br>2. 主机地址「' . htmlspecialchars($cfg['host'] ?? '') . '」和端口「' . htmlspecialchars($cfg['port'] ?? '') . '」是否正确<br>3. 防火墙设置是否允许连接</small>';
                        } elseif (strpos($errorMsg, 'timeout') !== false) {
                            $error = '数据库连接失败：连接超时<br>';
                            $error .= '<small class="text-muted">请检查网络连接和数据库服务器是否可访问</small>';
                        } else {
                            $error = '数据库连接失败<br>';
                            $error .= '<small class="text-muted">错误信息：' . htmlspecialchars($errorMsg) . '</small>';
                        }
                    } else {
                        // SQLite 错误
                        if (strpos($errorMsg, 'unable to open database') !== false || strpos($errorMsg, 'unable to write') !== false) {
                            $error = 'SQLite 数据库创建失败：文件权限不足<br>';
                            $error .= '<small class="text-muted">请检查 backend/data 目录是否有写入权限</small>';
                        } elseif (strpos($errorMsg, 'disk') !== false) {
                            $error = 'SQLite 数据库创建失败：磁盘空间不足<br>';
                            $error .= '<small class="text-muted">请检查磁盘空间</small>';
                        } else {
                            $error = 'SQLite 数据库初始化失败<br>';
                            $error .= '<small class="text-muted">错误信息：' . htmlspecialchars($errorMsg) . '</small>';
                        }
                    }
                    
                    @unlink($cfgPath);
                } catch (Exception $e) {
                    // 其他类型的异常
                    error_log('Setup failed: ' . $e->getMessage());
                    error_log('Exception type: ' . get_class($e));
                    
                    $error = '系统初始化失败<br>';
                    $error .= '<small class="text-muted">错误信息：' . htmlspecialchars($e->getMessage()) . '<br>';
                    $error .= '错误类型：' . htmlspecialchars(get_class($e)) . '</small>';
                    @unlink($cfgPath);
                }
            }
        }
    }
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>SyncoChan - 系统初始化</title>
  <link href="https://s4.zstatic.net/ajax/libs/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet">
  <link href="./assets/admin.css" rel="stylesheet">
  <style>
    .setup-container {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      padding: 2rem 1rem;
    }
    .setup-card {
      background: white;
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.3);
      overflow: hidden;
      max-width: 600px;
      width: 100%;
    }
    .setup-header {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 2rem;
      text-align: center;
    }
    .setup-logo {
      height: 60px;
      margin-bottom: 1rem;
      /* Remove filter to keep original logo colors */
    }
    .setup-body {
      padding: 2rem;
    }
    .setup-section {
      margin-bottom: 2rem;
    }
    .setup-section-title {
      font-size: 1.1rem;
      font-weight: 600;
      color: #667eea;
      margin-bottom: 1rem;
      padding-bottom: 0.5rem;
      border-bottom: 2px solid #667eea;
    }
    .btn-setup {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border: none;
      color: white;
      padding: 0.75rem;
      font-weight: 600;
      border-radius: 10px;
      transition: transform 0.2s;
    }
    .btn-setup:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
      color: white;
    }
    .form-control:focus, .form-select:focus {
      border-color: #667eea;
      box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
    }
    .info-badge {
      background: #e8eaf6;
      color: #5c6bc0;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-size: 0.9rem;
      margin-bottom: 1rem;
    }
  </style>
</head>
<body>
  <div class="setup-container">
    <div class="setup-card">
      <div class="setup-header">
        <img src="./assets/images/logo.png" alt="SyncoChan Logo" class="setup-logo">
        <h3 class="mb-0">欢迎使用 SyncoChan</h3>
        <p class="mb-0 mt-2" style="opacity: 0.9;">GitHub 内容同步系统 - 初次设置</p>
      </div>
      
      <div class="setup-body">
        <?php if ($alreadyInitialized): ?>
          <div class="alert alert-warning" role="alert">
            <strong>⚠️ 系统已初始化</strong><br>
            检测到系统已经完成初始化配置。如需重新设置，请先删除配置文件。<br>
          </div>
          <div class="text-center mt-4">
            <a href="admin.php" class="btn btn-setup btn-lg">
              🔐 前往登录页面
            </a>
          </div>
        <?php else: ?>
        
        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>❌ 错误：</strong> <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
          <div class="alert alert-success" role="alert">
            <strong>✅ <?= htmlspecialchars($success) ?></strong>
          </div>
        <?php else: ?>
        
        <div class="info-badge">
          <strong>📋 设置向导</strong><br>
          首次使用需要配置数据库和管理员账号，完成后即可开始使用。
        </div>
        
        <form method="post">
          <!-- 数据库配置 -->
          <div class="setup-section">
            <div class="setup-section-title">🗄️ 数据库配置</div>
            
            <div class="mb-3">
              <label class="form-label fw-bold">数据库类型</label>
              <select name="driver" class="form-select form-select-lg" id="dbDriver">
                <option value="sqlite">SQLite（无需配置）</option>
                <option value="mysql">MySQL / MariaDB</option>
              </select>
              <small class="form-text text-muted">
                SQLite 适合小规模使用或本地调试，MySQL 适合生产环境使用。
              </small>
            </div>
            
            <div id="mysqlfields" style="display:none">
              <div class="alert alert-info" style="font-size: 0.9em;">
                <strong>ℹ️ MySQL 配置说明：</strong><br>
                请确保已创建数据库，并且用户有相应的权限。系统会自动创建所需的数据表。
              </div>
              
              <div class="row">
                <div class="col-md-8 mb-3">
                  <label class="form-label fw-bold">主机地址</label>
                  <input name="host" value="127.0.0.1" class="form-control" placeholder="127.0.0.1">
                </div>
                <div class="col-md-4 mb-3">
                  <label class="form-label fw-bold">端口</label>
                  <input name="port" value="3306" class="form-control" placeholder="3306">
                </div>
              </div>
              
              <div class="mb-3">
                <label class="form-label fw-bold">数据库名称 <span class="text-danger">*</span></label>
                <input name="dbname" class="form-control" placeholder="syncochan" required>
              </div>
              
              <div class="mb-3">
                <label class="form-label fw-bold">用户名 <span class="text-danger">*</span></label>
                <input name="user" class="form-control" placeholder="数据库用户名" required>
              </div>
              
              <div class="mb-3">
                <label class="form-label fw-bold">密码</label>
                <input name="pass" type="password" class="form-control" placeholder="数据库密码">
              </div>
              
              <div class="mb-3">
                <label class="form-label fw-bold">表前缀</label>
                <input name="prefix" class="form-control" placeholder="例如：sc_（可选，留空表示无前缀）">
                <small class="form-text text-muted">
                  为数据表添加前缀，适用于多个应用共用一个数据库的情况
                </small>
              </div>
            </div>
          </div>
          
          <!-- 管理员账号 -->
          <div class="setup-section">
            <div class="setup-section-title">👤 管理员账号</div>
            
            <div class="mb-3">
              <label class="form-label fw-bold">用户名 <span class="text-danger">*</span></label>
              <input name="admin_user" value="admin" class="form-control" placeholder="管理员用户名" required autofocus>
              <small class="form-text text-muted">用于登录后台管理系统</small>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-bold">密码 <span class="text-danger">*</span></label>
              <input name="admin_pass" type="password" class="form-control" id="adminPass" placeholder="请设置安全密码" required minlength="6">
              <div class="mt-2">
                <div class="progress" style="height: 5px;">
                  <div class="progress-bar" id="passwordStrength" role="progressbar" style="width: 0%"></div>
                </div>
                <small class="form-text" id="passwordHint">密码强度：<span id="strengthText" class="text-muted">未输入</span></small>
              </div>
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-bold">确认密码 <span class="text-danger">*</span></label>
              <input name="admin_pass_confirm" type="password" class="form-control" id="adminPassConfirm" placeholder="再次输入密码" required minlength="6">
              <small class="form-text" id="confirmHint"></small>
            </div>
            
            <div class="alert alert-info" style="font-size: 0.9em;">
              <strong>🔒 密码要求：</strong><br>
              • 至少 6 位字符<br>
              • 建议包含大小写字母、数字和特殊字符<br>
              • 避免使用过于简单的密码
            </div>
          </div>
          
          <button type="submit" class="btn btn-setup w-100 btn-lg" id="submitBtn">
            🚀 完成设置并进入后台
          </button>
        </form>
        
        <div class="text-center mt-4">
          <small class="text-muted">
            设置完成后，您可以配置 GitHub 仓库并开始同步内容
          </small>
        </div>
        
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script src="https://s4.zstatic.net/ajax/libs/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
  <script>
    // Toggle MySQL fields based on database driver selection
    const dbDriver = document.getElementById('dbDriver');
    const mysqlFields = document.getElementById('mysqlfields');
    const mysqlInputs = mysqlFields.querySelectorAll('input[required]');
    
    function toggleMySQLFields() {
      const isMysql = dbDriver.value === 'mysql';
      mysqlFields.style.display = isMysql ? 'block' : 'none';
      
      // Enable/disable required attribute for MySQL fields
      mysqlInputs.forEach(input => {
        input.required = isMysql;
      });
    }
    
    if (dbDriver) {
      dbDriver.addEventListener('change', toggleMySQLFields);
      toggleMySQLFields(); // Initialize on page load
    }
    
    // Password strength checker
    const adminPass = document.getElementById('adminPass');
    const adminPassConfirm = document.getElementById('adminPassConfirm');
    const passwordStrength = document.getElementById('passwordStrength');
    const strengthText = document.getElementById('strengthText');
    const confirmHint = document.getElementById('confirmHint');
    const submitBtn = document.getElementById('submitBtn');
    
    if (adminPass) {
      adminPass.addEventListener('input', function() {
        const password = this.value;
        const strength = calculatePasswordStrength(password);
        
        // Update progress bar
        passwordStrength.style.width = strength.percentage + '%';
        passwordStrength.className = 'progress-bar ' + strength.class;
        strengthText.textContent = strength.text;
        strengthText.className = strength.textClass;
        
        // Check password match
        checkPasswordMatch();
      });
      
      adminPassConfirm.addEventListener('input', checkPasswordMatch);
    }
    
    function calculatePasswordStrength(password) {
      if (!password) {
        return { percentage: 0, class: '', text: '未输入', textClass: 'text-muted' };
      }
      
      let strength = 0;
      
      // Length check
      if (password.length >= 6) strength += 20;
      if (password.length >= 8) strength += 20;
      if (password.length >= 12) strength += 10;
      
      // Contains lowercase
      if (/[a-z]/.test(password)) strength += 15;
      
      // Contains uppercase
      if (/[A-Z]/.test(password)) strength += 15;
      
      // Contains number
      if (/[0-9]/.test(password)) strength += 15;
      
      // Contains special character
      if (/[^a-zA-Z0-9]/.test(password)) strength += 15;
      
      // Determine strength level
      if (strength < 40) {
        return { percentage: strength, class: 'bg-danger', text: '弱', textClass: 'text-danger' };
      } else if (strength < 70) {
        return { percentage: strength, class: 'bg-warning', text: '中等', textClass: 'text-warning' };
      } else {
        return { percentage: strength, class: 'bg-success', text: '强', textClass: 'text-success' };
      }
    }
    
    function checkPasswordMatch() {
      if (!adminPassConfirm.value) {
        confirmHint.textContent = '';
        confirmHint.className = 'form-text';
        return;
      }
      
      if (adminPass.value === adminPassConfirm.value) {
        confirmHint.textContent = '✓ 密码一致';
        confirmHint.className = 'form-text text-success';
      } else {
        confirmHint.textContent = '✗ 密码不一致';
        confirmHint.className = 'form-text text-danger';
      }
    }
    
    // Form validation
    const form = document.querySelector('form');
    if (form) {
      form.addEventListener('submit', function(e) {
        if (adminPass && adminPassConfirm) {
          if (adminPass.value !== adminPassConfirm.value) {
            e.preventDefault();
            alert('两次输入的密码不一致，请检查！');
            adminPassConfirm.focus();
            return false;
          }
          
          if (adminPass.value.length < 6) {
            e.preventDefault();
            alert('密码长度至少为 6 位字符！');
            adminPass.focus();
            return false;
          }
        }
      });
    }
  </script>
</body>
</html>
