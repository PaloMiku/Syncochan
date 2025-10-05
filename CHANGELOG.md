# Changelog

All notable changes to Syncochan will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned

- 多语言支持（英文、中文）
- 定时自动更新功能
- 更详细的更新统计和可视化

## [1.0.0] - 2025-01-05

### Added

- 初始版本发布
- GitHub 仓库自动同步功能
- Webhook 自动更新支持
- 备份管理系统（自动备份、恢复、删除）
- SQLite 和 MySQL 数据库支持
- 管理后台界面
- 更新日志系统
- Webhook 调用日志
- 支持 GitHub 镜像源配置
- 支持私有仓库（通过 Token）
- 安全特性：
  - 管理员密码认证
  - CSRF 保护
  - Webhook 签名验证
  - 目录遍历防护
- SPA 应用支持（客户端路由）
- 静态资源缓存优化
- 完整的文档（README、安装指南、配置指南）

### Technical Details

- PHP 7.4+ 支持
- Apache 和 Nginx 伪静态配置
- 异步更新支持（多种后台执行方法）
- 受限环境的同步更新模式
- 自动备份清理（保留最近 5 个）
- 详细的日志记录系统
- 错误处理和恢复机制

### Documentation

- 完整的 README.md

## 版本说明

### 版本号格式

采用语义化版本号：MAJOR.MINOR.PATCH

- **MAJOR**: 不兼容的 API 修改
- **MINOR**: 向下兼容的功能性新增
- **PATCH**: 向下兼容的问题修正

### 更新类型说明

- **Added**: 新增功能
- **Changed**: 现有功能的变更
- **Deprecated**: 即将废弃的功能
- **Removed**: 已移除的功能
- **Fixed**: Bug 修复
- **Security**: 安全性修复

---

[Unreleased]: https://github.com/PaloMiku/Syncochan/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/PaloMiku/Syncochan/releases/tag/v1.0.0
