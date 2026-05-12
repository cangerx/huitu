<?php

declare(strict_types=1);

const APP_RUNTIME_DIR = __DIR__ . '/runtime';
const APP_TASK_FILES_DIR = APP_RUNTIME_DIR . '/generation-task-files';
const APP_IMAGES_DIR = APP_RUNTIME_DIR . '/images';
const APP_TASK_DB_PATH = APP_RUNTIME_DIR . '/generation-tasks.sqlite';
const APP_ALLOWED_PROXY_PATHS = [
    '/v1/chat/completions',
    '/v1/images/generations',
    '/v1/images/edits',
];
const APP_API_ENDPOINTS = [
    'https://api.772.ee',   // 默认
    'http://api.4m3x.cn',   // 备用
];

function app_json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function app_runtime_dir(): string
{
    app_ensure_dir(APP_RUNTIME_DIR);
    return APP_RUNTIME_DIR;
}

function app_task_files_dir(): string
{
    app_ensure_dir(APP_TASK_FILES_DIR);
    return APP_TASK_FILES_DIR;
}

function app_images_dir(): string
{
    app_ensure_dir(APP_IMAGES_DIR);
    return APP_IMAGES_DIR;
}

function app_ensure_dir(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException("Failed to create directory: {$path}");
    }
}

function app_task_dir(string $taskId): string
{
    app_assert_valid_task_id($taskId);
    return app_task_files_dir() . '/' . $taskId;
}

function app_task_db_path(): string
{
    app_runtime_dir();
    return APP_TASK_DB_PATH;
}

function app_task_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('PDO SQLite extension is not enabled.');
    }

    $pdo = new PDO('sqlite:' . app_task_db_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS generation_tasks (
            task_id TEXT PRIMARY KEY,
            status TEXT NOT NULL,
            mode TEXT NOT NULL,
            model TEXT NOT NULL,
            prompt TEXT NOT NULL,
            size TEXT NOT NULL,
            quality TEXT NOT NULL,
            count INTEGER NOT NULL,
            is_public INTEGER NOT NULL DEFAULT 0,
            input_count INTEGER NOT NULL,
            retention_hours INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            completed_at TEXT DEFAULT NULL,
            message TEXT DEFAULT NULL,
            error TEXT DEFAULT NULL,
            items_json TEXT NOT NULL DEFAULT "[]",
            owner_hash TEXT NOT NULL DEFAULT "",
            api_key TEXT NOT NULL DEFAULT "",
            files_json TEXT NOT NULL DEFAULT "[]"
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_generation_tasks_status ON generation_tasks(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_generation_tasks_created_at ON generation_tasks(created_at)');
    return $pdo;
}

function app_assert_valid_task_id(string $taskId): void
{
    if (!preg_match('/\A[a-f0-9]{32}\z/', $taskId)) {
        throw new RuntimeException('Invalid task_id.');
    }
}

function app_decode_task_json(?string $raw, array $fallback = []): array
{
    if (!is_string($raw) || $raw === '') {
        return $fallback;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function app_task_from_row(array $row): array
{
    return [
        'task_id' => (string) $row['task_id'],
        'status' => (string) $row['status'],
        'mode' => (string) $row['mode'],
        'model' => (string) $row['model'],
        'prompt' => (string) $row['prompt'],
        'size' => (string) $row['size'],
        'quality' => (string) $row['quality'],
        'count' => (int) $row['count'],
        'input_count' => (int) $row['input_count'],
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'items' => app_decode_task_json($row['items_json'] ?? '[]', []),
        'message' => (string) ($row['message'] ?? ''),
        'error' => (string) ($row['error'] ?? ''),
        'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
    ];
}

function app_task_worker_payload_from_row(array $row): array
{
    return [
        'api_key' => (string) ($row['api_key'] ?? ''),
        'files' => app_decode_task_json($row['files_json'] ?? '[]', []),
    ];
}

function app_task_timeout_seconds(): int
{
    return 10 * 60;
}

function app_now_iso(): string
{
    return gmdate('c');
}

function app_is_task_timed_out(array $task): bool
{
    $status = (string) ($task['status'] ?? '');
    if (!in_array($status, ['queued', 'processing'], true)) {
        return false;
    }

    $createdAt = strtotime((string) ($task['created_at'] ?? ''));
    if ($createdAt === false) {
        return false;
    }

    return (time() - $createdAt) >= app_task_timeout_seconds();
}

function app_get_authorization_header(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return (string) $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, 'Authorization') === 0) {
                return (string) $value;
            }
        }
    }

    return '';
}

function app_request_api_key(): string
{
    $authorization = trim(app_get_authorization_header());
    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    return trim((string) ($_GET['api_key'] ?? $_POST['api_key'] ?? ''));
}

function app_is_oss_enabled(): bool
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }

    $configFile = __DIR__ . '/oss-config.php';
    if (!file_exists($configFile)) {
        $result = false;
        return false;
    }

    require_once $configFile;

    $required = ['OSS_ENDPOINT', 'OSS_ACCESS_KEY_ID', 'OSS_ACCESS_KEY_SECRET', 'OSS_BUCKET'];
    foreach ($required as $name) {
        if (!defined($name) || trim((string) constant($name)) === '') {
            $result = false;
            return false;
        }
    }

    $result = true;
    return true;
}

function app_require_oss_config(): void
{
    if (!app_is_oss_enabled()) {
        throw new RuntimeException('OSS is not configured. Create oss-config.php from oss-config.php.example');
    }
}

function app_oss_key_prefix(): string
{
    return trim((string) (defined('OSS_KEY_PREFIX') ? OSS_KEY_PREFIX : 'cang-api-draw'), '/');
}

function app_oss_signed_url_expires(): int
{
    return max(60, (int) (defined('OSS_SIGNED_URL_EXPIRES') ? OSS_SIGNED_URL_EXPIRES : 3600));
}

function app_oss_public_base_url(): string
{
    $url = trim((string) (defined('OSS_PUBLIC_BASE_URL') ? OSS_PUBLIC_BASE_URL : ''));
    return rtrim($url, '/');
}

function app_oss_host(): string
{
    return OSS_BUCKET . '.' . OSS_ENDPOINT;
}

function app_oss_sign(string $verb, string $contentMd5, string $contentType, string $date, string $canonicalizedResource): string
{
    $stringToSign = $verb . "\n" . $contentMd5 . "\n" . $contentType . "\n" . $date . "\n" . $canonicalizedResource;
    return base64_encode(hash_hmac('sha1', $stringToSign, OSS_ACCESS_KEY_SECRET, true));
}

function app_build_oss_key(string $extension): string
{
    $prefix = app_oss_key_prefix();
    $datePath = gmdate('Y/m/d');
    $token = bin2hex(random_bytes(8));
    return "{$prefix}/{$datePath}/{$token}.{$extension}";
}

function app_build_oss_public_url(string $key): string
{
    $baseUrl = app_oss_public_base_url();
    if ($baseUrl !== '') {
        return $baseUrl . '/' . implode('/', array_map('rawurlencode', explode('/', trim($key, '/'))));
    }
    return app_build_oss_signed_url($key);
}

function app_build_oss_signed_url(string $key, ?int $expiresAt = null): string
{
    app_require_oss_config();
    $expiresAt = $expiresAt ?? (time() + app_oss_signed_url_expires());
    $resource = '/' . OSS_BUCKET . '/' . $key;
    $signature = app_oss_sign('GET', '', '', (string) $expiresAt, $resource);
    $host = app_oss_host();
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', trim($key, '/'))));
    return 'https://' . $host . '/' . $encodedKey
        . '?OSSAccessKeyId=' . rawurlencode(OSS_ACCESS_KEY_ID)
        . '&Expires=' . rawurlencode((string) $expiresAt)
        . '&Signature=' . rawurlencode($signature);
}

function app_build_image_url(string $keyOrFilename): string
{
    $backend = app_storage_backend();
    if ($backend === 'oss') {
        return app_build_oss_public_url($keyOrFilename);
    }
    if ($backend === 'r2') {
        return app_build_r2_public_url($keyOrFilename);
    }
    if ($backend === 'cos') {
        return app_build_cos_public_url($keyOrFilename);
    }
    return app_build_local_image_url($keyOrFilename);
}

function app_build_local_image_url(string $filename): string
{
    return 'download.php?file=' . rawurlencode($filename);
}

function app_upload_to_oss(string $key, string $body, string $mimeType): void
{
    app_require_oss_config();
    $host = app_oss_host();
    $date = gmdate('D, d M Y H:i:s \G\M\T');
    $resource = '/' . OSS_BUCKET . '/' . $key;
    $signature = app_oss_sign('PUT', '', $mimeType, $date, $resource);
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', trim($key, '/'))));

    $ch = curl_init('https://' . $host . '/' . $encodedKey);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Host: ' . $host,
            'Date: ' . $date,
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . strlen($body),
            'Authorization: OSS ' . OSS_ACCESS_KEY_ID . ':' . $signature,
        ],
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $message = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("OSS upload failed: {$message}");
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException("OSS upload failed with status {$statusCode}: {$response}");
    }
}

function app_fetch_from_oss(string $key): array
{
    app_require_oss_config();
    $host = app_oss_host();
    $date = gmdate('D, d M Y H:i:s \G\M\T');
    $resource = '/' . OSS_BUCKET . '/' . $key;
    $signature = app_oss_sign('GET', '', '', $date, $resource);
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', trim($key, '/'))));

    $responseHeaders = [];
    $ch = curl_init('https://' . $host . '/' . $encodedKey);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Host: ' . $host,
            'Date: ' . $date,
            'Authorization: OSS ' . OSS_ACCESS_KEY_ID . ':' . $signature,
        ],
        CURLOPT_HEADERFUNCTION => static function ($curl, $headerLine) use (&$responseHeaders) {
            $trimmed = trim($headerLine);
            if ($trimmed !== '' && stripos($trimmed, 'HTTP/') !== 0) {
                $responseHeaders[] = $trimmed;
            }
            return strlen($headerLine);
        },
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $message = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("OSS fetch failed: {$message}");
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$statusCode, $responseHeaders, $body];
}

function app_list_oss_objects(string $prefix, int $maxKeys = 100): array
{
    app_require_oss_config();
    $host = app_oss_host();
    $date = gmdate('D, d M Y H:i:s \G\M\T');
    $resource = '/' . OSS_BUCKET . '/';
    $signature = app_oss_sign('GET', '', '', $date, $resource);

    $query = 'list-type=2&max-keys=' . rawurlencode((string) $maxKeys);
    $prefix = trim($prefix, '/');
    if ($prefix !== '') {
        $query .= '&prefix=' . rawurlencode($prefix . '/');
    }

    $ch = curl_init('https://' . $host . '/?' . $query);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Host: ' . $host,
            'Date: ' . $date,
            'Authorization: OSS ' . OSS_ACCESS_KEY_ID . ':' . $signature,
        ],
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $message = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("OSS list failed: {$message}");
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException("OSS list failed with status {$statusCode}: {$body}");
    }

    $xml = @simplexml_load_string($body);
    if ($xml === false) {
        throw new RuntimeException('Failed to parse OSS list response.');
    }

    $items = [];
    foreach ($xml->Contents as $content) {
        $key = trim((string) $content->Key, '/');
        if ($key === '') {
            continue;
        }
        $items[] = [
            'key' => $key,
            'last_modified' => (string) $content->LastModified,
            'size' => (int) $content->Size,
        ];
    }

    return $items;
}

// ── Cloudflare R2 ──

function app_is_r2_enabled(): bool
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }

    $configFile = __DIR__ . '/r2-config.php';
    if (!file_exists($configFile)) {
        $result = false;
        return false;
    }

    require_once $configFile;

    $required = ['R2_ACCOUNT_ID', 'R2_ACCESS_KEY_ID', 'R2_BUCKET'];
    foreach ($required as $name) {
        if (!defined($name) || trim((string) constant($name)) === '') {
            $result = false;
            return false;
        }
    }
    if (!app_r2_secret_key()) {
        $result = false;
        return false;
    }

    $result = true;
    return true;
}

function app_r2_secret_key(): string
{
    if (defined('R2_ACCESS_KEY_SECRET') && trim((string) R2_ACCESS_KEY_SECRET) !== '') {
        return R2_ACCESS_KEY_SECRET;
    }
    if (defined('R2_SECRET_ACCESS_KEY') && trim((string) R2_SECRET_ACCESS_KEY) !== '') {
        return R2_SECRET_ACCESS_KEY;
    }
    return '';
}

function app_require_r2_config(): void
{
    if (!app_is_r2_enabled()) {
        throw new RuntimeException('R2 is not configured. Create r2-config.php from r2-config.php.example');
    }
}

function app_r2_key_prefix(): string
{
    return trim((string) (defined('R2_KEY_PREFIX') ? R2_KEY_PREFIX : 'cang-api-draw'), '/');
}

function app_r2_public_base_url(): string
{
    return rtrim(trim((string) (defined('R2_PUBLIC_BASE_URL') ? R2_PUBLIC_BASE_URL : '')), '/');
}

function app_r2_s3_host(): string
{
    return R2_ACCOUNT_ID . '.r2.cloudflarestorage.com';
}

function app_r2_sign_v4(string $method, string $key, string $contentHash, string $contentType, array &$outHeaders, string $queryString = ''): void
{
    $host = app_r2_s3_host();
    $region = 'auto';
    $service = 's3';
    $now = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');

    $canonicalUri = '/' . R2_BUCKET . '/' . $key;
    if ($key === '') {
        $canonicalUri = '/' . R2_BUCKET;
    }

    $qsParts = [];
    if ($queryString !== '') {
        parse_str($queryString, $parsed);
        ksort($parsed);
        foreach ($parsed as $k => $v) {
            $qsParts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
    }
    $canonicalQueryString = implode('&', $qsParts);

    $signedHeadersList = ['host', 'x-amz-content-sha256', 'x-amz-date'];
    $headersMap = [
        'host' => $host,
        'x-amz-content-sha256' => $contentHash,
        'x-amz-date' => $now,
    ];
    if ($contentType !== '') {
        $signedHeadersList[] = 'content-type';
        $headersMap['content-type'] = $contentType;
        sort($signedHeadersList);
    }

    $canonicalHeaders = '';
    foreach ($signedHeadersList as $h) {
        $canonicalHeaders .= $h . ':' . $headersMap[$h] . "\n";
    }
    $signedHeaders = implode(';', $signedHeadersList);
    $canonicalRequest = $method . "\n" . $canonicalUri . "\n" . $canonicalQueryString . "\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $contentHash;

    $scope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . app_r2_secret_key(), true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "AWS4-HMAC-SHA256 Credential=" . R2_ACCESS_KEY_ID . "/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $outHeaders = [
        'Host: ' . $host,
        'x-amz-date: ' . $now,
        'x-amz-content-sha256: ' . $contentHash,
        'Authorization: ' . $authorization,
    ];
    if ($contentType !== '') {
        $outHeaders[] = 'Content-Type: ' . $contentType;
    }
}

function app_build_r2_key(string $extension): string
{
    $prefix = app_r2_key_prefix();
    $datePath = gmdate('Y/m/d');
    $token = bin2hex(random_bytes(8));
    return "{$prefix}/{$datePath}/{$token}.{$extension}";
}

function app_build_r2_public_url(string $key): string
{
    $baseUrl = app_r2_public_base_url();
    if ($baseUrl === '') {
        throw new RuntimeException('R2_PUBLIC_BASE_URL is required for R2 storage.');
    }
    return $baseUrl . '/' . implode('/', array_map('rawurlencode', explode('/', trim($key, '/'))));
}

function app_upload_to_r2(string $key, string $body, string $mimeType): void
{
    app_require_r2_config();
    $host = app_r2_s3_host();
    $contentHash = hash('sha256', $body);
    $headers = [];
    app_r2_sign_v4('PUT', $key, $contentHash, $mimeType, $headers);
    $headers[] = 'Content-Length: ' . strlen($body);

    $ch = curl_init('https://' . $host . '/' . R2_BUCKET . '/' . $key);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $message = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("R2 upload failed: {$message}");
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException("R2 upload failed with status {$statusCode}: {$response}");
    }
}

function app_list_r2_objects(string $prefix, int $maxKeys = 100): array
{
    app_require_r2_config();
    $host = app_r2_s3_host();
    $qs = 'list-type=2&prefix=' . rawurlencode($prefix) . '&max-keys=' . $maxKeys;
    $contentHash = hash('sha256', '');
    $headers = [];
    app_r2_sign_v4('GET', '', $contentHash, '', $headers, $qs);

    $url = 'https://' . $host . '/' . R2_BUCKET . '?' . $qs;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("R2 list failed: {$err}");
    }
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("R2 list failed with status {$code}: {$response}");
    }

    $objects = [];
    if (preg_match_all('/<Contents>(.*?)<\/Contents>/s', $response, $matches)) {
        foreach ($matches[1] as $block) {
            $key = '';
            $lastModified = '';
            $size = 0;
            if (preg_match('/<Key>(.*?)<\/Key>/', $block, $m)) $key = $m[1];
            if (preg_match('/<LastModified>(.*?)<\/LastModified>/', $block, $m)) $lastModified = $m[1];
            if (preg_match('/<Size>(.*?)<\/Size>/', $block, $m)) $size = (int) $m[1];
            if ($key !== '') {
                $objects[] = ['key' => $key, 'last_modified' => $lastModified, 'size' => $size];
            }
        }
    }
    return $objects;
}

function app_delete_r2_object(string $key): void
{
    app_require_r2_config();
    $host = app_r2_s3_host();
    $contentHash = hash('sha256', '');
    $headers = [];
    app_r2_sign_v4('DELETE', $key, $contentHash, '', $headers);

    $ch = curl_init('https://' . $host . '/' . R2_BUCKET . '/' . $key);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $code = $response !== false ? (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) : 0;
    curl_close($ch);
    if ($code !== 0 && $code !== 204 && $code < 200) {
        throw new RuntimeException("R2 delete failed with status {$code}");
    }
}

function app_r2_retention_hours(): int
{
    return defined('R2_RETENTION_HOURS') ? max(0, (int) R2_RETENTION_HOURS) : 0;
}

function app_cleanup_expired_r2_objects(): int
{
    $hours = app_r2_retention_hours();
    if ($hours <= 0) return 0;

    $prefix = app_r2_key_prefix();
    $objects = app_list_r2_objects($prefix . '/', 200);
    $cutoff = gmdate('Y-m-d\TH:i:s', time() - $hours * 3600);
    $deleted = 0;

    foreach ($objects as $obj) {
        if ($obj['last_modified'] !== '' && $obj['last_modified'] < $cutoff) {
            try {
                app_delete_r2_object($obj['key']);
                $deleted++;
            } catch (Throwable $e) {
                // skip individual failures
            }
        }
    }
    return $deleted;
}

// ── 腾讯云 COS ──

function app_is_cos_enabled(): bool
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }

    $configFile = __DIR__ . '/cos-config.php';
    if (!file_exists($configFile)) {
        $result = false;
        return false;
    }

    require_once $configFile;

    $required = ['COS_REGION', 'COS_SECRET_ID', 'COS_SECRET_KEY', 'COS_BUCKET'];
    foreach ($required as $name) {
        if (!defined($name) || trim((string) constant($name)) === '') {
            $result = false;
            return false;
        }
    }

    $result = true;
    return true;
}

function app_require_cos_config(): void
{
    if (!app_is_cos_enabled()) {
        throw new RuntimeException('COS is not configured. Create cos-config.php from cos-config.php.example');
    }
}

function app_cos_key_prefix(): string
{
    return trim((string) (defined('COS_KEY_PREFIX') ? COS_KEY_PREFIX : 'cang-api-draw'), '/');
}

function app_cos_signed_url_expires(): int
{
    return max(60, (int) (defined('COS_SIGNED_URL_EXPIRES') ? COS_SIGNED_URL_EXPIRES : 3600));
}

function app_cos_public_base_url(): string
{
    return rtrim(trim((string) (defined('COS_PUBLIC_BASE_URL') ? COS_PUBLIC_BASE_URL : '')), '/');
}

function app_cos_host(): string
{
    return COS_BUCKET . '.cos.' . COS_REGION . '.myqcloud.com';
}

function app_build_cos_key(string $extension): string
{
    $prefix = app_cos_key_prefix();
    $datePath = gmdate('Y/m/d');
    $token = bin2hex(random_bytes(8));
    return "{$prefix}/{$datePath}/{$token}.{$extension}";
}

function app_cos_sign_v5(string $method, string $key, int $startTime, int $endTime): string
{
    $method = strtolower($method);
    $keyTime = $startTime . ';' . $endTime;
    $signKey = hash_hmac('sha1', $keyTime, COS_SECRET_KEY);
    $urlParamList = '';
    $httpParameters = '';
    $headerList = '';
    $httpHeaders = '';
    $httpString = $method . "\n/" . $key . "\n" . $httpParameters . "\n" . $httpHeaders . "\n";
    $sha1edHttpString = sha1($httpString);
    $stringToSign = "sha1\n" . $keyTime . "\n" . $sha1edHttpString . "\n";
    $signature = hash_hmac('sha1', $stringToSign, $signKey);

    return 'q-sign-algorithm=sha1&q-ak=' . COS_SECRET_ID
        . '&q-sign-time=' . $keyTime
        . '&q-key-time=' . $keyTime
        . '&q-header-list=' . $headerList
        . '&q-url-param-list=' . $urlParamList
        . '&q-signature=' . $signature;
}

function app_build_cos_public_url(string $key): string
{
    $baseUrl = app_cos_public_base_url();
    if ($baseUrl !== '') {
        return $baseUrl . '/' . implode('/', array_map('rawurlencode', explode('/', trim($key, '/'))));
    }
    return app_build_cos_signed_url($key);
}

function app_build_cos_signed_url(string $key, ?int $expiresAt = null): string
{
    app_require_cos_config();
    $now = time();
    $expiresAt = $expiresAt ?? ($now + app_cos_signed_url_expires());
    $host = app_cos_host();
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', trim($key, '/'))));
    $authorization = app_cos_sign_v5('GET', $key, $now, $expiresAt);
    return 'https://' . $host . '/' . $encodedKey . '?' . $authorization;
}

function app_upload_to_cos(string $key, string $body, string $mimeType): void
{
    app_require_cos_config();
    $host = app_cos_host();
    $now = time();
    $authorization = app_cos_sign_v5('PUT', $key, $now, $now + 600);
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', trim($key, '/'))));

    $ch = curl_init('https://' . $host . '/' . $encodedKey);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Host: ' . $host,
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . strlen($body),
            'Authorization: ' . $authorization,
        ],
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $message = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("COS upload failed: {$message}");
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException("COS upload failed with status {$statusCode}: {$response}");
    }
}

// ── 统一存储接口 ──

function app_storage_backend(): string
{
    if (app_is_oss_enabled()) return 'oss';
    if (app_is_r2_enabled()) return 'r2';
    if (app_is_cos_enabled()) return 'cos';
    return 'local';
}

function app_detect_mime_from_path(string $path, string $fallbackName = ''): string
{
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo !== false) {
        $detected = finfo_file($finfo, $path);
        finfo_close($finfo);
        if (is_string($detected) && $detected !== '') {
            return app_normalize_image_mime($detected);
        }
    }

    return app_detect_mime_from_name($fallbackName, 'image/png');
}

function app_detect_mime_from_binary(string $binary, string $fallbackName = ''): string
{
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo !== false) {
        $detected = finfo_buffer($finfo, $binary);
        finfo_close($finfo);
        if (is_string($detected) && $detected !== '') {
            return app_normalize_image_mime($detected);
        }
    }

    return app_detect_mime_from_name($fallbackName, 'image/png');
}

function app_detect_mime_from_name(string $filename, string $fallback = 'image/png'): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            return 'image/jpeg';
        case 'webp':
            return 'image/webp';
        case 'gif':
            return 'image/gif';
        case 'png':
            return 'image/png';
        default:
            return $fallback;
    }
}

function app_normalize_image_mime(string $mimeType): string
{
    $allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/gif'];
    $mimeType = strtolower(trim(explode(';', $mimeType)[0]));
    if (!in_array($mimeType, $allowed, true)) {
        throw new RuntimeException("Unsupported image mime type: {$mimeType}");
    }

    return $mimeType === 'image/jpg' ? 'image/jpeg' : $mimeType;
}

function app_extension_from_mime(string $mimeType): string
{
    switch ($mimeType) {
        case 'image/jpeg':
            return 'jpg';
        case 'image/webp':
            return 'webp';
        case 'image/gif':
            return 'gif';
        default:
            return 'png';
    }
}

function app_save_image(string $binary, string $mimeType): string
{
    $backend = app_storage_backend();
    $extension = app_extension_from_mime($mimeType);

    if ($backend === 'oss') {
        $key = app_build_oss_key($extension);
        app_upload_to_oss($key, $binary, $mimeType);
        return $key;
    }

    if ($backend === 'r2') {
        $key = app_build_r2_key($extension);
        app_upload_to_r2($key, $binary, $mimeType);
        return $key;
    }

    if ($backend === 'cos') {
        $key = app_build_cos_key($extension);
        app_upload_to_cos($key, $binary, $mimeType);
        return $key;
    }

    return app_save_image_locally($binary, $mimeType);
}

function app_save_image_locally(string $binary, string $mimeType): string
{
    $extension = app_extension_from_mime($mimeType);
    $filename = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    $dir = app_images_dir();
    $path = $dir . '/' . $filename;
    if (file_put_contents($path, $binary) === false) {
        throw new RuntimeException("Failed to save image: {$filename}");
    }
    return $filename;
}

function app_list_local_images(int $limit = 18): array
{
    $dir = app_images_dir();
    $files = scandir($dir, SCANDIR_SORT_DESCENDING);
    if (!is_array($files)) {
        return [];
    }

    $items = [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $dir . '/' . $file;
        if (!is_file($path)) {
            continue;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            continue;
        }
        $items[] = [
            'filename' => $file,
            'url' => app_build_local_image_url($file),
            'download_url' => app_build_local_image_url($file),
            'last_modified' => gmdate('c', (int) filemtime($path)),
            'size' => (int) filesize($path),
        ];
        if (count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

function app_create_generation_task(array $input, array $files): array
{
    $mode = (string) ($input['mode'] ?? 'text');
    if (!in_array($mode, ['text', 'image'], true)) {
        throw new RuntimeException('Invalid mode.');
    }

    $apiKey = trim((string) ($input['api_key'] ?? ''));
    $model = trim((string) ($input['model'] ?? ''));
    $prompt = trim((string) ($input['prompt'] ?? ''));
    $size = trim((string) ($input['size'] ?? '1:1'));
    $quality = trim((string) ($input['quality'] ?? 'medium'));
    $count = (int) ($input['count'] ?? 1);

    if ($apiKey === '') {
        throw new RuntimeException('Missing api_key.');
    }
    if ($model === '') {
        throw new RuntimeException('Missing model.');
    }
    if ($prompt === '') {
        throw new RuntimeException('Missing prompt.');
    }
    $sizeValid = false;
    if ($size === 'auto') {
        $sizeValid = true;
    } elseif (preg_match('/^(\d+):(\d+)$/', $size, $m)) {
        $a = (int) $m[1]; $b = (int) $m[2];
        $sizeValid = $a >= 1 && $b >= 1 && $a <= 20 && $b <= 20;
    } elseif (preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
        $w = (int) $m[1]; $h = (int) $m[2];
        $sizeValid = $w >= 16 && $h >= 16 && $w <= 3840 && $h <= 3840
            && $w % 16 === 0 && $h % 16 === 0;
    }
    if (!$sizeValid) {
        throw new RuntimeException('Invalid size.');
    }
    if (!in_array($quality, ['low', 'medium', 'high'], true)) {
        throw new RuntimeException('Invalid quality.');
    }
    if ($count < 1 || $count > 4) {
        throw new RuntimeException('Invalid count.');
    }

    $taskId = bin2hex(random_bytes(16));
    $taskDir = app_task_dir($taskId);
    $inputDir = $taskDir . '/inputs';
    app_ensure_dir($inputDir);

    $savedFiles = [];
    if ($mode === 'image') {
        $normalized = app_normalize_uploaded_files($files['image'] ?? null);
        if (!$normalized) {
            throw new RuntimeException('请先上传一张参考图片。');
        }
        if (count($normalized) > 16) {
            throw new RuntimeException('参考图最多 16 张。');
        }

        foreach ($normalized as $index => $fileInfo) {
            if (($fileInfo['size'] ?? 0) > 2 * 1024 * 1024) {
                throw new RuntimeException("图片 {$fileInfo['name']} 超过 2MB，无法上传。");
            }
            if ((int) ($fileInfo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new RuntimeException("上传文件失败: {$fileInfo['name']}");
            }

            $mimeType = app_detect_mime_from_path((string) $fileInfo['tmp_name'], (string) $fileInfo['name']);
            $extension = app_extension_from_mime($mimeType);
            $targetPath = $inputDir . '/' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '.' . $extension;

            if (!move_uploaded_file((string) $fileInfo['tmp_name'], $targetPath)) {
                throw new RuntimeException("Failed to persist upload: {$fileInfo['name']}");
            }

            $savedFiles[] = [
                'path' => $targetPath,
                'name' => (string) $fileInfo['name'],
                'mime_type' => $mimeType,
                'size' => (int) $fileInfo['size'],
            ];
        }
    }

    $task = [
        'task_id' => $taskId,
        'status' => 'queued',
        'mode' => $mode,
        'model' => $model,
        'prompt' => $prompt,
        'size' => $size,
        'quality' => $quality,
        'count' => $count,
        'input_count' => count($savedFiles),
        'created_at' => app_now_iso(),
        'updated_at' => app_now_iso(),
        'items' => [],
    ];

    $pdo = app_task_db();
    $stmt = $pdo->prepare(
        'INSERT INTO generation_tasks (
            task_id, status, mode, model, prompt, size, quality, count, is_public,
            input_count, retention_hours, created_at, updated_at, completed_at,
            message, error, items_json, owner_hash, api_key, files_json
        ) VALUES (
            :task_id, :status, :mode, :model, :prompt, :size, :quality, :count, 0,
            :input_count, 0, :created_at, :updated_at, :completed_at,
            :message, :error, :items_json, "", :api_key, :files_json
        )'
    );
    $stmt->execute([
        ':task_id' => $task['task_id'],
        ':status' => $task['status'],
        ':mode' => $task['mode'],
        ':model' => $task['model'],
        ':prompt' => $task['prompt'],
        ':size' => $task['size'],
        ':quality' => $task['quality'],
        ':count' => $task['count'],
        ':input_count' => $task['input_count'],
        ':created_at' => $task['created_at'],
        ':updated_at' => $task['updated_at'],
        ':completed_at' => null,
        ':message' => null,
        ':error' => null,
        ':items_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':api_key' => $apiKey,
        ':files_json' => json_encode($savedFiles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return $task;
}

function app_normalize_uploaded_files($fileInfo): array
{
    if (!is_array($fileInfo) || empty($fileInfo['tmp_name'])) {
        return [];
    }

    if (!is_array($fileInfo['tmp_name'])) {
        return [$fileInfo];
    }

    $normalized = [];
    $count = count($fileInfo['tmp_name']);
    for ($i = 0; $i < $count; $i += 1) {
        $normalized[] = [
            'name' => $fileInfo['name'][$i] ?? '',
            'type' => $fileInfo['type'][$i] ?? '',
            'tmp_name' => $fileInfo['tmp_name'][$i] ?? '',
            'error' => $fileInfo['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $fileInfo['size'][$i] ?? 0,
        ];
    }

    return $normalized;
}

function app_read_generation_task(string $taskId): array
{
    app_assert_valid_task_id($taskId);
    $stmt = app_task_db()->prepare('SELECT * FROM generation_tasks WHERE task_id = :task_id LIMIT 1');
    $stmt->execute([':task_id' => $taskId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Task not found.');
    }

    return app_finalize_task_timeout_if_needed(app_task_from_row($row));
}

function app_write_generation_task(array $task): void
{
    $task['updated_at'] = app_now_iso();
    $stmt = app_task_db()->prepare(
        'UPDATE generation_tasks
         SET status = :status,
             mode = :mode,
             model = :model,
             prompt = :prompt,
             size = :size,
             quality = :quality,
             count = :count,
             input_count = :input_count,
             created_at = :created_at,
             updated_at = :updated_at,
             completed_at = :completed_at,
             message = :message,
             error = :error,
             items_json = :items_json
         WHERE task_id = :task_id'
    );
    $stmt->execute([
        ':task_id' => (string) $task['task_id'],
        ':status' => (string) ($task['status'] ?? 'queued'),
        ':mode' => (string) ($task['mode'] ?? 'text'),
        ':model' => (string) ($task['model'] ?? ''),
        ':prompt' => (string) ($task['prompt'] ?? ''),
        ':size' => (string) ($task['size'] ?? '1024x1024'),
        ':quality' => (string) ($task['quality'] ?? 'medium'),
        ':count' => (int) ($task['count'] ?? 1),
        ':input_count' => (int) ($task['input_count'] ?? 0),
        ':created_at' => (string) ($task['created_at'] ?? app_now_iso()),
        ':updated_at' => (string) $task['updated_at'],
        ':completed_at' => $task['completed_at'] ?? null,
        ':message' => array_key_exists('message', $task) ? (string) ($task['message'] ?? '') : null,
        ':error' => array_key_exists('error', $task) ? (string) ($task['error'] ?? '') : null,
        ':items_json' => json_encode($task['items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function app_mark_task_timed_out(array $task): array
{
    $task['status'] = 'failed';
    $task['message'] = '任务处理超时，请重新提交。';
    $task['error'] = 'timeout';
    $task['completed_at'] = app_now_iso();
    app_write_generation_task($task);
    app_cleanup_task_workspace((string) $task['task_id']);
    return $task;
}

function app_finalize_task_timeout_if_needed(array $task): array
{
    if (!app_is_task_timed_out($task)) {
        return $task;
    }

    return app_mark_task_timed_out($task);
}

function app_list_recent_tasks(int $limit = 12): array
{
    $limit = max(1, min(30, $limit));
    $stmt = app_task_db()->prepare(
        'SELECT * FROM generation_tasks
         ORDER BY datetime(created_at) DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $tasks = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tasks[] = app_finalize_task_timeout_if_needed(app_task_from_row($row));
    }

    return $tasks;
}

function app_start_generation_worker(string $taskId): bool
{
    $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $script = __DIR__ . '/generate-task.php';
    $command = escapeshellarg($phpBinary)
        . ' '
        . escapeshellarg($script)
        . ' worker '
        . escapeshellarg($taskId)
        . ' > /dev/null 2>&1 &';

    if (!function_exists('exec')) {
        return false;
    }

    exec($command);
    return true;
}

function app_continue_generation_after_response(string $taskId): void
{
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    header('Connection: close');

    $length = ob_get_length();
    if ($length !== false) {
        header('Content-Length: ' . (string) $length);
    }

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }

    @flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    app_process_generation_task($taskId);
}

function app_process_generation_task(string $taskId): void
{
    $stmt = app_task_db()->prepare('SELECT * FROM generation_tasks WHERE task_id = :task_id LIMIT 1');
    $stmt->execute([':task_id' => $taskId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Task not found.');
    }

    $task = app_task_from_row($row);
    $secret = app_task_worker_payload_from_row($row);

    if (($task['status'] ?? '') === 'completed') {
        return;
    }

    $task['status'] = 'processing';
    $task['message'] = '任务已提交，正在等待上游接口返回结果。';
    app_write_generation_task($task);

    try {
        $response = app_call_generation_api($task, (string) ($secret['api_key'] ?? ''), (array) ($secret['files'] ?? []));
        $items = app_extract_generation_items($response);
        if (!$items) {
            throw new RuntimeException('上游接口未返回图片数据。');
        }

        $savedItems = [];
        foreach ($items as $index => $item) {
            $savedItems[] = app_store_generated_item($item, $task, $index);
            $task['message'] = '图片已生成，正在保存 ' . ($index + 1) . '/' . count($items) . '。';
            app_write_generation_task($task);
        }

        $task['status'] = 'completed';
        $task['message'] = '生成完成。';
        $task['items'] = $savedItems;
        $task['completed_at'] = app_now_iso();
        app_write_generation_task($task);
        app_cleanup_task_workspace($taskId);
    } catch (Throwable $e) {
        $task['status'] = 'failed';
        $task['message'] = $e->getMessage();
        $task['error'] = $e->getMessage();
        app_write_generation_task($task);
        app_cleanup_task_workspace($taskId);
    }
}

function app_resolve_size_to_pixels(string $size, string $quality): string
{
    if ($size === 'auto' || preg_match('/^\d+x\d+$/', $size)) {
        return $size;
    }

    if (!preg_match('/^(\d+):(\d+)$/', $size, $m)) {
        return $size;
    }

    $a = (int) $m[1];
    $b = (int) $m[2];
    $ratio = $a / $b;

    $maxDim = $quality === 'low' ? 1024 : 2048;

    if ($ratio >= 1) {
        $w = $maxDim;
        $h = (int) round($maxDim / $ratio);
    } else {
        $h = $maxDim;
        $w = (int) round($maxDim * $ratio);
    }

    $w = max(16, (int) (round($w / 16) * 16));
    $h = max(16, (int) (round($h / 16) * 16));

    return $w . 'x' . $h;
}

function app_call_generation_api(array $task, string $apiKey, array $files): array
{
    if ($apiKey === '') {
        throw new RuntimeException('Missing API key.');
    }

    $mode = (string) ($task['mode'] ?? 'text');
    $path = $mode === 'image' ? '/v1/images/edits' : '/v1/images/generations';
    $apiSize = app_resolve_size_to_pixels((string) $task['size'], (string) $task['quality']);
    $baseHeaders = ['Authorization: Bearer ' . $apiKey];

    $response = false;
    $lastError = '';
    $ch = null;

    foreach (APP_API_ENDPOINTS as $base) {
        $endpoint = rtrim($base, '/') . $path;
        $headers = $baseHeaders;

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($mode === 'image') {
            $postFields = [
                'model' => (string) $task['model'],
                'prompt' => (string) $task['prompt'],
                'size' => $apiSize,
                'quality' => (string) $task['quality'],
                'n' => (string) $task['count'],
            ];
            foreach ($files as $index => $file) {
                $postFields["image[{$index}]"] = new CURLFile(
                    (string) $file['path'],
                    (string) $file['mime_type'],
                    (string) $file['name']
                );
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'model' => (string) $task['model'],
                'prompt' => (string) $task['prompt'],
                'size' => $apiSize,
                'quality' => (string) $task['quality'],
                'n' => (int) $task['count'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        if ($response === false) {
            $lastError = curl_error($ch) . " ({$base})";
            curl_close($ch);
            $ch = null;
            continue;
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (in_array($httpCode, [502, 503, 504], true)) {
            $lastError = "HTTP {$httpCode} ({$base})";
            curl_close($ch);
            $ch = null;
            $response = false;
            continue;
        }
        break;
    }

    if ($response === false || $ch === null) {
        throw new RuntimeException("所有 API 节点均不可用: {$lastError}");
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $decoded = null;
    if (stripos($contentType, 'application/json') !== false || str_starts_with(ltrim($response), '{')) {
        $decoded = json_decode($response, true);
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['error'] ?? $decoded['message'] ?? $response;
            throw new RuntimeException("上游返回 {$statusCode}: {$message}");
        }
        throw new RuntimeException("上游返回 {$statusCode}: {$response}");
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('上游返回了非 JSON 数据。');
    }

    return $decoded;
}

function app_extract_generation_items(array $response): array
{
    $items = $response['data'] ?? null;
    if (!is_array($items)) {
        return [];
    }

    return array_values(array_filter($items, static function ($item): bool {
        return is_array($item) && (!empty($item['b64_json']) || !empty($item['url']));
    }));
}

function app_store_generated_item(array $item, array $task, int $index): array
{
    $binary = '';
    $mimeType = 'image/png';

    if (!empty($item['b64_json'])) {
        $binary = base64_decode((string) $item['b64_json'], true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Failed to decode generated image.');
        }
        $mimeType = app_detect_mime_from_binary($binary, 'result.png');
    } elseif (!empty($item['url'])) {
        [$binary, $mimeType] = app_fetch_remote_image_binary((string) $item['url']);
    } else {
        throw new RuntimeException('Missing image payload.');
    }

    $ref = app_save_image($binary, $mimeType);
    $extension = app_extension_from_mime($mimeType);

    return [
        'key' => $ref,
        'filename' => app_build_generated_filename($task, $index + 1, $extension),
        'url' => app_build_image_url($ref),
        'download_url' => app_build_image_url($ref),
        'mime_type' => $mimeType,
        'size' => strlen($binary),
    ];
}

function app_fetch_remote_image_binary(string $url): array
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        throw new RuntimeException('Invalid remote image host.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => ['Accept: image/*'],
    ]);

    $binary = curl_exec($ch);
    if ($binary === false) {
        $message = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Failed to fetch source image: {$message}");
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException("Source image fetch failed with status {$statusCode}.");
    }

    $mimeType = app_normalize_image_mime($contentType !== '' ? $contentType : 'image/png');
    return [$binary, $mimeType];
}

function app_build_generated_filename(array $task, int $index, string $extension): string
{
    $timestamp = gmdate('Ymd-His');
    $mode = ($task['mode'] ?? 'text') === 'image' ? 'edit' : 'text';
    $size = str_replace(':', 'x', (string) ($task['size'] ?? '1024x1024'));
    return "image-{$mode}-{$size}-{$timestamp}-{$index}.{$extension}";
}

function app_cleanup_task_workspace(string $taskId): void
{
    $taskDir = app_task_dir($taskId);
    $stmt = app_task_db()->prepare(
        'UPDATE generation_tasks
         SET api_key = :api_key,
             files_json = :files_json
         WHERE task_id = :task_id'
    );
    $stmt->execute([
        ':api_key' => '',
        ':files_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':task_id' => $taskId,
    ]);

    $inputDir = $taskDir . '/inputs';
    if (!is_dir($inputDir)) {
        return;
    }

    $files = scandir($inputDir);
    if (!is_array($files)) {
        return;
    }

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        @unlink($inputDir . '/' . $file);
    }
    @rmdir($inputDir);
    @rmdir($taskDir);
}
