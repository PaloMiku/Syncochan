<?php

// 检查数据库配置文件是否存在
$dbConfigPath = __DIR__ . '/data/db_config.php';

if (!is_file($dbConfigPath)) {
    // 系统未初始化，跳转到设置页面
    header('Location: setup.php');
    exit;
} else {
    // 系统已初始化，跳转到管理后台
    header('Location: admin.php');
    exit;
}
