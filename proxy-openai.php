<?php

declare(strict_types=1);

require __DIR__ . '/app-lib.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $requestHost = parse_url($origin, PHP_URL_HOST);
    $serverHost = $_SERVER['HTTP_HOST'] ?? '';
    if (is_string($requestHost) && $requestHost !== '' && $requestHost === $serverHost) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(405, ['error' => 'Method not allowed']);
    exit;
}

$path = (string) ($_GET['path'] ?? '');
if (!in_array($path, APP_ALLOWED_PROXY_PATHS, true)) {
    app_json_response(400, ['error' => 'Invalid proxy path']);
    exit;
}

$authorization = app_get_authorization_header();
if ($authorization === '') {
    app_json_response(401, ['error' => 'Missing Authorization header']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$baseHeaders = ['Authorization: ' . $authorization];
if ($contentType !== '') {
    $baseHeaders[] = 'Content-Type: ' . $contentType;
}

$isMultipart = stripos($contentType, 'multipart/form-data') !== false;
$multipartBody = null;
$multipartContentType = null;
$rawBody = null;

if ($isMultipart) {
    [$multipartBody, $multipartContentType] = app_rebuild_multipart_payload();
} else {
    $raw = file_get_contents('php://input');
    $rawBody = $raw === false ? '' : $raw;
}

$endpoints = APP_API_ENDPOINTS;
$response = false;
$lastError = '';

foreach ($endpoints as $base) {
    $targetUrl = rtrim($base, '/') . $path;
    $headers = $baseHeaders;

    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($isMultipart) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipartBody);
        $headers = array_values(array_filter($headers, static function ($header) {
            return stripos($header, 'Content-Type:') !== 0;
        }));
        $headers[] = 'Content-Type: ' . $multipartContentType;
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    if ($response === false) {
        $lastError = curl_error($ch) . " ({$base})";
        curl_close($ch);
        continue;
    }
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (in_array($httpCode, [502, 503, 504], true)) {
        $lastError = "HTTP {$httpCode} ({$base})";
        curl_close($ch);
        $response = false;
        continue;
    }
    break;
}

if ($response === false) {
    app_json_response(502, ['error' => '所有 API 节点均不可用', 'detail' => $lastError]);
    exit;
}

$statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$responseHeaders = substr($response, 0, $headerSize);
$responseBody = substr($response, $headerSize);
curl_close($ch);

http_response_code($statusCode);
foreach (explode("\r\n", $responseHeaders) as $headerLine) {
    if ($headerLine === '' || stripos($headerLine, 'HTTP/') === 0) {
        continue;
    }
    if (stripos($headerLine, 'Transfer-Encoding:') === 0) {
        continue;
    }
    if (stripos($headerLine, 'Content-Length:') === 0) {
        continue;
    }
    header($headerLine, false);
}

echo $responseBody;

function app_rebuild_multipart_payload(): array
{
    $boundary = '--------------------------' . bin2hex(random_bytes(12));
    $eol = "\r\n";
    $body = '';

    foreach ($_POST as $key => $value) {
        $body .= '--' . $boundary . $eol;
        $body .= sprintf('Content-Disposition: form-data; name="%s"%s%s', $key, $eol, $eol);
        $body .= $value . $eol;
    }

    foreach ($_FILES as $fieldName => $fileInfo) {
        $normalized = app_normalize_uploaded_files($fileInfo);
        foreach ($normalized as $file) {
            if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                continue;
            }
            $binary = file_get_contents((string) $file['tmp_name']);
            if ($binary === false) {
                throw new RuntimeException('Failed to read uploaded file.');
            }
            $body .= '--' . $boundary . $eol;
            $body .= sprintf(
                'Content-Disposition: form-data; name="%s[]"; filename="%s"%s',
                addcslashes($fieldName, "\"\\"),
                addcslashes((string) $file['name'], "\"\\"),
                $eol
            );
            $body .= 'Content-Type: ' . app_detect_mime_from_path((string) $file['tmp_name'], (string) $file['name']) . $eol . $eol;
            $body .= $binary . $eol;
        }
    }

    $body .= '--' . $boundary . '--' . $eol;
    return [$body, 'multipart/form-data; boundary=' . $boundary];
}
