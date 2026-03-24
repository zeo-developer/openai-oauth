#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/OpenAIOAuth/Auth.php';
require_once __DIR__ . '/../src/OpenAIOAuth/CodexClient.php';

use OpenAIOAuth\Auth;
use OpenAIOAuth\CodexClient;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

header('content-type: application/json; charset=utf-8');

try {
    $auth = new Auth(getenv('OAUTH_FILE') ?: null);
    $client = new CodexClient($auth, getenv('CODEX_BASE_URL') ?: null);

    if ($path === '/v1/models' && $method === 'GET') {
        $up = $client->request('/models', 'GET');
        http_response_code($up['status']);
        echo $up['body'];
        exit;
    }

    if (($path === '/v1/responses' || $path === '/responses') && $method === 'POST') {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON payload');
        }

        $up = $client->request('/responses', 'POST', $decoded);
        http_response_code($up['status']);
        echo $up['body'];
        exit;
    }

    http_response_code(404);
    echo json_encode([
        'error' => 'Not found',
        'available' => ['/v1/models [GET]', '/v1/responses [POST]'],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
