#!/bin/bash
set -euo pipefail

# ============================================================
#  苍API-绘图 宝塔一键部署脚本
#  用法: bash deploy-bt.sh
#  前提: 宝塔已安装 Nginx + PHP 8.1+（需启用 curl/pdo_sqlite/fileinfo）
# ============================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

info()  { echo -e "${CYAN}[INFO]${NC} $*"; }
ok()    { echo -e "${GREEN}[OK]${NC} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
err()   { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

# ----------------------------- 交互输入 -----------------------------
echo ""
echo -e "${CYAN}========================================${NC}"
echo -e "${CYAN}   苍API-绘图 · 宝塔一键部署${NC}"
echo -e "${CYAN}========================================${NC}"
echo ""

read -rp "$(echo -e ${YELLOW})请输入站点域名 (如 draw.example.com): $(echo -e ${NC})" SITE_DOMAIN
[ -z "$SITE_DOMAIN" ] && err "域名不能为空"
SITE_DIR="/www/wwwroot/${SITE_DOMAIN}"
info "站点目录: $SITE_DIR"

USE_OSS="no"
OSS_ENDPOINT=""
OSS_AK_ID=""
OSS_AK_SECRET=""
OSS_BUCKET=""
OSS_PUBLIC_URL=""
OSS_PREFIX="cang-api-draw"

echo -e "${CYAN}存储模式:${NC}"
echo -e "  直接回车 = 本地存储（图片保存在服务器磁盘）"
echo -e "  填写配置 = 阿里云 OSS（图片上传到对象存储）"
echo ""
read -rp "$(echo -e ${YELLOW})OSS Endpoint (留空使用本地模式): $(echo -e ${NC})" OSS_ENDPOINT

if [ -n "$OSS_ENDPOINT" ]; then
    USE_OSS="yes"

    read -rp "$(echo -e ${YELLOW})OSS AccessKey ID: $(echo -e ${NC})" OSS_AK_ID
    [ -z "$OSS_AK_ID" ] && err "AccessKey ID 不能为空"

    read -rsp "$(echo -e ${YELLOW})OSS AccessKey Secret: $(echo -e ${NC})" OSS_AK_SECRET
    echo ""
    [ -z "$OSS_AK_SECRET" ] && err "AccessKey Secret 不能为空"

    read -rp "$(echo -e ${YELLOW})OSS Bucket 名称: $(echo -e ${NC})" OSS_BUCKET
    [ -z "$OSS_BUCKET" ] && err "Bucket 不能为空"

    read -rp "$(echo -e ${YELLOW})OSS 公开访问域名 (留空则使用签名URL): $(echo -e ${NC})" OSS_PUBLIC_URL

    read -rp "$(echo -e ${YELLOW})OSS Key 前缀 [cang-api-draw]: $(echo -e ${NC})" OSS_PREFIX
    OSS_PREFIX="${OSS_PREFIX:-cang-api-draw}"

    info "存储模式: 阿里云 OSS"
else
    info "存储模式: 本地存储"
fi

# ----------------------------- 检查环境 -----------------------------
info "检查 PHP 环境..."
PHP_BIN=$(which php 2>/dev/null || echo "")
if [ -z "$PHP_BIN" ]; then
    # 宝塔常见路径
    for p in /www/server/php/*/bin/php; do
        if [ -x "$p" ]; then PHP_BIN="$p"; break; fi
    done
fi
[ -z "$PHP_BIN" ] && err "找不到 PHP，请确认宝塔已安装 PHP"
PHP_VER=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
info "PHP 版本: $PHP_VER ($PHP_BIN)"

# 检查必要扩展
for EXT in curl pdo_sqlite fileinfo; do
    if ! "$PHP_BIN" -m 2>/dev/null | grep -qi "^${EXT}$"; then
        warn "PHP 扩展 $EXT 未启用，请在宝塔 PHP 管理中开启！"
    fi
done

# ----------------------------- 部署文件 -----------------------------
info "部署文件到 $SITE_DIR ..."
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

mkdir -p "$SITE_DIR"

# 复制项目文件（排除部署脚本自身、.git、runtime）
rsync -av --delete \
    --exclude='deploy-bt.sh' \
    --exclude='DEPLOY-BT.md' \
    --exclude='oss-config.php' \
    --exclude='runtime/' \
    --exclude='.git/' \
    --exclude='.DS_Store' \
    "$SCRIPT_DIR/" "$SITE_DIR/"

ok "文件已同步"

# ----------------------------- 生成 OSS 配置（仅 OSS 模式）-----------------------------
OSS_CONFIG="$SITE_DIR/oss-config.php"
if [ "$USE_OSS" = "yes" ]; then
    if [ -f "$OSS_CONFIG" ]; then
        warn "oss-config.php 已存在，将备份为 oss-config.php.bak"
        cp "$OSS_CONFIG" "${OSS_CONFIG}.bak"
    fi

    cat > "$OSS_CONFIG" << OSSEOF
<?php

declare(strict_types=1);

define('OSS_ENDPOINT', '${OSS_ENDPOINT}');
define('OSS_ACCESS_KEY_ID', '${OSS_AK_ID}');
define('OSS_ACCESS_KEY_SECRET', '${OSS_AK_SECRET}');
define('OSS_BUCKET', '${OSS_BUCKET}');
define('OSS_PUBLIC_BASE_URL', '${OSS_PUBLIC_URL}');
define('OSS_KEY_PREFIX', '${OSS_PREFIX}');
define('OSS_SIGNED_URL_EXPIRES', 3600);
OSSEOF

    ok "oss-config.php 已生成（OSS 模式）"
else
    # 本地模式不需要 oss-config.php，删除旧的（如有）
    if [ -f "$OSS_CONFIG" ]; then
        rm -f "$OSS_CONFIG"
        info "已移除旧的 oss-config.php（切换为本地模式）"
    fi
    ok "本地存储模式，无需 OSS 配置"
fi

# ----------------------------- 创建 runtime 目录 -----------------------------
RUNTIME_DIR="$SITE_DIR/runtime"
mkdir -p "$RUNTIME_DIR/generation-task-files"
mkdir -p "$RUNTIME_DIR/images"

# 设置权限 —— 宝塔默认 www 用户
WEB_USER="www"
if id "$WEB_USER" &>/dev/null; then
    chown -R "${WEB_USER}:${WEB_USER}" "$SITE_DIR/runtime"
    [ -f "$OSS_CONFIG" ] && chown "${WEB_USER}:${WEB_USER}" "$OSS_CONFIG"
    ok "目录权限已设为 $WEB_USER"
else
    warn "www 用户不存在，请手动设置 runtime 目录的权限"
fi

chmod -R 750 "$RUNTIME_DIR"

# ----------------------------- 生成 Nginx 伪静态规则 -----------------------------
NGINX_CONF="$SITE_DIR/nginx-rewrite.conf"
cat > "$NGINX_CONF" << 'NGINXEOF'
# 苍API-绘图 Nginx 伪静态规则
# 复制以下内容到宝塔站点「伪静态」配置中

# 禁止直接访问 runtime 目录
location ^~ /runtime/ {
    deny all;
    return 403;
}

# 禁止访问配置文件和安装文件
location ~* (oss-config\.php|install\.lock|\.example$) {
    deny all;
    return 403;
}

# PHP 超时设置（生成图片可能较慢）
location ~ \.php$ {
    fastcgi_read_timeout 600;
    fastcgi_send_timeout 600;
}
NGINXEOF

ok "Nginx 伪静态规则已生成: nginx-rewrite.conf"

# ----------------------------- 验证部署 -----------------------------
info "验证 PHP 配置..."
if [ "$USE_OSS" = "yes" ]; then
    TEST_RESULT=$("$PHP_BIN" -r "
    require '$SITE_DIR/app-lib.php';
    echo app_is_oss_enabled() ? 'OSS_OK' : 'OSS_FAIL';
    " 2>&1 || true)

    if echo "$TEST_RESULT" | grep -q "OSS_OK"; then
        ok "OSS 配置验证通过"
    else
        warn "OSS 配置验证失败: $TEST_RESULT"
        warn "请检查 oss-config.php 中的配置是否正确"
    fi
else
    TEST_RESULT=$("$PHP_BIN" -r "
    require '$SITE_DIR/app-lib.php';
    echo app_is_oss_enabled() ? 'OSS' : 'LOCAL';
    " 2>&1 || true)

    if echo "$TEST_RESULT" | grep -q "LOCAL"; then
        ok "本地存储模式验证通过"
    else
        warn "验证结果异常: $TEST_RESULT"
    fi
fi

# ----------------------------- 完成提示 -----------------------------
echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}   部署完成！${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo -e "站点目录:   ${CYAN}$SITE_DIR${NC}"
if [ "$USE_OSS" = "yes" ]; then
    echo -e "存储模式:   ${CYAN}阿里云 OSS${NC}"
else
    echo -e "存储模式:   ${CYAN}本地存储${NC}（图片在 runtime/images/）"
fi
echo ""
echo -e "${YELLOW}还需要在宝塔面板中完成以下操作:${NC}"
echo ""
echo -e "  1. ${CYAN}创建站点${NC}（如果还没创建）"
echo -e "     站点目录指向: $SITE_DIR"
echo -e "     PHP 版本选择 8.1 或更高"
echo ""
echo -e "  2. ${CYAN}PHP 扩展${NC}"
echo -e "     确保已启用: curl, pdo_sqlite, fileinfo"
echo ""
echo -e "  3. ${CYAN}伪静态配置${NC}"
echo -e "     打开站点设置 → 伪静态"
echo -e "     将 ${CYAN}nginx-rewrite.conf${NC} 的内容粘贴进去"
echo ""
echo -e "  4. ${CYAN}SSL 证书${NC}（推荐）"
echo -e "     站点设置 → SSL → Let's Encrypt 一键申请"
echo ""
echo -e "  5. ${CYAN}PHP 超时设置${NC}（可选）"
echo -e "     宝塔 → PHP 设置 → 配置修改"
echo -e "     max_execution_time = 600"
echo -e "     upload_max_filesize = 10M"
echo -e "     post_max_size = 12M"
echo ""
echo -e "  6. ${CYAN}防火墙${NC}"
echo -e "     确保 80/443 端口已放行"
echo ""
echo -e "完成后访问你的域名即可使用！"
echo ""
