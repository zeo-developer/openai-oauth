<?php

declare(strict_types=1);

namespace OpenAIOAuth;

final class CodexClient
{
    private const DEFAULT_BASE_URL = 'https://chatgpt.com/backend-api/codex';

    private Auth $auth;
    private string $baseUrl;

    public function __construct(Auth $auth, ?string $baseUrl = null)
    {
        $this->auth = $auth;
        $this->baseUrl = rtrim($baseUrl ?: self::DEFAULT_BASE_URL, '/');
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function responses(array $body): array
    {
        $body['store'] = $body['store'] ?? false;
        $result = $this->request('/responses', 'POST', $body);

        if (!is_array($result['json'] ?? null)) {
            throw new \RuntimeException('Unexpected responses payload');
        }

        return $result['json'];
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>|null}
     */
    public function request(string $path, string $method = 'GET', ?array $jsonBody = null): array
    {
        $tokens = $this->auth->loadTokens(true);
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $headers = [
            'authorization: Bearer ' . $tokens['accessToken'],
            'chatgpt-account-id: ' . $tokens['accountId'],
            'openai-beta: responses=experimental',
        ];

        $payload = null;
        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($payload)) {
                throw new \RuntimeException('Failed to encode JSON body');
            }
            $headers[] = 'content-type: application/json';
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => function ($curl, string $headerLine) use (&$responseHeaders): int {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
            CURLOPT_TIMEOUT => 60,
        ]);

        if (is_string($payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($body)) {
            throw new \RuntimeException('Request failed: ' . $error);
        }

        $json = json_decode($body, true);
        if ($status >= 400) {
            throw new \RuntimeException('Upstream error ' . $status . ': ' . $body);
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $body,
            'json' => is_array($json) ? $json : null,
        ];
    }
}
