<?php
/**
 * CANG-AI 绘图 - 探索页接口
 * Copyright (c) 2025 苍洱 (CANG-AI). All rights reserved.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/app-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

try {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(60, (int) ($_GET['limit'] ?? 24)));
    $offset = ($page - 1) * $limit;

    $db = app_task_db();

    $countStmt = $db->query(
        "SELECT COUNT(*) as total FROM generation_tasks WHERE is_public = 1 AND status = 'completed'"
    );
    $total = (int) $countStmt->fetch()['total'];

    $stmt = $db->prepare(
        "SELECT task_id, mode, model, prompt, size, quality, count, items_json, created_at
         FROM generation_tasks
         WHERE is_public = 1 AND status = 'completed'
         ORDER BY datetime(created_at) DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $taskItems = json_decode($row['items_json'] ?: '[]', true) ?: [];
        if (!$taskItems) continue;

        $items[] = [
            'task_id' => $row['task_id'],
            'prompt' => mb_substr($row['prompt'], 0, 200),
            'model' => $row['model'],
            'size' => $row['size'],
            'created_at' => $row['created_at'],
            'images' => array_map(fn($img) => [
                'url' => $img['url'] ?? $img['download_url'] ?? '',
            ], array_slice($taskItems, 0, 4)),
        ];
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $limit,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
