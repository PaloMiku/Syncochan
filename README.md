![](/backend/assets/images/logo.png)

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-blue.svg)

Syncochan 是一个基于 PHP 的轻量级 GitHub 静态站点同步系统，专为将 GitHub 仓库自动同步到 Web 服务器或虚拟主机而设计。

## ✨ 特性

- 🚀 **自动同步** - 支持 GitHub Webhook 自动推送更新
- 📦 **备份管理** - 自动创建和管理内容备份，支持一键恢复
- 🔐 **安全认证** - 管理后台密码保护，Webhook 签名验证
- 💾 **多数据库支持** - 支持 SQLite 和 MySQL 数据库
- 🌐 **镜像加速** - 支持自定义 GitHub 镜像源
- 📊 **日志系统** - 详细的更新日志和 Webhook 调用记录
- 🎯 **智能路由** - 支持 SPA 应用的客户端路由（如 Nuxt.js、Vue.js 等）
- ⚡ **高性能** - 优化的文件传输和缓存策略

## 📋 系统要求

- PHP 7.4 或更高版本
- PHP 扩展：
  - `zip` - 用于解压 GitHub 仓库压缩包
  - `curl` - 用于下载远程文件
  - `pdo_sqlite` 或 `pdo_mysql` - 数据库支持
- Web 服务器：Apache 2.4+ 或 Nginx 1.10+
- 文件系统写入权限

## 🚀 快速开始

### 1. 下载和部署

```bash
# 克隆项目
git clone https://github.com/PaloMiku/Syncochan.git

# 或直接下载压缩包解压到 Web 目录
```

### 2. 配置伪静态

#### Apache 配置

项目根目录已自带`.htaccess`文件，可参考使用。

#### Nginx 配置

```nginx
location ~ ^/(backend/data|backups|\.git) {
    deny all;
    return 404;
}

location ~ /\.(env|htaccess|gitignore)$ {
    deny all;
    return 404;
}

location ~ composer\.(json|lock)$ {
    deny all;
    return 404;
}

location /backend/ {
    try_files $uri $uri/ /backend/index.php?$query_string;
    
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
    }
}

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ ^/[^/]+\.php$ {
    try_files $uri =404;
    fastcgi_pass unix:/var/run/php/php-fpm.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
    fastcgi_read_timeout 300;
    fastcgi_send_timeout 300;
}

location ~* \.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff|woff2|ttf|eot|otf)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    access_log off;
}
```

### 3. 初始化系统

1. 访问 `http://your-domain.com/backend/`，应当会自动跳转到项目初始化页面
2. 选择数据库类型（SQLite 或 MySQL）
3. 设置管理员账号和密码
4. 完成初始化

### 4. 配置同步源

1. 访问 `http://your-domain.com/backend/` 登录
2. 在设置页面填写：
   - **Owner**: GitHub 用户名或组织名
   - **Repo**: 仓库名称
   - **Branch**: 分支名称（默认 `main`）
   - **Token**: GitHub Personal Access Token（可选，用于私有仓库）
   - **GitHub Mirror**: 镜像源地址（可选，如 `https://ghproxy.net`）

3. 点击"立即更新"测试同步功能

## 🔔 配置 Webhook（可选）

### 1. 生成 Webhook Secret

在管理后台的"Webhook 设置"中生成或设置一个密钥。

### 2. 在 GitHub 仓库中配置 Webhook

1. 进入对应仓库的 Settings → Webhooks → Add webhook
2. 填写配置：
   - **Payload URL**: `http://your-domain.com/backend/webhook.php`
   - **Content type**: `application/json`
   - **Secret**: 在管理后台设置的密钥
   - **Events**: 选择 `Just the push event`
3. 点击 Add webhook

现在，每次向仓库推送代码，网站都会自动同步更新！

## 📚 目录结构

```markdown
syncochan/
├── index.php              # 前台入口，处理静态文件和 SPA 路由
├── README.md             # 项目文档
├── .htaccess             # Apache 伪静态规则（可选）
├── backend/              # 后端管理系统
│   ├── admin.php         # 管理后台主页面
│   ├── api.php           # AJAX API 接口
│   ├── setup.php         # 系统初始化页面
│   ├── webhook.php       # GitHub Webhook 接收端点
│   ├── init.php          # 初始化和更新逻辑
│   ├── update.php        # CLI 更新脚本
│   ├── data/             # 数据存储目录
│   │   ├── db_config.php # 数据库配置（自动生成）
│   │   ├── syncochan.db  # SQLite 数据库（如使用 SQLite）
│   │   ├── update.log    # 更新日志
│   │   └── sessions/     # PHP 会话存储
│   ├── lib/              # 库文件
│   │   └── db.php        # 数据库操作函数
│   └── assets/           # 静态资源
│       ├── admin.css     # 管理后台样式
│       ├── admin.js      # 管理后台脚本
│       └── images/       # 图片资源
├── content/              # 同步的静态站点内容（自动生成）
│   └── ...              # 从 GitHub 同步的文件
└── backups/              # 备份目录（自动生成）
    └── backup_<hash>/    # 按 commit hash 命名的备份
```

## 🔧 高级配置

### 使用 GitHub Token

对于私有仓库或避免 API 限制，建议配置 GitHub Personal Access Token：

1. 访问 GitHub Settings → Developer settings → Personal access tokens → Tokens (classic)
2. 生成新 token，至少需要 `repo` 权限
3. 在管理后台填入 token

### 备份策略

- 系统默认保留最近 5 个备份
- 每次更新前自动创建备份
- 备份以 Git commit hash 命名，便于追踪
- 可在管理后台一键恢复或删除备份

### 日志查看

- **更新日志**: 查看详细的同步过程和错误信息
- **Webhook 日志**: 查看 Webhook 调用历史和签名验证结果

## 🐛 故障排查

### 问题：无法访问管理后台

- 检查 Web 服务器伪静态规则是否正确配置
- 确认 PHP 版本和所需扩展已安装
- 查看 Web 服务器错误日志

### 问题：更新失败

- 检查服务器是否能访问 GitHub（或镜像源）
- 确认 `content` 和 `backups` 目录有写入权限
- 查看更新日志获取详细错误信息
- 对于私有仓库，确认 Token 是否有效

### 问题：Webhook 不工作

- 在 GitHub Webhook 页面查看请求日志
- 确认 Webhook Secret 配置正确
- 检查 PHP 是否能执行后台任务（某些虚拟主机可能有限制）
- 查看 Webhook 日志了解详细信息

### 问题：文件权限错误

```bash
# 设置正确的权限
chmod -R 755 /path/to/syncochan
chown -R www-data:www-data /path/to/syncochan  # 根据实际用户调整
chmod -R 775 backend/data
chmod -R 775 backups
```

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

## 📄 许可证

本项目采用 MIT 许可证。详见 [LICENSE](LICENSE) 文件。

## 👨‍💻 作者

**PaloMiku**

- GitHub: [@PaloMiku](https://github.com/PaloMiku)

**Enjoy Syncochan! 🎉**
