# CANG-AI 绘图

AI 图片生成平台，支持 GPT-Image 等模型，带用户管理和卡密系统。

## 快速部署

```bash
bash scripts/deploy-bt.sh
```

详细教程见 [docs/DEPLOY-BT.md](docs/DEPLOY-BT.md)

## 目录说明

| 目录 | 用途 |
|------|------|
| `api/` | 后端 PHP 接口 |
| `lib/` | 核心业务逻辑库 |
| `config/` | 配置文件（含示例） |
| `scripts/` | 部署和初始化脚本 |
| `docs/` | 文档 |
| `runtime/` | 运行时数据（自动创建，gitignore） |

## 功能

- AI 图片生成（文生图 / 图生图）
- 多存储后端（本地 / 阿里云 OSS / 腾讯云 COS / Cloudflare R2）
- 用户注册登录 + 卡密兑换系统
- 管理后台（用户管理、卡密管理、数据统计）
- 探索页（公开作品展示）

## 技术栈

- PHP 8.1+ / SQLite / Nginx
- 原生 JavaScript 前端
- 零外部依赖
