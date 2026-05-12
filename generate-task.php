<?php

declare(strict_types=1);

require __DIR__ . '/app-lib.php';

if (PHP_SAPI === 'cli') {
    $command = $argv[1] ?? '';
    $taskId = $argv[2] ?? '';

    if ($command !== 'worker' || $taskId === '') {
        fwrite(STDERR, "Usage: php generate-task.php worker <task_id>\n");
        exit(1);
    }

    try {
        app_process_generation_task($taskId);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

header('Cache-Control: no-store');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $task = app_create_generation_task($_POST, $_FILES);
        $dispatched = app_start_generation_worker((string) $task['task_id']);
        app_json_response(202, [
            'ok' => true,
            'task_id' => $task['task_id'],
            'status' => $task['status'],
            'message' => $dispatched
                ? '任务已创建，前端可轮询查询结果。'
                : '任务已创建，当前环境禁用 exec，将在响应返回后继续处理。',
            'worker_mode' => $dispatched ? 'exec' : 'after_response',
        ]);
        if (!$dispatched) {
            app_continue_generation_after_response((string) $task['task_id']);
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        app_json_response(405, ['error' => 'Method not allowed']);
        exit;
    }

    $action = trim((string) ($_GET['action'] ?? ''));
    if ($action === 'recent') {
        $limit = max(1, min(20, (int) ($_GET['limit'] ?? 8)));
        $tasks = app_list_recent_tasks($limit);
        app_json_response(200, [
            'ok' => true,
            'tasks' => $tasks,
        ]);
        exit;
    }

    $taskId = trim((string) ($_GET['task_id'] ?? ''));
    if ($taskId === '') {
        app_json_response(400, ['error' => 'Missing task_id']);
        exit;
    }

    $task = app_read_generation_task($taskId);
    app_json_response(200, [
        'ok' => true,
        'task' => $task,
    ]);
} catch (Throwable $e) {
    app_json_response(500, ['error' => $e->getMessage()]);
}
