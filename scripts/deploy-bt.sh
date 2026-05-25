#!/bin/bash
set -euo pipefail

# CANG-AI 绘图 · 宝塔一键部署脚本

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

info()  { echo -e "${CYAN}[INFO]${NC} $*"; }
ok()    { echo -e "${GREEN}[ OK ]${NC} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
err()   { echo -e "${RED}[ERR]${NC} $*"; exit 1; }

echo ""
echo -e "${CYAN}════════════════════════════════════════${NC}"
echo -e "${CYAN}   CANG-AI 绘图 · 宝塔一键部署${NC}"
echo -e "${CYAN}════════════════════════════════════════${NC}"
echo ""

# ─── 交互输入 ───
read -rp "$(echo -e ${YELLOW})站点域名 (如 draw.example.com): $(echo -e ${NC})" SITE_DOMAIN
[ -z "$SITE_DOMAIN" ] && err "域名不能为空"
SITE_DIR="/www/wwwroot/${SITE_DOMAIN}"
info "站点目录: $SITE_DIR"

echo ""
echo -e "${CYAN}存储模式:${NC}"
echo "  [1] 本地存储（默认，图片保存在服务器）"
echo "  [2] 阿里云 OSS"
echo "  [3] 腾讯云 COS"
echo "  [4] Cloudflare R2"
echo ""
read -rp "$(echo -e ${YELLOW})选择 [1]: $(echo -e ${NC})" STORAGE_MODE
STORAGE_MODE="${STORAGE_MODE:-1}"

# ─── 检查环境 ───
info "检查 PHP..."
PHP_BIN=""
for p in /www/server/php/8.*/bin/php /www/server/php/*/bin/php $(which php 2>/dev/null); do
    if [ -x "$p" ] 2>/dev/null; then PHP_BIN="$p"; break; fi
done
[ -z "$PHP_BIN" ] && err "找不到 PHP，请在宝塔安装 PHP 8.1+"
PHP_VER=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
info "PHP $PHP_VER ($PHP_BIN)"

for EXT in curl pdo_sqlite fileinfo; do
    if ! "$PHP_BIN" -m 2>/dev/null | grep -qi "^${EXT}$"; then
        warn "扩展 $EXT 未启用，请在宝塔 PHP 管理中开启"
    fi
done

# ─── 部署文件 ───
SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
info "源目录: $SCRIPT_DIR"
info "部署到: $SITE_DIR"

mkdir -p "$SITE_DIR"

rsync -av --delete \
    --exclude='runtime/' \
    --exclude='config/oss-config.php' \
    --exclude='config/r2-config.php' \
    --exclude='config/cos-config.php' \
    --exclude='.git/' \
    --exclude='.claude/' \
    --exclude='.playwright-mcp/' \
    --exclude='.DS_Store' \
    --exclude='*.zip' \
    "$SCRIPT_DIR/" "$SITE_DIR/"

ok "文件已同步"

# ─── 创建 runtime ───
mkdir -p "$SITE_DIR/runtime/generation-task-files" "$SITE_DIR/runtime/images"
WEB_USER="www"
if id "$WEB_USER" &>/dev/null; then
    chown -R "${WEB_USER}:${WEB_USER}" "$SITE_DIR/runtime"
    chmod -R 750 "$SITE_DIR/runtime"
    ok "runtime 权限已设置"
fi

# ─── 存储配置 ───
if [ "$STORAGE_MODE" = "1" ]; then
    ok "本地存储模式，无需额外配置"
elif [ "$STORAGE_MODE" = "2" ]; then
    read -rp "OSS Endpoint: " V_ENDPOINT
    read -rp "AccessKey ID: " V_AK_ID
    read -rsp "AccessKey Secret: " V_AK_SECRET; echo
    read -rp "Bucket: " V_BUCKET
    read -rp "公开域名 (留空用签名URL): " V_PUBLIC_URL
    cat > "$SITE_DIR/config/oss-config.php" << EOF
<?php
define('OSS_ENDPOINT', '${V_ENDPOINT}');
define('OSS_ACCESS_KEY_ID', '${V_AK_ID}');
define('OSS_ACCESS_KEY_SECRET', '${V_AK_SECRET}');
define('OSS_BUCKET', '${V_BUCKET}');
define('OSS_PUBLIC_BASE_URL', '${V_PUBLIC_URL}');
define('OSS_KEY_PREFIX', 'cang-ai-draw');
define('OSS_SIGNED_URL_EXPIRES', 3600);
EOF
    chown "${WEB_USER}:${WEB_USER}" "$SITE_DIR/config/oss-config.php" 2>/dev/null
    ok "OSS 配置已写入"
elif [ "$STORAGE_MODE" = "3" ]; then
    read -rp "COS Region (如 ap-guangzhou): " V_REGION
    read -rp "SecretId: " V_SID
    read -rsp "SecretKey: " V_SKEY; echo
    read -rp "Bucket: " V_BUCKET
    read -rp "公开域名 (留空用签名URL): " V_PUBLIC_URL
    cat > "$SITE_DIR/config/cos-config.php" << EOF
<?php
define('COS_REGION', '${V_REGION}');
define('COS_SECRET_ID', '${V_SID}');
define('COS_SECRET_KEY', '${V_SKEY}');
define('COS_BUCKET', '${V_BUCKET}');
define('COS_PUBLIC_BASE_URL', '${V_PUBLIC_URL}');
define('COS_KEY_PREFIX', 'cang-ai-draw');
define('COS_SIGNED_URL_EXPIRES', 3600);
EOF
    chown "${WEB_USER}:${WEB_USER}" "$SITE_DIR/config/cos-config.php" 2>/dev/null
    ok "COS 配置已写入"
elif [ "$STORAGE_MODE" = "4" ]; then
    read -rp "R2 Account ID: " V_ACCOUNT
    read -rp "Access Key ID: " V_AK_ID
    read -rsp "Access Key Secret: " V_AK_SECRET; echo
    read -rp "Bucket: " V_BUCKET
    read -rp "公开域名: " V_PUBLIC_URL
    cat > "$SITE_DIR/config/r2-config.php" << EOF
<?php
define('R2_ACCOUNT_ID', '${V_ACCOUNT}');
define('R2_ACCESS_KEY_ID', '${V_AK_ID}');
define('R2_ACCESS_KEY_SECRET', '${V_AK_SECRET}');
define('R2_BUCKET', '${V_BUCKET}');
define('R2_PUBLIC_BASE_URL', '${V_PUBLIC_URL}');
define('R2_KEY_PREFIX', 'cang-ai-draw');
define('R2_SIGNED_URL_EXPIRES', 3600);
EOF
    chown "${WEB_USER}:${WEB_USER}" "$SITE_DIR/config/r2-config.php" 2>/dev/null
    ok "R2 配置已写入"
fi

# ─── 完成提示 ───
echo ""
echo -e "${GREEN}════════════════════════════════════════${NC}"
echo -e "${GREEN}   部署完成！${NC}"
echo -e "${GREEN}════════════════════════════════════════${NC}"
echo ""
echo -e "接下来在宝塔面板完成："
echo ""
echo -e "  ${CYAN}1. 创建站点${NC} → 域名 ${SITE_DOMAIN}，PHP 8.1+"
echo -e "  ${CYAN}2. 伪静态${NC}   → 粘贴以下 Nginx 规则："
echo ""
cat << 'NGINX'
  location ^~ /runtime/ { deny all; return 403; }
  location ^~ /config/  { deny all; return 403; }
  location ^~ /lib/     { deny all; return 403; }
  location ^~ /scripts/ { deny all; return 403; }
NGINX
echo ""
echo -e "  ${CYAN}3. Nginx 配置${NC} → 站点设置 → 配置文件，在 PHP location 块中添加："
echo ""
cat << 'PHPCONF'
  # 在 location ~ [^/]\.php(/|$) { ... } 块内添加：
  fastcgi_split_path_info ^(.+?\.php)(/.*)$;
  set $path_info $fastcgi_path_info;
  fastcgi_param PATH_INFO $path_info;
  fastcgi_read_timeout 600;
  fastcgi_send_timeout 600;
PHPCONF
echo ""
echo -e "  ${CYAN}4. SSL${NC}      → Let's Encrypt 一键申请"
echo -e "  ${CYAN}5. PHP${NC}      → max_execution_time=600, upload_max_filesize=10M"
echo -e "  ${CYAN}6. 初始化${NC}   → 访问 https://${SITE_DOMAIN}/api/install.php"
echo ""
echo -e "  完成后访问 https://${SITE_DOMAIN} 即可使用"
echo ""
