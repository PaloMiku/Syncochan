# 贡献指南

感谢您考虑为 Syncochan 做出贡献！本文档提供了参与项目开发的指南。

## 📋 目录

- [行为准则](#行为准则)
- [如何贡献](#如何贡献)
- [开发流程](#开发流程)
- [代码规范](#代码规范)
- [提交 Issue](#提交-issue)
- [提交 Pull Request](#提交-pull-request)

## 🤝 行为准则

请参与者：

- 使用友好和包容的语言
- 尊重不同的观点和经验
- 优雅地接受建设性批评
- 关注对社区最有利的事情
- 对其他社区成员表示同理心

## 💡 如何贡献

您可以通过以下方式为项目做出贡献：

### 报告 Bug

在提交 Bug 报告之前：

1. 检查是否已有相关 Issue
2. 确认问题可重现
3. 收集相关信息（PHP 版本、Web 服务器、错误日志等）

### 建议新功能

1. 确保该功能符合项目目标
2. 提供清晰的使用场景
3. 考虑实现的可行性

### 改进文档

- 修正拼写或语法错误
- 增加示例和说明
- 翻译文档到其他语言

### 提交代码

- 修复 Bug
- 实现新功能
- 性能优化
- 代码重构

## 🔧 开发流程

### 1. Fork 项目

点击 GitHub 页面右上角的 "Fork" 按钮。

### 2. 克隆仓库

```bash
git clone https://github.com/your-username/Syncochan.git
cd Syncochan
```

### 3. 创建分支

```bash
git checkout -b feature/your-feature-name
# 或
git checkout -b fix/your-bug-fix
```

分支命名规范：

- `feature/xxx` - 新功能
- `fix/xxx` - Bug 修复
- `docs/xxx` - 文档改进
- `refactor/xxx` - 代码重构
- `perf/xxx` - 性能优化

### 4. 开发环境设置

```bash
# 设置开发环境
cp backend/data/db_config.php.example backend/data/db_config.php

# 设置权限
chmod -R 775 backend/data backups
```

### 5. 进行更改

- 遵循代码规范
- 添加必要的注释
- 保持代码简洁清晰

### 6. 测试

确保您的更改：

- 不破坏现有功能
- 在多种环境下测试（Apache/Nginx、不同 PHP 版本）
- 添加适当的错误处理

### 7. 提交更改

```bash
git add .
git commit -m "类型: 简短描述

详细描述更改内容，说明为什么这样做。

相关 Issue: #123"
```

提交信息格式：

```
类型: 简短描述（不超过 50 字符）

详细描述（可选，解释 what 和 why，而不是 how）

相关 Issue: #issue_number（如果适用）
```

提交类型：

- `feat`: 新功能
- `fix`: Bug 修复
- `docs`: 文档更新
- `style`: 代码格式（不影响功能）
- `refactor`: 代码重构
- `perf`: 性能优化
- `test`: 测试相关
- `chore`: 构建或辅助工具

示例：

```
feat: 添加自动清理旧备份功能

- 在更新后自动删除超过 10 个的旧备份
- 添加配置选项允许用户自定义保留数量
- 更新管理界面显示备份统计信息

相关 Issue: #45
```

### 8. 推送到 GitHub

```bash
git push origin feature/your-feature-name
```

### 9. 创建 Pull Request

在 GitHub 上创建 Pull Request，填写：

- 标题：简洁描述更改
- 描述：详细说明更改内容、原因和影响
- 相关 Issue 编号

## 📝 代码规范

### PHP 代码规范

遵循 PSR-12 编码标准的基本原则：

```php
<?php
// 1. 使用 4 个空格缩进（不是 Tab）
// 2. 左花括号另起一行（函数和类）
// 3. 控制结构的左花括号在同一行

// 好的示例
function performUpdate(array $options = []): array 
{
    if (empty($options['repo'])) {
        return ['ok' => false, 'msg' => 'repo required'];
    }
    
    // 逻辑代码
}

// 类命名使用 PascalCase
class BackupManager 
{
    // 常量全大写
    const MAX_BACKUPS = 10;
    
    // 属性使用 camelCase
    private $backupPath;
    
    // 方法使用 camelCase
    public function createBackup(): bool 
    {
        // 实现
    }
}
```

### 命名约定

- **变量和函数**: `camelCase` 或 `snake_case`（保持项目一致性）
- **类**: `PascalCase`
- **常量**: `UPPER_CASE`
- **数据库表**: `snake_case`

### 注释规范

```php
/**
 * 执行 GitHub 仓库同步更新
 * 
 * @param array $opts 配置选项
 *   - owner: GitHub 用户名或组织
 *   - repo: 仓库名称
 *   - branch: 分支名称
 *   - token: 访问令牌（可选）
 * 
 * @return array 返回结果
 *   - ok: 是否成功
 *   - msg: 消息或错误信息
 *   - backup: 备份路径（成功时）
 */
function perform_update(array $opts = []): array 
{
    // 实现
}
```

### 安全注意事项

1. **输入验证**: 始终验证和清理用户输入
2. **SQL 注入**: 使用参数化查询
3. **XSS 防护**: 转义输出
4. **CSRF 保护**: 使用 CSRF token
5. **路径遍历**: 使用 `basename()` 和路径验证

```php
// 好的示例
$backup = basename($_POST['backup'] ?? '');
if (!preg_match('/^backup_[a-f0-9]+$/', $backup)) {
    die('invalid backup name');
}
```

### 错误处理

```php
// 使用明确的错误处理
try {
    $pdo = get_db();
    // 操作
} catch (PDOException $e) {
    if (function_exists('log_update')) {
        log_update("Database error: " . $e->getMessage());
    }
    return ['ok' => false, 'msg' => 'database error'];
}
```

## 🐛 提交 Issue

### Bug 报告模板

```markdown
### 问题描述
简要描述遇到的问题

### 复现步骤
1. 第一步
2. 第二步
3. 第三步

### 期望行为
描述期望发生什么

### 实际行为
描述实际发生了什么

### 环境信息
- OS: [例如 Ubuntu 22.04]
- Web 服务器: [例如 Apache 2.4.52]
- PHP 版本: [例如 8.1.2]
- 数据库: [例如 SQLite 3.37.2]
- Syncochan 版本: [例如 v1.0.0]

### 错误日志
```

粘贴相关错误日志

```

### 截图
如果适用，添加截图
```

### 功能请求模板

```markdown
### 功能描述
清晰简洁地描述您想要的功能

### 使用场景
描述为什么需要这个功能，它解决什么问题

### 建议的实现方式
如果有想法，可以描述如何实现

### 替代方案
是否考虑过其他替代方案

### 附加信息
任何其他相关信息
```

## 🔀 提交 Pull Request

### PR 检查清单

提交 PR 前，请确认：

- [ ] 代码遵循项目规范
- [ ] 已在本地测试所有更改
- [ ] 已添加或更新相关文档
- [ ] 提交信息清晰明确
- [ ] 已解决所有合并冲突
- [ ] PR 标题和描述清晰
- [ ] 已关联相关 Issue

### PR 描述模板

```markdown
## 更改类型
- [ ] Bug 修复
- [ ] 新功能
- [ ] 文档更新
- [ ] 代码重构
- [ ] 性能优化
- [ ] 其他（请说明）

## 更改说明
描述此 PR 的主要更改内容

## 相关 Issue
Closes #issue_number

## 测试
描述如何测试这些更改

## 截图（如果适用）
添加相关截图

## 检查清单
- [ ] 代码已测试
- [ ] 文档已更新
- [ ] 无合并冲突
- [ ] 遵循代码规范
```

## 📚 其他资源

- [GitHub Flow](https://guides.github.com/introduction/flow/)
- [如何编写 Git 提交信息](https://chris.beams.io/posts/git-commit/)
- [PHP PSR-12 编码规范](https://www.php-fig.org/psr/psr-12/)

## 🙋 问题？

如有任何问题，请：

1. 搜索现有 [Issues](https://github.com/PaloMiku/Syncochan/issues)
2. 创建新 Issue 提问

## 🎉 致谢

感谢所有贡献者让 Syncochan 变得更好！

---

**Happy Coding! 🚀**
