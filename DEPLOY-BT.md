# 苍API-绘图 · 宝塔部署教程

## 环境要求

| 项目 | 要求 |
|------|------|
| 系统 | CentOS 7+ / Ubuntu 20+ / Debian 10+ |
| 宝塔 | 8.0+ |
| Nginx | 1.20+ |
| PHP | 8.1+（需启用 curl、pdo_sqlite、fileinfo 扩展） |
| 阿里云 OSS | （可选）已创建 Bucket，已获取 AccessKey |

---

## 方式一：Web 安装向导（推荐）

### 1. 宝塔创建站点

宝塔面板 → 网站 → 添加站点：
- 域名：`draw.example.com`
- PHP 版本：8.1 或更高
- 数据库：不需要

### 2. 上传文件

将项目文件上传到站点目录 `/www/wwwroot/draw.example.com/`。

### 3. 访问安装页面

浏览器打开：

```
https://draw.example.com/install.php
```

安装页面会自动：
- ✅ 检测 PHP 版本和扩展
- ✅ 提供「本地存储」和「阿里云 OSS」两种模式选择
- ✅ OSS 模式会实时验证连接
- ✅ 自动创建目录和配置文件
- ✅ 完成后自动锁定（生成 install.lock）

### 4. 配置伪静态（重要）

站点设置 → 伪静态，粘贴以下内容：

```nginx
location ^~ /runtime/ { deny all; return 403; }
location ~* (oss-config\.php|install\.php|install\.lock|\.example$) { deny all; return 403; }
location ~ \.php$ { fastcgi_read_timeout 600; fastcgi_send_timeout 600; }
```

### 5. 申请 SSL（推荐）

站点设置 → SSL → Let's Encrypt 一键申请。

---

## 方式二：命令行脚本部署

### 1. 上传项目

将项目上传到服务器任意位置（如 `/root/cang-draw`）。

### 2. 运行部署脚本

```bash
cd /root/cang-draw
bash deploy-bt.sh
```

脚本会询问站点域名和存储模式：

- **OSS Endpoint 留空回车** → 本地模式（图片存服务器）
- **填写 Endpoint** → 继续输入 AK/SK/Bucket 等 OSS 配置

### 3. 在宝塔面板完成配置

1. **创建站点**（如已创建则跳过） — PHP 版本选 8.1+
2. **配置伪静态** — 将 `nginx-rewrite.conf` 内容粘贴到站点的「伪静态」配置中
3. **申请 SSL** — 站点设置 → SSL → Let's Encrypt
4. **调整 PHP 超时** — 见下方"PHP 配置优化"

---

## 方式三：手动部署

### 1. 创建站点

在宝塔面板 → 网站 → 添加站点：
- 域名：`draw.example.com`
- 根目录：`/www/wwwroot/draw.example.com`
- PHP 版本：8.1 或更高
- 数据库：不需要

### 2. 上传文件

将以下文件上传到站点根目录：

```
├── .htaccess
├── 404.html
├── app-lib.php
├── doc.html
├── download.php
├── generate-task.php
├── index.html
├── list.php
├── oss-config.php.example
└── proxy-openai.php
```

### 3. 创建 OSS 配置

```bash
cd /www/wwwroot/draw.example.com
cp oss-config.php.example oss-config.php
```

编辑 `oss-config.php`，填入你的阿里云 OSS 配置：

```php
define('OSS_ENDPOINT', 'oss-cn-hangzhou.aliyuncs.com');
define('OSS_ACCESS_KEY_ID', '你的 AccessKey ID');
define('OSS_ACCESS_KEY_SECRET', '你的 AccessKey Secret');
define('OSS_BUCKET', '你的 Bucket 名');
define('OSS_PUBLIC_BASE_URL', 'https://oss.example.com');  // 或留空
define('OSS_KEY_PREFIX', 'cang-api-draw');
define('OSS_SIGNED_URL_EXPIRES', 3600);
```

### 4. 创建 runtime 目录并设置权限

```bash
cd /www/wwwroot/draw.example.com
mkdir -p runtime/generation-task-files runtime/images
chown -R www:www runtime
chmod -R 750 runtime
chown www:www oss-config.php
```

### 5. 配置 PHP 扩展

宝塔面板 → 软件商店 → PHP 8.1 → 安装扩展：

- ✅ `curl` — HTTP 请求
- ✅ `pdo_sqlite` — 任务数据库
- ✅ `fileinfo` — 图片类型检测

### 6. 配置伪静态

站点设置 → 伪静态，粘贴以下内容：

```nginx
# 禁止访问 runtime 目录
location ^~ /runtime/ {
    deny all;
    return 403;
}

# 禁止访问配置文件
location ~* (oss-config\.php|\.example$) {
    deny all;
    return 403;
}

# PHP 超时设置
location ~ \.php$ {
    fastcgi_read_timeout 600;
    fastcgi_send_timeout 600;
}
```

### 7. 申请 SSL 证书

站点设置 → SSL → Let's Encrypt → 申请（勾选域名） → 开启强制 HTTPS。

---

## PHP 配置优化

宝塔 → PHP 8.1 → 配置修改，找到并修改以下项：

```ini
max_execution_time = 600       ; 图片生成可能需要较长时间
upload_max_filesize = 10M      ; 允许上传参考图
post_max_size = 12M            ; 表单最大体积
memory_limit = 256M            ; 内存限制
```

修改后点击「服务」→「重启」。

---

## 阿里云 OSS 配置指南

### 创建 Bucket

1. 登录 [阿里云 OSS 控制台](https://oss.console.aliyun.com)
2. 创建 Bucket：
   - **区域**：选择离服务器近的区域
   - **存储类型**：标准存储
   - **读写权限**：公共读（推荐）或私有

### 获取 AccessKey

1. 访问 [AccessKey 管理](https://ram.console.aliyun.com/manage/ak)
2. 建议创建 RAM 子账号，仅授权 OSS 相关权限
3. 记录 AccessKey ID 和 Secret

### 绑定自定义域名（可选）

Bucket → 传输管理 → 域名管理 → 绑定域名。

绑定后将域名填入 `OSS_PUBLIC_BASE_URL`，图片即可通过自定义域名访问。

### 设置生命周期规则（可选）

如需自动清理旧图片：
Bucket → 基础设置 → 生命周期 → 创建规则：
- 前缀：`cang-api-draw/`
- 天数：按需设置（如 30 天后删除）

---

## 验证部署

1. 访问 `https://draw.example.com` 看到绘图界面
2. 在右上角保存你的 API Key
3. 输入提示词，点击生成
4. 检查 OSS Bucket 中是否出现生成的图片

---

## 常见问题

### 生成超时 / 502

PHP 超时时间不够。修改：
- PHP `max_execution_time = 600`
- Nginx `fastcgi_read_timeout 600`

### 图片流加载失败

检查 OSS 配置是否正确：
```bash
cd /www/wwwroot/draw.example.com
php -r "require 'app-lib.php'; app_require_oss_config(); echo 'OK';"
```

### runtime 目录无权限

```bash
chown -R www:www /www/wwwroot/draw.example.com/runtime
chmod -R 750 /www/wwwroot/draw.example.com/runtime
```

### 图片无法显示（私有 Bucket）

如果 Bucket 是私有的，不要设置 `OSS_PUBLIC_BASE_URL`，留空即可。系统会自动生成带签名的临时访问 URL。

### 反推提示词失败

反推功能通过前端将图片转为 Base64 发送给 API，无需上传到 OSS。
确保图片不超过 2MB，且 API Key 的模型支持视觉能力（gpt-5.4-mini）。
