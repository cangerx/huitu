<?php
/**
 * CANG-AI 绘图 - 管理后台 API
 * Copyright (c) 2025 苍洱 (CANG-AI). All rights reserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/app-lib.php';
require_once __DIR__ . '/../lib/auth-lib.php';

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function admin_api_response(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_api_error(int $code, string $message): void
{
    admin_api_response($code, ['error' => $message]);
}

function admin_api_validate_pagination(int $page, int $perPage): array
{
    $page = max(1, min($page, 10000));
    $perPage = max(1, min($perPage, 100));
    return [$page, $perPage];
}

function admin_api_get_current_user(): ?array
{
    $authorization = app_get_authorization_header();
    if (!$authorization || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return null;
    }
    
    $token = trim($matches[1]);
    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        return null;
    }
    
    return auth_get_user_by_token($token);
}

function admin_api_require_admin(): array
{
    $user = admin_api_get_current_user();
    if (!$user) {
        admin_api_error(401, '未登录或登录已过期');
    }
    
    if ((int)$user['is_admin'] !== 1) {
        admin_api_error(403, '需要管理员权限');
    }
    
    return $user;
}

$path = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($path, PHP_URL_PATH);
$path = preg_replace('#^(/api)?/admin-api\.php#', '', $path);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($path === '/users' && $method === 'GET') {
        admin_api_require_admin();
        
        $page = (int)($_GET['page'] ?? 1);
        $perPage = (int)($_GET['per_page'] ?? 20);
        [$page, $perPage] = admin_api_validate_pagination($page, $perPage);
        
        $result = auth_get_all_users($page, $perPage);
        
        admin_api_response(200, $result);
    }
    
    if (preg_match('#^/users/(\d+)$#', $path, $matches) && $method === 'PUT') {
        $admin = admin_api_require_admin();

        $userId = (int)$matches[1];
        if ($userId <= 0) {
            admin_api_error(400, '无效的用户ID');
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        if (isset($input['credits']) && (!is_numeric($input['credits']) || $input['credits'] < 0)) {
            admin_api_error(400, '次数必须是非负整数');
        }

        if (isset($input['balance']) && (!is_numeric($input['balance']) || $input['balance'] < 0)) {
            admin_api_error(400, '余额必须是非负数');
        }

        auth_update_user($userId, $input);
        auth_log_action((int)$admin['id'], 'update_user', "user_id={$userId}");

        admin_api_response(200, ['message' => '更新成功']);
    }

    if (preg_match('#^/users/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        $admin = admin_api_require_admin();

        $userId = (int)$matches[1];
        if ($userId <= 0) {
            admin_api_error(400, '无效的用户ID');
        }

        auth_delete_user($userId);
        auth_log_action((int)$admin['id'], 'delete_user', "user_id={$userId}");

        admin_api_response(200, ['message' => '删除成功']);
    }
    
    if ($path === '/redeem-codes' && $method === 'GET') {
        admin_api_require_admin();
        
        $page = (int)($_GET['page'] ?? 1);
        $perPage = (int)($_GET['per_page'] ?? 20);
        [$page, $perPage] = admin_api_validate_pagination($page, $perPage);
        
        $result = auth_get_all_redeem_codes($page, $perPage);
        
        admin_api_response(200, $result);
    }
    
    if ($path === '/redeem-codes' && $method === 'POST') {
        $admin = admin_api_require_admin();
        
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        
        $count = (int)($input['count'] ?? 1);
        $credits = (int)($input['credits'] ?? 0);
        $balance = (float)($input['balance'] ?? 0.0);
        $usageLimit = (int)($input['usage_limit'] ?? 1);
        $expiresAt = !empty($input['expires_at']) ? $input['expires_at'] : null;
        
        if ($count < 1 || $count > 1000) {
            admin_api_error(400, '生成数量必须在1-1000之间');
        }
        
        if ($credits < 0 || $credits > 1000000) {
            admin_api_error(400, '次数必须在0-1000000之间');
        }
        
        if ($balance < 0 || $balance > 1000000) {
            admin_api_error(400, '余额必须在0-1000000之间');
        }
        
        if ($usageLimit < 1 || $usageLimit > 10000) {
            admin_api_error(400, '使用次数限制必须在1-10000之间');
        }
        
        if ($expiresAt && !strtotime($expiresAt)) {
            admin_api_error(400, '过期时间格式不正确');
        }
        
        $codes = auth_create_redeem_codes(
            $count,
            $credits,
            $balance,
            $usageLimit,
            $expiresAt,
            (int)$admin['id']
        );

        auth_log_action((int)$admin['id'], 'generate_codes', "count={$count},credits={$credits},balance={$balance}");

        admin_api_response(200, [
            'message' => '生成成功',
            'count' => count($codes),
            'codes' => $codes,
        ]);
    }
    
    if (preg_match('#^/redeem-codes/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        admin_api_require_admin();
        
        $codeId = (int)$matches[1];
        if ($codeId <= 0) {
            admin_api_error(400, '无效的卡密ID');
        }
        
        auth_delete_redeem_code($codeId);
        
        admin_api_response(200, ['message' => '删除成功']);
    }
    
    if ($path === '/stats' && $method === 'GET') {
        admin_api_require_admin();
        
        $stats = auth_get_dashboard_stats();
        
        admin_api_response(200, $stats);
    }
    
    if ($path === '/redeem-logs' && $method === 'GET') {
        admin_api_require_admin();
        
        $page = (int)($_GET['page'] ?? 1);
        $perPage = (int)($_GET['per_page'] ?? 20);
        [$page, $perPage] = admin_api_validate_pagination($page, $perPage);
        
        $userId = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;
        $codeId = !empty($_GET['code_id']) ? (int)$_GET['code_id'] : null;
        
        if ($userId !== null && $userId <= 0) {
            admin_api_error(400, '无效的用户ID');
        }
        
        if ($codeId !== null && $codeId <= 0) {
            admin_api_error(400, '无效的卡密ID');
        }
        
        $result = auth_get_redeem_logs($page, $perPage, $userId, $codeId);
        
        admin_api_response(200, $result);
    }
    
    if (preg_match('#^/users/(\d+)/detail$#', $path, $matches) && $method === 'GET') {
        admin_api_require_admin();
        
        $userId = (int)$matches[1];
        if ($userId <= 0) {
            admin_api_error(400, '无效的用户ID');
        }
        
        $detail = auth_get_user_detail($userId);
        
        if (!$detail) {
            admin_api_error(404, '用户不存在');
        }
        
        admin_api_response(200, $detail);
    }
    
    if ($path === '/users/search' && $method === 'GET') {
        admin_api_require_admin();
        
        $keyword = trim($_GET['keyword'] ?? '');
        if (empty($keyword)) {
            admin_api_error(400, '请输入搜索关键词');
        }
        
        if (mb_strlen($keyword) > 100) {
            admin_api_error(400, '搜索关键词过长');
        }
        
        $page = (int)($_GET['page'] ?? 1);
        $perPage = (int)($_GET['per_page'] ?? 20);
        [$page, $perPage] = admin_api_validate_pagination($page, $perPage);
        
        $result = auth_search_users($keyword, $page, $perPage);
        
        admin_api_response(200, $result);
    }
    
    if ($path === '/settings' && $method === 'GET') {
        admin_api_require_admin();
        
        $settings = auth_get_all_settings();
        
        admin_api_response(200, ['settings' => $settings]);
    }
    
    if ($path === '/settings' && $method === 'PUT') {
        $admin = admin_api_require_admin();

        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        if (empty($input)) {
            admin_api_error(400, '请提供要更新的设置');
        }

        auth_update_settings($input, (int)$admin['id']);
        auth_log_action((int)$admin['id'], 'update_settings', json_encode(array_keys($input)));

        admin_api_response(200, ['message' => '设置已更新']);
    }

    if ($path === '/tasks' && $method === 'GET') {
        admin_api_require_admin();

        $page = (int)($_GET['page'] ?? 1);
        $perPage = (int)($_GET['per_page'] ?? 20);
        [$page, $perPage] = admin_api_validate_pagination($page, $perPage);
        $status = trim($_GET['status'] ?? '');

        $db = app_task_db();
        $where = '1=1';
        $params = [];
        if ($status && in_array($status, ['queued', 'processing', 'completed', 'failed'], true)) {
            $where .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) as total FROM generation_tasks WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetch()['total'];

        $offset = ($page - 1) * $perPage;
        $stmt = $db->prepare(
            "SELECT task_id, status, mode, model, prompt, size, quality, count, created_at, updated_at, completed_at, message, error
             FROM generation_tasks WHERE {$where} ORDER BY datetime(created_at) DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $tasks = $stmt->fetchAll();

        admin_api_response(200, [
            'tasks' => $tasks,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    if (preg_match('#^/tasks/([a-f0-9]{32})$#', $path, $m) && $method === 'DELETE') {
        $admin = admin_api_require_admin();
        $taskId = $m[1];

        $db = app_task_db();
        $stmt = $db->prepare('DELETE FROM generation_tasks WHERE task_id = ?');
        $stmt->execute([$taskId]);

        if ($stmt->rowCount() === 0) {
            admin_api_error(404, '任务不存在');
        }

        auth_log_action((int)$admin['id'], 'delete_task', $taskId);
        admin_api_response(200, ['message' => '任务已删除']);
    }

    if (preg_match('#^/tasks/([a-f0-9]{32})$#', $path, $m) && $method === 'GET') {
        admin_api_require_admin();
        $taskId = $m[1];

        $task = app_read_generation_task($taskId);
        admin_api_response(200, ['task' => $task]);
    }

    if ($path === '/codes/export' && $method === 'GET') {
        admin_api_require_admin();

        $page = (int)($_GET['page'] ?? 1);
        $perPage = (int)($_GET['per_page'] ?? 100);
        [$page, $perPage] = admin_api_validate_pagination($page, $perPage);
        $status = trim($_GET['status'] ?? '');

        $result = auth_export_redeem_codes($page, $perPage, $status ?: null);
        admin_api_response(200, $result);
    }

    if ($path === '/logs' && $method === 'GET') {
        admin_api_require_admin();

        $page = (int)($_GET['page'] ?? 1);
        $perPage = (int)($_GET['per_page'] ?? 50);
        [$page, $perPage] = admin_api_validate_pagination($page, $perPage);

        $result = auth_get_action_logs($page, $perPage);
        admin_api_response(200, $result);
    }

    if ($path === '/stats/tasks' && $method === 'GET') {
        admin_api_require_admin();

        $db = app_task_db();
        $stats = [];
        $stmt = $db->query("SELECT status, COUNT(*) as count FROM generation_tasks GROUP BY status");
        while ($row = $stmt->fetch()) {
            $stats[$row['status']] = (int)$row['count'];
        }

        $todayStart = gmdate('Y-m-d') . 'T00:00:00+00:00';
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM generation_tasks WHERE created_at >= ?");
        $stmt->execute([$todayStart]);
        $stats['today'] = (int)$stmt->fetch()['count'];

        admin_api_response(200, ['stats' => $stats]);
    }

    admin_api_error(404, '接口不存在');

} catch (RuntimeException $e) {
    admin_api_error(400, $e->getMessage());
} catch (Exception $e) {
    error_log('Admin API Error: ' . $e->getMessage());
    admin_api_error(500, '服务器错误');
}
