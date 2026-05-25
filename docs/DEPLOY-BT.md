# CANG-AI 绘图 · 宝塔部署教程

## 环境要求

| 项目 | 要求 |
|------|------|
| 系统 | CentOS 7+ / Ubuntu 20+ / Debian 10+ |
| 宝塔 | 8.0+ |
| Nginx | 1.20+ |
| PHP | 8.1+（需启用 curl、pdo_sqlite、fileinfo） |

## 项目目录结构

```
├── index.html              # 主页
├── admin.html              # 管理后台
├── admin-login.html        # 管理登录
├── explore.html            # 探索页
├── 404.html
├── api/                    # 后端接口
│   ├── generate-task.php   # 图片生成
│   ├── auth-api.php        # 用户认证
│   ├── admin-api.php       # 管理接口
│   ├── explore-api.php     # 探索接口
│   ├── download.php        # 图片下载
│   ├── list.php            # 图片列表
│   ├── proxy-openai.php    # API 代理
│   └── install.php         # 安装向导
├── lib/                    # 核心库（禁止外部访问）
│   ├── app-lib.php
│   └── auth-lib.php
├── config/                 # 配置文件（禁止外部访问）
│   ├── oss-config.php.example
│   ├── cos-config.php.example
│   └── r2-config.php.example
├── scripts/                # 部署脚本（禁止外部访问）
│   ├── deploy-bt.sh
│   └── init-admin.php
├── docs/                   # 文档
└── runtime/                # 运行时数据（禁止外部访问）
    ├── images/
    ├── generation-task-files/
    ├── generation-tasks.sqlite
    └── auth.sqlite
```

---

## 方式一：一键脚本部署（推荐）

### 1. 上传项目到服务器

```bash
scp -r ./cang-ai-draw root@your-server:/root/
```

### 2. 运行部署脚本

```bash
cd /root/cang-ai-draw
bash scripts/deploy-bt.sh
```

脚本会交互式询问：
- 站点域名
- 存储模式（本地 / OSS / COS / R2）

### 3. 宝塔面板配置

按脚本提示完成：

1. **创建站点** → PHP 8.1+
2. **伪静态** → 粘贴 Nginx 规则（见下方）
3. **SSL** → Let's Encrypt
4. **访问安装页** → `https://你的域名/api/install.php`

---

## 方式二：Web 安装向导

### 1. 宝塔创建站点

宝塔面板 → 网站 → 添加站点：
- 域名：`draw.example.com`
- PHP 版本：8.1+
- 数据库：不需要

### 2. 上传文件

将项目所有文件上传到站点目录 `/www/wwwroot/draw.example.com/`。

### 3. 配置伪静态

站点设置 → 伪静态，粘贴：

```nginx
location ^~ /runtime/ { deny all; return 403; }
location ^~ /config/  { deny all; return 403; }
location ^~ /lib/     { deny all; return 403; }
location ^~ /scripts/ { deny all; return 403; }
```

### 4. 修改 Nginx 配置

站点设置 → 配置文件，找到 PHP 的 location 块（通常是 `location ~ [^/]\.php(/|$)`），在其中添加：

```nginx
fastcgi_split_path_info ^(.+?\.php)(/.*)$;
set $path_info $fastcgi_path_info;
fastcgi_param PATH_INFO $path_info;
fastcgi_read_timeout 600;
fastcgi_send_timeout 600;
```

> 如果 PHP location 块是 `location ~ \.php$`，需改为 `location ~ [^/]\.php(/|$)` 以支持 PATH_INFO。

### 5. 访问安装页面

```
https://draw.example.com/api/install.php
```

安装向导会自动：
- 检测 PHP 版本和扩展
- 选择存储模式（本地 / OSS / COS / R2）
- 创建管理员账号
- 创建目录和配置文件
- 完成后自动锁定

### 5. 申请 SSL

站点设置 → SSL → Let's Encrypt 一键申请。

---

## 方式三：手动部署

### 1. 上传文件到站点目录

### 2. 设置目录权限

```bash
cd /www/wwwroot/draw.example.com
mkdir -p runtime/generation-task-files runtime/images
chown -R www:www runtime
chmod -R 750 runtime
```

### 3. 配置存储（可选）

本地模式无需配置。如需对象存储：

```bash
cp config/oss-config.php.example config/oss-config.php
# 编辑填入你的 OSS 配置
```

### 4. 初始化管理员

访问 `https://你的域名/api/install.php` 完成安装并创建管理员账号。

### 5. 配置伪静态和 SSL

同方式二的步骤 3、5。

---

## PHP 配置优化

宝塔 → PHP 8.1 → 配置修改：

```ini
max_execution_time = 600
upload_max_filesize = 10M
post_max_size = 12M
memory_limit = 256M
```

---

## Nginx 伪静态规则（必须配置）

```nginx
# 禁止访问敏感目录（放入伪静态）
location ^~ /runtime/ { deny all; return 403; }
location ^~ /config/  { deny all; return 403; }
location ^~ /lib/     { deny all; return 403; }
location ^~ /scripts/ { deny all; return 403; }
```

在站点配置文件的 PHP location 块中添加（支持 PATH_INFO 路由）：

```nginx
# 修改 PHP location 为: location ~ [^/]\.php(/|$) {
fastcgi_split_path_info ^(.+?\.php)(/.*)$;
set $path_info $fastcgi_path_info;
fastcgi_param PATH_INFO $path_info;
fastcgi_read_timeout 600;
fastcgi_send_timeout 600;
```

> ⚠️ 不配置伪静态会导致 `lib/`、`config/` 目录中的敏感文件可被直接访问！
> ⚠️ 不配置 PATH_INFO 会导致 API 路由（如 `/api/auth-api.php/login`）返回 404！

---

## 验证部署

1. 访问 `https://draw.example.com` → 看到绘图界面
2. 右上角登录管理员账号
3. 输入提示词，点击生成
4. 访问 `https://draw.example.com/admin.html` → 管理后台

---

## 常见问题

### 生成超时 / 502

PHP 超时不够：
- `max_execution_time = 600`
- Nginx `fastcgi_read_timeout 600`

### runtime 目录无权限

```bash
chown -R www:www /www/wwwroot/draw.example.com/runtime
chmod -R 750 /www/wwwroot/draw.example.com/runtime
```

### 如何重置管理员

```bash
rm runtime/install.lock runtime/auth.sqlite
# 重新访问 /api/install.php
```

### 更新版本

```bash
cd /root/cang-ai-draw
git pull
bash scripts/deploy-bt.sh
# 选择相同域名，文件会自动同步（不覆盖 config 和 runtime）
```
