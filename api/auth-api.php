<?php
/**
 * CANG-AI 绘图 - 用户认证 API
 * Copyright (c) 2025 苍洱 (CANG-AI). All rights reserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/app-lib.php';
require_once __DIR__ . '/../lib/auth-lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function auth_api_response(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function auth_api_error(int $code, string $message): void
{
    auth_api_response($code, ['error' => $message]);
}

function auth_api_validate_input(string $name, int $minLength = 1, int $maxLength = 255): bool
{
    $length = mb_strlen($name);
    return $length >= $minLength && $length <= $maxLength;
}

function auth_api_get_current_user(): ?array
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

function auth_api_require_auth(): array
{
    $user = auth_api_get_current_user();
    if (!$user) {
        auth_api_error(401, '未登录或登录已过期');
    }
    return $user;
}

$path = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($path, PHP_URL_PATH);
$path = preg_replace('#^(/api)?/auth-api\.php#', '', $path);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($path === '/register' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $passwordConfirm = $input['password_confirmation'] ?? '';
        $inviteCode = trim($input['invite_code'] ?? '');
        
        if (!auth_api_validate_input($name, 2, 50)) {
            auth_api_error(400, '用户名长度必须在2-50个字符之间');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            auth_api_error(400, '邮箱格式不正确');
        }
        
        if (strlen($email) > 100) {
            auth_api_error(400, '邮箱长度不能超过100个字符');
        }
        
        if (strlen($password) < 6 || strlen($password) > 100) {
            auth_api_error(400, '密码长度必须在6-100个字符之间');
        }
        
        if ($password !== $passwordConfirm) {
            auth_api_error(400, '两次密码不一致');
        }
        
        if ($inviteCode && strlen($inviteCode) !== 8) {
            auth_api_error(400, '邀请码格式不正确');
        }
        
        $user = auth_create_user($name, $email, $password, $inviteCode ?: null);
        $session = auth_create_session((int)$user['id']);
        
        auth_api_response(200, [
            'message' => '注册成功',
            'token' => $session['token'],
            'user' => auth_format_user($user),
        ]);
    }
    
    if ($path === '/login' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            auth_api_error(400, '请填写邮箱和密码');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            auth_api_error(400, '邮箱格式不正确');
        }

        $failures = auth_count_login_failures($email);
        if ($failures >= 5) {
            auth_api_error(429, '登录失败次数过多，请30分钟后再试');
        }

        $user = auth_get_user_by_email($email);
        if (!$user || !auth_verify_password($password, $user['password'])) {
            auth_record_login_failure($email);
            auth_api_error(401, '邮箱或密码错误');
        }

        auth_clear_login_failures($email);
        $session = auth_create_session((int)$user['id']);

        auth_api_response(200, [
            'message' => '登录成功',
            'token' => $session['token'],
            'user' => auth_format_user($user),
        ]);
    }
    
    if ($path === '/logout' && $method === 'POST') {
        $authorization = app_get_authorization_header();
        if ($authorization && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            auth_delete_session(trim($matches[1]));
        }
        
        auth_api_response(200, ['message' => '退出成功']);
    }
    
    if ($path === '/me' && $method === 'GET') {
        $user = auth_api_require_auth();
        
        auth_api_response(200, [
            'user' => auth_format_user($user),
        ]);
    }
    
    if ($path === '/redeem' && $method === 'POST') {
        $user = auth_api_require_auth();
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        $code = trim($input['code'] ?? '');

        if (!$code || strlen($code) !== 64) {
            auth_api_error(400, '请输入正确的兑换码');
        }

        $result = auth_redeem_code((int)$user['id'], $code);

        $updatedUser = auth_get_user_by_email($user['email']);

        auth_api_response(200, [
            'message' => '兑换成功',
            'added_credits' => $result['credits'],
            'added_balance' => $result['balance'],
            'user' => auth_format_user($updatedUser),
        ]);
    }

    if ($path === '/apps/image-gen/generate' && $method === 'POST') {
        $user = auth_api_require_auth();

        if ((int)$user['credits'] <= 0 && (float)$user['balance'] <= 0) {
            auth_api_error(403, '次数不足，请充值或兑换卡密');
        }

        $systemApiKey = auth_get_setting_value('api_key');
        if (!$systemApiKey) {
            auth_api_error(500, '系统未配置API密钥，请联系管理员');
        }

        $_POST['api_key'] = $systemApiKey;

        $task = app_create_generation_task($_POST, $_FILES);
        $dispatched = app_start_generation_worker((string)$task['task_id']);

        // 扣减次数
        auth_update_user_credits((int)$user['id'], -1, 0.0);
        $updatedUser = auth_get_user_by_email($user['email']);

        auth_api_response(202, [
            'ok' => true,
            'task_id' => $task['task_id'],
            'status' => $task['status'],
            'message' => $dispatched ? '任务已创建' : '任务已创建，稍后处理',
            'worker_mode' => $dispatched ? 'exec' : 'after_response',
            'user' => auth_format_user($updatedUser),
        ]);

        if (!$dispatched) {
            app_continue_generation_after_response((string)$task['task_id']);
        }
    }

    if ($path === '/apps/image-gen/retry' && $method === 'POST') {
        $user = auth_api_require_auth();
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $taskId = trim($input['task_id'] ?? '');

        if (!$taskId || !preg_match('/\A[a-f0-9]{32}\z/', $taskId)) {
            auth_api_error(400, '无效的任务ID');
        }

        $db = app_task_db();
        $stmt = $db->prepare("SELECT * FROM generation_tasks WHERE task_id = ? AND status = 'failed'");
        $stmt->execute([$taskId]);
        $oldTask = $stmt->fetch();

        if (!$oldTask) {
            auth_api_error(404, '任务不存在或不可重试');
        }

        $newTaskId = bin2hex(random_bytes(16));
        $now = gmdate('c');
        $stmt = $db->prepare(
            'INSERT INTO generation_tasks (task_id, status, mode, model, prompt, size, quality, count, is_public, input_count, retention_hours, created_at, updated_at, completed_at, message, error, items_json, owner_hash, api_key, files_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $newTaskId, 'queued', $oldTask['mode'], $oldTask['model'], $oldTask['prompt'],
            $oldTask['size'], $oldTask['quality'], $oldTask['count'], $oldTask['is_public'],
            $oldTask['input_count'], $oldTask['retention_hours'], $now, $now,
            '[]', $oldTask['owner_hash'], $oldTask['api_key'], $oldTask['files_json'],
        ]);

        $dispatched = app_start_generation_worker($newTaskId);

        auth_api_response(202, [
            'ok' => true,
            'task_id' => $newTaskId,
            'status' => 'queued',
        ]);

        if (!$dispatched) {
            app_continue_generation_after_response($newTaskId);
        }
    }

    if ($path === '/apps/image-gen/refund' && $method === 'POST') {
        $user = auth_api_require_auth();
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $taskId = trim($input['task_id'] ?? '');

        if (!$taskId || !preg_match('/\A[a-f0-9]{32}\z/', $taskId)) {
            auth_api_error(400, '无效的任务ID');
        }

        $db = app_task_db();
        $stmt = $db->prepare("SELECT task_id FROM generation_tasks WHERE task_id = ? AND status = 'failed'");
        $stmt->execute([$taskId]);
        if (!$stmt->fetch()) {
            auth_api_error(404, '任务不存在或不可退款');
        }

        auth_update_user_credits((int)$user['id'], 1, 0.0);
        $updatedUser = auth_get_user_by_email($user['email']);

        auth_api_response(200, [
            'ok' => true,
            'message' => '已退还1次额度',
            'user' => auth_format_user($updatedUser),
        ]);
    }

    if ($path === '/me/password' && $method === 'PUT') {
        $user = auth_api_require_auth();
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        $oldPassword = $input['old_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';

        if (!$oldPassword || !$newPassword) {
            auth_api_error(400, '请填写旧密码和新密码');
        }
        if (strlen($newPassword) < 6 || strlen($newPassword) > 100) {
            auth_api_error(400, '新密码长度必须在6-100个字符之间');
        }
        if (!auth_verify_password($oldPassword, $user['password'])) {
            auth_api_error(400, '旧密码错误');
        }

        auth_update_user_password((int)$user['id'], $newPassword);

        auth_api_response(200, ['message' => '密码修改成功']);
    }

    auth_api_error(404, '接口不存在');
    
} catch (RuntimeException $e) {
    auth_api_error(400, $e->getMessage());
} catch (Exception $e) {
    error_log('Auth API Error: ' . $e->getMessage());
    auth_api_error(500, '服务器错误');
}
