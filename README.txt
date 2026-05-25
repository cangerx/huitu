================================================================
  CANG-AI 绘图 - AI 图像生成平台
  版本: 1.0.0
  作者: 苍洱 (CANG-AI)
  网站: https://772.ee
  Copyright (c) 2025 苍洱. All rights reserved.
================================================================

一、系统要求
────────────────────────────────────────
- PHP 8.1+（需启用 curl, pdo_sqlite, fileinfo, mbstring 扩展）
- Nginx + PHP-FPM（推荐宝塔面板）
- exec() 函数可用（用于后台生成图片，非必须）
- 磁盘空间 ≥ 1GB（存储生成的图片）

二、目录结构
────────────────────────────────────────
├── index.html          # 主页（AI 绘图界面）
├── explore.html        # 探索页（公开作品展示）
├── admin-login.html    # 管理后台登录
├── admin.html          # 管理后台
├── api/                # 后端 API
│   ├── install.php         # 安装向导（首次部署访问此页面）
│   ├── auth-api.php        # 用户认证
│   ├── admin-api.php       # 管理接口
│   ├── generate-task.php   # 图片生成任务
│   ├── proxy-openai.php    # OpenAI API 代理
│   ├── list.php            # 图片列表
│   ├── download.php        # 图片下载
│   └── explore-api.php     # 探索页接口
├── lib/                # 核心库（禁止 Web 访问）
├── config/             # 配置文件（禁止 Web 访问）
├── runtime/            # 运行时数据（禁止 Web 访问）
├── scripts/            # 部署脚本（禁止 Web 访问）
└── docs/               # 文档

三、部署步骤（宝塔面板）
────────────────────────────────────────

1. 创建站点
   - 宝塔面板 → 网站 → 添加站点
   - 域名填写你的域名，PHP 版本选 8.1+
   - 将本源码包上传到站点根目录

2. 配置伪静态
   站点设置 → 伪静态，粘贴：

   location ^~ /runtime/ { deny all; return 403; }
   location ^~ /config/  { deny all; return 403; }
   location ^~ /lib/     { deny all; return 403; }
   location ^~ /scripts/ { deny all; return 403; }

3. 修改 Nginx 配置（支持 PATH_INFO）
   站点设置 → 配置文件，找到 PHP 的 location 块，
   将 location ~ \.php$ 改为：

   location ~ [^/]\.php(/|$) {

   并在该块内添加：

   fastcgi_split_path_info ^(.+?\.php)(/.*)$;
   set $path_info $fastcgi_path_info;
   fastcgi_param PATH_INFO $path_info;
   fastcgi_read_timeout 600;
   fastcgi_send_timeout 600;

4. 申请 SSL 证书
   站点设置 → SSL → Let's Encrypt → 申请

5. 修改 PHP 配置
   软件商店 → PHP 设置 → 配置修改：
   - max_execution_time = 600
   - upload_max_filesize = 10M
   - post_max_size = 12M

   禁用函数中移除 exec（如需后台生成）

6. 访问安装向导
   浏览器打开: https://你的域名/api/install.php
   按提示完成配置（API Key、存储方式等）

7. 完成
   访问 https://你的域名 即可使用

四、功能说明
────────────────────────────────────────
- AI 绘图：支持 GPT-Image-1、DALL-E 3 等模型
- 用户系统：注册/登录、积分管理、兑换码
- 管理后台：用户管理、任务监控、系统设置
- 存储方式：本地存储 / 阿里云 OSS / Cloudflare R2 / 腾讯云 COS
- 图片尺寸：支持多种比例和 4K 分辨率

五、常见问题
────────────────────────────────────────
Q: API 路由返回 404？
A: 需要配置 Nginx PATH_INFO 支持，参见步骤 3。

Q: 图片生成超时？
A: 确保 PHP max_execution_time 和 Nginx fastcgi_read_timeout 都设为 600。

Q: 如何重新安装？
A: 删除 runtime/install.lock 文件后重新访问安装页面。

Q: 管理后台无法登录？
A: 确认已在安装向导中创建管理员账号。

六、技术支持
────────────────────────────────────────
作者: 苍洱
网站: https://772.ee

================================================================
  Copyright (c) 2025 苍洱 (CANG-AI). All rights reserved.
================================================================
