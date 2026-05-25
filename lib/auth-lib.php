<?php
/**
 * CANG-AI 绘图 - AI 图像生成平台
 * Copyright (c) 2025 苍洱 (CANG-AI). All rights reserved.
 */

declare(strict_types=1);

const AUTH_DB_PATH = __DIR__ . '/../runtime/auth.sqlite';

function auth_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('PDO SQLite extension is not enabled.');
    }

    app_ensure_dir(__DIR__ . '/../runtime');
    
    $pdo = new PDO('sqlite:' . AUTH_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            credits INTEGER NOT NULL DEFAULT 0,
            balance REAL NOT NULL DEFAULT 0.0,
            invite_code TEXT UNIQUE,
            invited_by INTEGER DEFAULT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (invited_by) REFERENCES users(id)
        )'
    );
    
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS redeem_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            credits INTEGER NOT NULL DEFAULT 0,
            balance REAL NOT NULL DEFAULT 0.0,
            usage_limit INTEGER NOT NULL DEFAULT 1,
            used_count INTEGER NOT NULL DEFAULT 0,
            expires_at TEXT DEFAULT NULL,
            created_by INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )'
    );
    
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS redeem_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            code_id INTEGER NOT NULL,
            credits_added INTEGER NOT NULL DEFAULT 0,
            balance_added REAL NOT NULL DEFAULT 0.0,
            redeemed_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (code_id) REFERENCES redeem_codes(id)
        )'
    );
    
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )'
    );
    
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS system_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT NOT NULL UNIQUE,
            setting_value TEXT,
            setting_type TEXT NOT NULL DEFAULT "string",
            description TEXT,
            updated_at TEXT NOT NULL,
            updated_by INTEGER,
            FOREIGN KEY (updated_by) REFERENCES users(id)
        )'
    );
    
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_invite_code ON users(invite_code)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_redeem_codes_code ON redeem_codes(code)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_system_settings_key ON system_settings(setting_key)');
    
    auth_init_default_settings($pdo);
    
    return $pdo;
}

function auth_init_default_settings(PDO $pdo): void
{
    $defaults = [
        ['site_title', '网站标题', 'CANG-AI 绘图', 'string', 'SEO - 网站标题'],
        ['site_description', '网站描述', 'AI智能绘图平台，支持多种绘图模型', 'text', 'SEO - 网站描述'],
        ['site_keywords', '网站关键词', 'AI绘图,人工智能,图像生成', 'string', 'SEO - 网站关键词'],
        ['api_key', 'API密钥', '', 'password', '请前往 api.772.ee 购买并生成API密钥'],
        ['default_credits', '默认注册次数', '10', 'number', '新用户注册默认获得的次数'],
        ['default_balance', '默认注册余额', '0', 'number', '新用户注册默认获得的余额'],
        ['enable_registration', '开放注册', '1', 'boolean', '是否允许新用户注册'],
        ['require_invite_code', '需要邀请码', '0', 'boolean', '注册是否必须填写邀请码'],
    ];
    
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO system_settings (setting_key, setting_value, setting_type, description, updated_at) 
         VALUES (?, ?, ?, ?, ?)'
    );
    
    $now = gmdate('c');
    foreach ($defaults as [$key, $_, $value, $type, $desc]) {
        $stmt->execute([$key, $value, $type, $desc, $now]);
    }
}

function auth_generate_token(): string
{
    return bin2hex(random_bytes(32));
}

function auth_generate_invite_code(): string
{
    return strtoupper(bin2hex(random_bytes(4)));
}

function auth_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

function auth_verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function auth_create_session(int $userId): array
{
    $db = auth_db();
    $token = auth_generate_token();
    $expiresAt = gmdate('c', time() + 30 * 24 * 3600);
    $createdAt = gmdate('c');
    
    $stmt = $db->prepare(
        'INSERT INTO sessions (user_id, token, expires_at, created_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $token, $expiresAt, $createdAt]);
    
    return [
        'token' => $token,
        'expires_at' => $expiresAt,
    ];
}

function auth_get_user_by_token(string $token): ?array
{
    $db = auth_db();
    $stmt = $db->prepare(
        'SELECT u.* FROM users u 
         INNER JOIN sessions s ON u.id = s.user_id 
         WHERE s.token = ? AND s.expires_at > ?'
    );
    $stmt->execute([$token, gmdate('c')]);
    $user = $stmt->fetch();
    
    return $user ?: null;
}

function auth_delete_session(string $token): void
{
    $db = auth_db();
    $stmt = $db->prepare('DELETE FROM sessions WHERE token = ?');
    $stmt->execute([$token]);
}

function auth_get_user_by_email(string $email): ?array
{
    $db = auth_db();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    return $user ?: null;
}

function auth_create_user(string $name, string $email, string $password, ?string $inviteCode = null): array
{
    $db = auth_db();
    
    if (auth_get_user_by_email($email)) {
        throw new RuntimeException('邮箱已被注册');
    }
    
    $invitedBy = null;
    if ($inviteCode) {
        $stmt = $db->prepare('SELECT id FROM users WHERE invite_code = ?');
        $stmt->execute([$inviteCode]);
        $inviter = $stmt->fetch();
        if ($inviter) {
            $invitedBy = $inviter['id'];
        }
    }
    
    $passwordHash = auth_hash_password($password);
    $userInviteCode = auth_generate_invite_code();
    $createdAt = gmdate('c');
    
    $stmt = $db->prepare(
        'INSERT INTO users (name, email, password, invite_code, invited_by, created_at, updated_at) 
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$name, $email, $passwordHash, $userInviteCode, $invitedBy, $createdAt, $createdAt]);
    
    $userId = (int) $db->lastInsertId();
    
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    
    return $stmt->fetch();
}

function auth_update_user_credits(int $userId, int $credits, float $balance): void
{
    $db = auth_db();
    $stmt = $db->prepare(
        'UPDATE users SET credits = credits + ?, balance = balance + ?, updated_at = ? WHERE id = ?'
    );
    $stmt->execute([$credits, $balance, gmdate('c'), $userId]);
}

function auth_get_redeem_code(string $code): ?array
{
    $db = auth_db();
    $stmt = $db->prepare('SELECT * FROM redeem_codes WHERE code = ?');
    $stmt->execute([$code]);
    $result = $stmt->fetch();
    
    return $result ?: null;
}

function auth_redeem_code(int $userId, string $code): array
{
    $db = auth_db();
    $db->beginTransaction();
    
    try {
        $redeemCode = auth_get_redeem_code($code);
        
        if (!$redeemCode) {
            throw new RuntimeException('兑换码不存在');
        }
        
        if ($redeemCode['used_count'] >= $redeemCode['usage_limit']) {
            throw new RuntimeException('兑换码已达使用上限');
        }
        
        if ($redeemCode['expires_at'] && $redeemCode['expires_at'] < gmdate('c')) {
            throw new RuntimeException('兑换码已过期');
        }
        
        $stmt = $db->prepare(
            'SELECT COUNT(*) as count FROM redeem_logs WHERE user_id = ? AND code_id = ?'
        );
        $stmt->execute([$userId, $redeemCode['id']]);
        $log = $stmt->fetch();
        
        if ($log['count'] > 0) {
            throw new RuntimeException('您已经使用过此兑换码');
        }
        
        auth_update_user_credits($userId, (int)$redeemCode['credits'], (float)$redeemCode['balance']);
        
        $stmt = $db->prepare(
            'UPDATE redeem_codes SET used_count = used_count + 1 WHERE id = ?'
        );
        $stmt->execute([$redeemCode['id']]);
        
        $stmt = $db->prepare(
            'INSERT INTO redeem_logs (user_id, code_id, credits_added, balance_added, redeemed_at) 
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $redeemCode['id'],
            $redeemCode['credits'],
            $redeemCode['balance'],
            gmdate('c')
        ]);
        
        $db->commit();
        
        return [
            'credits' => (int)$redeemCode['credits'],
            'balance' => (float)$redeemCode['balance'],
        ];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function auth_create_redeem_codes(int $count, int $credits, float $balance, int $usageLimit, ?string $expiresAt, ?int $createdBy): array
{
    $db = auth_db();
    $codes = [];
    $createdAt = gmdate('c');
    
    $stmt = $db->prepare(
        'INSERT INTO redeem_codes (code, credits, balance, usage_limit, expires_at, created_by, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    
    for ($i = 0; $i < $count; $i++) {
        $code = auth_generate_token();
        $stmt->execute([$code, $credits, $balance, $usageLimit, $expiresAt, $createdBy, $createdAt]);
        $codes[] = $code;
    }
    
    return $codes;
}

function auth_format_user(array $user): array
{
    return [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'nickname' => $user['name'],
        'email' => $user['email'],
        'credits' => (int)$user['credits'],
        'balance' => (float)$user['balance'],
        'invite_code' => $user['invite_code'],
        'is_admin' => (int)$user['is_admin'] === 1,
        'created_at' => $user['created_at'],
    ];
}

function auth_require_admin(array $user): void
{
    if ((int)$user['is_admin'] !== 1) {
        http_response_code(403);
        echo json_encode(['error' => '需要管理员权限'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function auth_get_all_users(int $page = 1, int $perPage = 20): array
{
    $db = auth_db();
    $offset = ($page - 1) * $perPage;
    
    $stmt = $db->query('SELECT COUNT(*) as total FROM users');
    $total = $stmt->fetch()['total'];
    
    $stmt = $db->prepare(
        'SELECT * FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->execute([$perPage, $offset]);
    $users = $stmt->fetchAll();
    
    return [
        'total' => (int)$total,
        'page' => $page,
        'per_page' => $perPage,
        'users' => array_map('auth_format_user', $users),
    ];
}

function auth_get_all_redeem_codes(int $page = 1, int $perPage = 20): array
{
    $db = auth_db();
    $offset = ($page - 1) * $perPage;
    
    $stmt = $db->query('SELECT COUNT(*) as total FROM redeem_codes');
    $total = $stmt->fetch()['total'];
    
    $stmt = $db->prepare(
        'SELECT * FROM redeem_codes ORDER BY created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->execute([$perPage, $offset]);
    $codes = $stmt->fetchAll();
    
    return [
        'total' => (int)$total,
        'page' => $page,
        'per_page' => $perPage,
        'codes' => $codes,
    ];
}

function auth_update_user(int $userId, array $data): void
{
    $db = auth_db();
    $fields = [];
    $values = [];
    
    if (isset($data['credits'])) {
        $fields[] = 'credits = ?';
        $values[] = (int)$data['credits'];
    }
    
    if (isset($data['balance'])) {
        $fields[] = 'balance = ?';
        $values[] = (float)$data['balance'];
    }
    
    if (isset($data['is_admin'])) {
        $fields[] = 'is_admin = ?';
        $values[] = $data['is_admin'] ? 1 : 0;
    }
    
    if (empty($fields)) {
        return;
    }
    
    $fields[] = 'updated_at = ?';
    $values[] = gmdate('c');
    $values[] = $userId;
    
    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($values);
}

function auth_delete_user(int $userId): void
{
    $db = auth_db();
    $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$userId]);
}

function auth_delete_redeem_code(int $codeId): void
{
    $db = auth_db();
    $stmt = $db->prepare('DELETE FROM redeem_codes WHERE id = ?');
    $stmt->execute([$codeId]);
}

function auth_get_redeem_logs(int $page = 1, int $perPage = 20, ?int $userId = null, ?int $codeId = null): array
{
    $db = auth_db();
    $offset = ($page - 1) * $perPage;
    
    $where = [];
    $params = [];
    
    if ($userId !== null) {
        $where[] = 'rl.user_id = ?';
        $params[] = $userId;
    }
    
    if ($codeId !== null) {
        $where[] = 'rl.code_id = ?';
        $params[] = $codeId;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM redeem_logs rl $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    $stmt = $db->prepare(
        "SELECT rl.*, u.name as user_name, u.email as user_email, rc.code 
         FROM redeem_logs rl 
         LEFT JOIN users u ON rl.user_id = u.id 
         LEFT JOIN redeem_codes rc ON rl.code_id = rc.id 
         $whereClause
         ORDER BY rl.redeemed_at DESC 
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([...$params, $perPage, $offset]);
    $logs = $stmt->fetchAll();
    
    return [
        'total' => (int)$total,
        'page' => $page,
        'per_page' => $perPage,
        'logs' => $logs,
    ];
}

function auth_get_user_detail(int $userId): ?array
{
    $db = auth_db();
    
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        return null;
    }
    
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM redeem_logs WHERE user_id = ?');
    $stmt->execute([$userId]);
    $redeemCount = $stmt->fetch()['count'];
    
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM users WHERE invited_by = ?');
    $stmt->execute([$userId]);
    $invitedCount = $stmt->fetch()['count'];
    
    $stmt = $db->prepare(
        'SELECT rl.*, rc.code, rc.credits, rc.balance 
         FROM redeem_logs rl 
         LEFT JOIN redeem_codes rc ON rl.code_id = rc.id 
         WHERE rl.user_id = ? 
         ORDER BY rl.redeemed_at DESC 
         LIMIT 10'
    );
    $stmt->execute([$userId]);
    $recentRedeems = $stmt->fetchAll();
    
    return [
        'user' => auth_format_user($user),
        'redeem_count' => (int)$redeemCount,
        'invited_count' => (int)$invitedCount,
        'recent_redeems' => $recentRedeems,
    ];
}

function auth_search_users(string $keyword, int $page = 1, int $perPage = 20): array
{
    $db = auth_db();
    $offset = ($page - 1) * $perPage;
    $searchTerm = '%' . $keyword . '%';
    
    $stmt = $db->prepare(
        'SELECT COUNT(*) as total FROM users WHERE name LIKE ? OR email LIKE ?'
    );
    $stmt->execute([$searchTerm, $searchTerm]);
    $total = $stmt->fetch()['total'];
    
    $stmt = $db->prepare(
        'SELECT * FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->execute([$searchTerm, $searchTerm, $perPage, $offset]);
    $users = $stmt->fetchAll();
    
    return [
        'total' => (int)$total,
        'page' => $page,
        'per_page' => $perPage,
        'users' => array_map('auth_format_user', $users),
    ];
}

function auth_get_dashboard_stats(): array
{
    $db = auth_db();
    
    $stmt = $db->query('SELECT COUNT(*) as total FROM users');
    $totalUsers = $stmt->fetch()['total'];
    
    $stmt = $db->query('SELECT COUNT(*) as total FROM users WHERE is_admin = 1');
    $adminUsers = $stmt->fetch()['total'];
    
    $stmt = $db->query('SELECT COUNT(*) as total FROM redeem_codes');
    $totalCodes = $stmt->fetch()['total'];
    
    $stmt = $db->query('SELECT COUNT(*) as total FROM redeem_codes WHERE used_count >= usage_limit');
    $usedCodes = $stmt->fetch()['total'];
    
    $stmt = $db->query('SELECT COUNT(*) as total FROM redeem_codes WHERE expires_at IS NOT NULL AND expires_at < ?');
    $stmt->execute([gmdate('c')]);
    $expiredCodes = $stmt->fetch()['total'];
    
    $stmt = $db->query('SELECT SUM(credits) as total FROM users');
    $totalCredits = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $db->query('SELECT SUM(balance) as total FROM users');
    $totalBalance = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $db->query('SELECT COUNT(*) as total FROM redeem_logs');
    $totalRedeems = $stmt->fetch()['total'];
    
    $stmt = $db->query(
        'SELECT DATE(redeemed_at) as date, COUNT(*) as count 
         FROM redeem_logs 
         WHERE redeemed_at >= date("now", "-7 days") 
         GROUP BY DATE(redeemed_at) 
         ORDER BY date DESC'
    );
    $recentRedeems = $stmt->fetchAll();
    
    $stmt = $db->query(
        'SELECT DATE(created_at) as date, COUNT(*) as count 
         FROM users 
         WHERE created_at >= date("now", "-7 days") 
         GROUP BY DATE(created_at) 
         ORDER BY date DESC'
    );
    $recentUsers = $stmt->fetchAll();
    
    return [
        'total_users' => (int)$totalUsers,
        'admin_users' => (int)$adminUsers,
        'total_codes' => (int)$totalCodes,
        'used_codes' => (int)$usedCodes,
        'expired_codes' => (int)$expiredCodes,
        'available_codes' => (int)$totalCodes - (int)$usedCodes - (int)$expiredCodes,
        'total_credits' => (int)$totalCredits,
        'total_balance' => (float)$totalBalance,
        'total_redeems' => (int)$totalRedeems,
        'recent_redeems' => $recentRedeems,
        'recent_users' => $recentUsers,
    ];
}

function auth_get_setting(string $key, $default = null)
{
    $db = auth_db();
    $stmt = $db->prepare('SELECT setting_value, setting_type FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    
    if (!$result) {
        return $default;
    }
    
    $value = $result['setting_value'];
    $type = $result['setting_type'];
    
    switch ($type) {
        case 'number':
            return is_numeric($value) ? (strpos($value, '.') !== false ? (float)$value : (int)$value) : $default;
        case 'boolean':
            return $value === '1' || $value === 'true';
        case 'json':
            return json_decode($value, true) ?? $default;
        default:
            return $value;
    }
}

function auth_get_all_settings(): array
{
    $db = auth_db();
    $stmt = $db->query('SELECT * FROM system_settings ORDER BY setting_key');
    $settings = $stmt->fetchAll();
    
    $result = [];
    foreach ($settings as $setting) {
        $result[] = [
            'id' => (int)$setting['id'],
            'key' => $setting['setting_key'],
            'value' => $setting['setting_value'],
            'type' => $setting['setting_type'],
            'description' => $setting['description'],
            'updated_at' => $setting['updated_at'],
        ];
    }
    
    return $result;
}

function auth_update_setting(string $key, $value, ?int $updatedBy = null): void
{
    $db = auth_db();
    
    $stmt = $db->prepare('SELECT setting_type FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    
    if (!$result) {
        throw new RuntimeException('设置项不存在');
    }
    
    $type = $result['setting_type'];
    
    if ($type === 'boolean') {
        $value = $value ? '1' : '0';
    } elseif ($type === 'json') {
        $value = is_string($value) ? $value : json_encode($value);
    } else {
        $value = (string)$value;
    }
    
    $stmt = $db->prepare(
        'UPDATE system_settings SET setting_value = ?, updated_at = ?, updated_by = ? WHERE setting_key = ?'
    );
    $stmt->execute([$value, gmdate('c'), $updatedBy, $key]);
}

function auth_update_settings(array $settings, ?int $updatedBy = null): void
{
    foreach ($settings as $key => $value) {
        auth_update_setting($key, $value, $updatedBy);
    }
}

function auth_get_setting_value(string $key, $default = null)
{
    return auth_get_setting($key, $default);
}

function auth_update_user_password(int $userId, string $newPassword): void
{
    $db = auth_db();
    $hash = auth_hash_password($newPassword);
    $stmt = $db->prepare('UPDATE users SET password = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$hash, gmdate('c'), $userId]);
}

function auth_deduct_credits(int $userId, int $amount = 1): bool
{
    $db = auth_db();
    $stmt = $db->prepare('SELECT credits, balance FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) return false;

    if ((int)$user['credits'] >= $amount) {
        $stmt = $db->prepare('UPDATE users SET credits = credits - ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$amount, gmdate('c'), $userId]);
        return true;
    }
    return false;
}

function auth_log_action(int $userId, string $action, ?string $detail = null): void
{
    $db = auth_db();
    $db->exec(
        'CREATE TABLE IF NOT EXISTS action_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            detail TEXT,
            ip TEXT,
            created_at TEXT NOT NULL
        )'
    );
    $stmt = $db->prepare('INSERT INTO action_logs (user_id, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $action, $detail, $_SERVER['REMOTE_ADDR'] ?? '', gmdate('c')]);
}

function auth_get_action_logs(int $page = 1, int $perPage = 50): array
{
    $db = auth_db();
    $db->exec(
        'CREATE TABLE IF NOT EXISTS action_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            detail TEXT,
            ip TEXT,
            created_at TEXT NOT NULL
        )'
    );
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare(
        'SELECT l.*, u.name as user_name, u.email as user_email
         FROM action_logs l LEFT JOIN users u ON l.user_id = u.id
         ORDER BY l.created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->execute([$perPage, $offset]);
    $logs = $stmt->fetchAll();

    $countStmt = $db->query('SELECT COUNT(*) as total FROM action_logs');
    $total = (int)$countStmt->fetch()['total'];

    return ['logs' => $logs, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

function auth_count_login_failures(string $email, int $windowMinutes = 30): int
{
    $db = auth_db();
    $db->exec(
        'CREATE TABLE IF NOT EXISTS login_failures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            ip TEXT,
            created_at TEXT NOT NULL
        )'
    );
    $since = gmdate('c', time() - $windowMinutes * 60);
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM login_failures WHERE email = ? AND created_at > ?');
    $stmt->execute([$email, $since]);
    return (int)$stmt->fetch()['count'];
}

function auth_record_login_failure(string $email): void
{
    $db = auth_db();
    $db->exec(
        'CREATE TABLE IF NOT EXISTS login_failures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            ip TEXT,
            created_at TEXT NOT NULL
        )'
    );
    $stmt = $db->prepare('INSERT INTO login_failures (email, ip, created_at) VALUES (?, ?, ?)');
    $stmt->execute([$email, $_SERVER['REMOTE_ADDR'] ?? '', gmdate('c')]);
}

function auth_clear_login_failures(string $email): void
{
    $db = auth_db();
    try {
        $stmt = $db->prepare('DELETE FROM login_failures WHERE email = ?');
        $stmt->execute([$email]);
    } catch (Throwable $e) {
        // table may not exist yet
    }
}

function auth_export_redeem_codes(int $page = 1, int $perPage = 100, ?string $status = null): array
{
    $db = auth_db();
    $where = '1=1';
    $params = [];

    if ($status === 'unused') {
        $where .= ' AND used_count = 0';
    } elseif ($status === 'used') {
        $where .= ' AND used_count >= usage_limit';
    } elseif ($status === 'partial') {
        $where .= ' AND used_count > 0 AND used_count < usage_limit';
    }

    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare("SELECT * FROM redeem_codes WHERE {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $codes = $stmt->fetchAll();

    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM redeem_codes WHERE {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['total'];

    return ['codes' => $codes, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}
