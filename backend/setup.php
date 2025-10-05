<?php
require_once __DIR__ . '/lib/db.php';
// first-run setup: choose db driver and create admin
$cfgPath = __DIR__ . '/data/db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver = $_POST['driver'] ?? 'sqlite';
    $cfg = ['driver'=>$driver];
    if ($driver === 'mysql') {
        $cfg['host'] = $_POST['host'] ?? '127.0.0.1';
        $cfg['port'] = intval($_POST['port'] ?? 3306);
        $cfg['dbname'] = $_POST['dbname'] ?? '';
        $cfg['user'] = $_POST['user'] ?? '';
        $cfg['pass'] = $_POST['pass'] ?? '';
    }
    file_put_contents($cfgPath, '<?php return ' . var_export($cfg, true) . ';');

    // create admin user
    $u = $_POST['admin_user'] ?? 'admin';
    $p = $_POST['admin_pass'] ?? 'admin';
    // reload functions
    require_once __DIR__ . '/lib/db.php';
    create_admin_if_missing();
    create_user($u, $p);

    header('Location: admin.php');
    exit;
}

?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Setup</title></head>
<body>
<h2>初次设置</h2>
<form method="post">
  <label>数据库：<select name="driver"><option value="sqlite">SQLite</option><option value="mysql">MySQL</option></select></label><br>
  <div id="mysqlfields" style="display:none">
    <label>host <input name="host" value="127.0.0.1"></label><br>
    <label>port <input name="port" value="3306"></label><br>
    <label>dbname <input name="dbname"></label><br>
    <label>user <input name="user"></label><br>
    <label>pass <input name="pass" type="password"></label><br>
  </div>
  <h3>管理员账号</h3>
  <label>username <input name="admin_user" value="admin"></label><br>
  <label>password <input name="admin_pass" value="admin"></label><br>
  <button>保存并进入后台</button>
</form>
<script>
document.querySelector('select[name=driver]').addEventListener('change', function(e){
  document.getElementById('mysqlfields').style.display = e.target.value === 'mysql' ? 'block' : 'none';
});
</script>
</body></html>
