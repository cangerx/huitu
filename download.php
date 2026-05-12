<?php

declare(strict_types=1);

require __DIR__ . '/app-lib.php';

try {
    // 本地模式: download.php?file=xxx
    $file = trim((string) ($_GET['file'] ?? ''));
    if ($file !== '') {
        if (strpos($file, '/') !== false || strpos($file, '..') !== false) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid file parameter.';
            exit;
        }

        $path = app_images_dir() . '/' . $file;
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'File not found.';
            exit;
        }

        $mimeType = app_detect_mime_from_path($path, $file);
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    // OSS 模式: download.php?key=xxx
    $key = trim((string) ($_GET['key'] ?? ''));
    if ($key === '' || strpos($key, '..') !== false) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Missing file or key parameter.';
        exit;
    }

    app_require_oss_config();

    if (trim((string) ($_GET['mode'] ?? '')) === 'redirect') {
        header('Location: ' . app_build_oss_signed_url($key), true, 302);
        exit;
    }

    [$statusCode, $headers, $body] = app_fetch_from_oss($key);
    if ($statusCode < 200 || $statusCode >= 300) {
        http_response_code($statusCode);
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
        exit;
    }

    $contentType = 'application/octet-stream';
    foreach ($headers as $headerLine) {
        if (stripos($headerLine, 'Content-Type:') === 0) {
            $contentType = trim(substr($headerLine, strlen('Content-Type:')));
            break;
        }
    }

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo $body;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
