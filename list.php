<?php

declare(strict_types=1);

require __DIR__ . '/app-lib.php';

try {
    $limit = max(1, min(60, (int) ($_GET['limit'] ?? 18)));
    $backend = app_storage_backend();

    if ($backend === 'r2') {
        // Auto-cleanup expired R2 objects
        try { app_cleanup_expired_r2_objects(); } catch (Throwable $e) { /* ignore */ }

        $prefix = app_r2_key_prefix();
        $objects = app_list_r2_objects($prefix . '/', max(100, $limit));

        usort($objects, static function (array $a, array $b): int {
            return strcmp((string) $b['last_modified'], (string) $a['last_modified']);
        });

        $objects = array_slice($objects, 0, $limit);
        $items = array_map(static function (array $item): array {
            return [
                'key' => $item['key'],
                'url' => app_build_image_url($item['key']),
                'download_url' => app_build_image_url($item['key']),
                'last_modified' => $item['last_modified'],
                'size' => $item['size'],
            ];
        }, $objects);
    } elseif ($backend === 'oss') {
        $prefix = app_oss_key_prefix();
        $objects = app_list_oss_objects($prefix, max(100, $limit));

        usort($objects, static function (array $a, array $b): int {
            return strcmp((string) $b['last_modified'], (string) $a['last_modified']);
        });

        $objects = array_slice($objects, 0, $limit);
        $items = array_map(static function (array $item): array {
            return [
                'key' => $item['key'],
                'url' => app_build_image_url($item['key']),
                'download_url' => app_build_image_url($item['key']),
                'last_modified' => $item['last_modified'],
                'size' => $item['size'],
            ];
        }, $objects);
    } else {
        $items = app_list_local_images($limit);
    }

    app_json_response(200, ['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    app_json_response(500, ['error' => $e->getMessage()]);
}
