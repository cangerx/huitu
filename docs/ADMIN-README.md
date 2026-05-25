# 管理系统使用说明

## 功能概述

本管理系统为 CANG-AI 绘图项目提供完整的用户管理和卡密系统，包括：

- ✅ 用户注册、登录、个人信息管理
- ✅ 卡密生成和管理
- ✅ 用户兑换卡密功能
- ✅ 管理后台（用户管理、卡密管理、数据统计）
- ✅ 次数和余额管理

## 文件说明

### 核心文件

- `lib/auth-lib.php` - 认证和数据库核心库
- `api/auth-api.php` - 用户认证API（登录、注册、兑换等）
- `api/admin-api.php` - 管理后台API
- `api/install.php` - 安装向导（含管理员初始化）
- `admin.html` - 管理后台界面
- `runtime/auth.sqlite` - 用户数据库（自动创建）

### 数据库表结构

#### users 表
- 用户基本信息
- 次数（credits）和余额（balance）
- 邀请码系统
- 管理员权限标识

#### redeem_codes 表
- 卡密信息
- 次数和余额配置
- 使用次数限制
- 过期时间

#### redeem_logs 表
- 兑换记录
- 用户兑换历史

#### sessions 表
- 用户登录会话
- Token 管理

## 安装步骤

### 1. 初始化管理员账号

访问安装向导创建第一个管理员账号：

```
https://your-domain.com/api/install.php
```

填写：
- 用户名
- 邮箱
- 密码（至少6位）

创建成功后会生成 `runtime/install.lock` 锁定文件，防止重复初始化。

### 2. 登录系统

在 `index.html` 主页点击"登录"按钮，使用管理员账号登录。

### 3. 访问管理后台

登录后，访问 `admin.html` 进入管理后台：

```
http://your-domain.com/admin.html
```

## 管理后台功能

### 用户管理

- 查看所有用户列表
- 编辑用户次数和余额
- 设置管理员权限
- 删除用户

### 卡密管理

#### 生成卡密

点击"生成卡密"按钮，配置：

- **生成数量**：1-1000个
- **次数**：每个卡密包含的次数
- **余额**：每个卡密包含的余额（元）
- **使用次数限制**：每个卡密可以被使用的次数（默认1次）
- **过期时间**：可选，留空则永久有效

生成后会显示所有卡密码，可以复制分发给用户。

#### 卡密列表

- 查看所有卡密
- 显示使用状态
- 删除无效卡密

### 数据统计

首页显示：
- 总用户数
- 总卡密数
- 已使用卡密数
- 系统总次数
- 系统总余额

## 用户功能

### 注册

用户可以在主页注册账号：
- 用户名
- 邮箱
- 密码
- 邀请码（可选）

### 登录

使用邮箱和密码登录。

### 兑换卡密

登录后在"我的账号"页面：
1. 输入64位卡密
2. 点击"兑换"
3. 次数和余额自动增加

### 查看信息

- 剩余次数
- 账户余额
- 我的邀请码（可分享给他人）

## API 接口说明

### 用户认证 API (api/auth-api.php)

#### POST /api/auth-api.php/register
注册新用户

请求体：
```json
{
  "name": "用户名",
  "email": "email@example.com",
  "password": "密码",
  "password_confirmation": "确认密码",
  "invite_code": "邀请码（可选）"
}
```

#### POST /api/auth-api.php/login
用户登录

请求体：
```json
{
  "email": "email@example.com",
  "password": "密码"
}
```

#### GET /api/auth-api.php/me
获取当前用户信息

需要 Authorization 头：`Bearer {token}`

#### POST /api/auth-api.php/redeem
兑换卡密

请求体：
```json
{
  "code": "64位卡密"
}
```

#### POST /api/auth-api.php/logout
退出登录

### 管理后台 API (api/admin-api.php)

所有接口都需要管理员权限，需要 Authorization 头：`Bearer {token}`

#### GET /api/admin-api.php/stats
获取统计数据

#### GET /api/admin-api.php/users
获取用户列表

参数：
- `page`: 页码（默认1）
- `per_page`: 每页数量（默认20）

#### PUT /api/admin-api.php/users/{id}
更新用户信息

请求体：
```json
{
  "credits": 100,
  "balance": 50.00,
  "is_admin": false
}
```

#### DELETE /api/admin-api.php/users/{id}
删除用户

#### GET /api/admin-api.php/redeem-codes
获取卡密列表

参数：
- `page`: 页码
- `per_page`: 每页数量

#### POST /api/admin-api.php/redeem-codes
生成卡密

请求体：
```json
{
  "count": 10,
  "credits": 100,
  "balance": 50.00,
  "usage_limit": 1,
  "expires_at": "2026-12-31T23:59:59+00:00"
}
```

#### DELETE /api/admin-api.php/redeem-codes/{id}
删除卡密

## 安全建议

1. **修改默认配置**：首次安装后立即创建管理员账号
2. **使用强密码**：管理员密码建议使用复杂密码
3. **定期备份**：定期备份 `runtime/auth.sqlite` 数据库
4. **HTTPS**：生产环境建议使用 HTTPS
5. **权限控制**：确保 `runtime` 目录有写入权限但不可直接访问

## 常见问题

### 如何重置管理员密码？

删除 `runtime/install.lock` 和 `runtime/auth.sqlite`，重新访问 `/api/install.php`。

### 卡密格式是什么？

卡密是64位十六进制字符串，由系统自动生成。

### 如何批量导出卡密？

生成卡密后，在弹窗中会显示所有卡密，可以复制保存到文本文件。

### 用户忘记密码怎么办？

管理员可以在后台编辑用户信息，或者删除用户让其重新注册。

## 技术栈

- PHP 8.1+
- SQLite 3
- 原生 JavaScript
- 无需额外依赖

## 许可证

与主项目保持一致
