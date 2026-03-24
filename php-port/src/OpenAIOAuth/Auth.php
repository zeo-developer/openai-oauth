<?php

declare(strict_types=1);

namespace OpenAIOAuth;

final class Auth
{
    private const DEFAULT_CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';
    private const DEFAULT_ISSUER = 'https://auth.openai.com';
    private const AUTH_FILENAME = 'auth.json';
    private const REFRESH_EXPIRY_MARGIN_SECONDS = 300;
    private const REFRESH_INTERVAL_SECONDS = 3300;

    private string $clientId;
    private string $tokenUrl;
    private ?string $authFilePath;

    public function __construct(?string $authFilePath = null, ?string $clientId = null, ?string $tokenUrl = null)
    {
        $this->authFilePath = $authFilePath;
        $this->clientId = $clientId ?: self::DEFAULT_CLIENT_ID;
        $issuer = rtrim(getenv('CHATGPT_LOCAL_ISSUER') ?: self::DEFAULT_ISSUER, '/');
        $this->tokenUrl = $tokenUrl ?: ($issuer . '/oauth/token');
    }

    /**
     * @return array{accessToken:string, accountId:string, idToken:?string, refreshToken:?string, sourcePath:?string}
     */
    public function loadTokens(bool $ensureFresh = true): array
    {
        [$path, $data] = $this->readAuthFile();

        $tokens = isset($data['tokens']) && is_array($data['tokens']) ? $data['tokens'] : [];
        $accessToken = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : null;
        $refreshToken = is_string($tokens['refresh_token'] ?? null) ? $tokens['refresh_token'] : null;
        $idToken = is_string($tokens['id_token'] ?? null) ? $tokens['id_token'] : null;
        $accountId = is_string($tokens['account_id'] ?? null) ? $tokens['account_id'] : null;
        $lastRefresh = is_string($data['last_refresh'] ?? null) ? $data['last_refresh'] : null;

        if ($ensureFresh && $this->shouldRefresh($accessToken, $lastRefresh)) {
            if (!$refreshToken) {
                throw new \RuntimeException('Missing refresh token in auth.json. Run codex login first.');
            }

            $refreshed = $this->refresh($refreshToken);
            $accessToken = $refreshed['access_token'] ?? $accessToken;
            $idToken = $refreshed['id_token'] ?? $idToken;
            $refreshToken = $refreshed['refresh_token'] ?? $refreshToken;
            $accountId = $accountId ?: $this->deriveAccountId($idToken);

            $tokens['access_token'] = $accessToken;
            $tokens['id_token'] = $idToken;
            $tokens['refresh_token'] = $refreshToken;
            if ($accountId) {
                $tokens['account_id'] = $accountId;
            }

            $data['tokens'] = $tokens;
            $data['last_refresh'] = gmdate('c');
            $this->writeAuthFile($path, $data);
        }

        if (!$accessToken) {
            throw new \RuntimeException('Missing access token in auth file.');
        }

        $accountId = $accountId ?: $this->deriveAccountId($idToken);
        if (!$accountId) {
            throw new \RuntimeException('Could not derive account id from tokens.');
        }

        return [
            'accessToken' => $accessToken,
            'accountId' => $accountId,
            'idToken' => $idToken,
            'refreshToken' => $refreshToken,
            'sourcePath' => $path,
        ];
    }

    private function shouldRefresh(?string $accessToken, ?string $lastRefresh): bool
    {
        if (!$accessToken) {
            return true;
        }

        $claims = $this->parseJwtClaims($accessToken);
        if (is_array($claims) && isset($claims['exp']) && is_numeric($claims['exp'])) {
            $exp = (int) $claims['exp'];
            if ($exp <= time() + self::REFRESH_EXPIRY_MARGIN_SECONDS) {
                return true;
            }
        }

        if ($lastRefresh) {
            $ts = strtotime($lastRefresh);
            if ($ts !== false) {
                return $ts <= time() - self::REFRESH_INTERVAL_SECONDS;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function refresh(string $refreshToken): array
    {
        $payload = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'scope' => 'openid profile email offline_access',
        ]);

        $ch = curl_init($this->tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['content-type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($resp)) {
            throw new \RuntimeException('OAuth refresh failed: ' . $err);
        }

        $json = json_decode($resp, true);
        if (!is_array($json) || $status >= 400) {
            throw new \RuntimeException('OAuth refresh failed: HTTP ' . $status . ' ' . $resp);
        }

        return $json;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function readAuthFile(): array
    {
        foreach ($this->resolveCandidates() as $path) {
            if (!is_file($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                continue;
            }
            $json = json_decode($contents, true);
            if (is_array($json)) {
                return [$path, $json];
            }
        }

        throw new \RuntimeException('Cannot find a valid auth.json. Run `npx @openai/codex login`.');
    }

    /** @return list<string> */
    private function resolveCandidates(): array
    {
        if ($this->authFilePath) {
            return [$this->authFilePath];
        }

        $paths = [];
        $home = getenv('CHATGPT_LOCAL_HOME');
        $codex = getenv('CODEX_HOME');
        if (is_string($home) && $home !== '') {
            $paths[] = rtrim($home, '/') . '/' . self::AUTH_FILENAME;
        }
        if (is_string($codex) && $codex !== '') {
            $paths[] = rtrim($codex, '/') . '/' . self::AUTH_FILENAME;
        }

        $userHome = rtrim((string) getenv('HOME'), '/');
        $paths[] = $userHome . '/.chatgpt-local/' . self::AUTH_FILENAME;
        $paths[] = $userHome . '/.codex/' . self::AUTH_FILENAME;

        return array_values(array_unique($paths));
    }

    /** @param array<string,mixed> $data */
    private function writeAuthFile(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create auth directory: ' . $dir);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($path, $json . PHP_EOL) === false) {
            throw new \RuntimeException('Cannot write auth file: ' . $path);
        }
    }

    /** @return array<string,mixed>|null */
    private function parseJwtClaims(?string $token): ?array
    {
        if (!$token || substr_count($token, '.') !== 2) {
            return null;
        }

        $parts = explode('.', $token);
        if (!isset($parts[1])) {
            return null;
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);
        if (!is_string($decoded)) {
            return null;
        }

        $json = json_decode($decoded, true);
        return is_array($json) ? $json : null;
    }

    private function deriveAccountId(?string $idToken): ?string
    {
        $claims = $this->parseJwtClaims($idToken);
        if (!is_array($claims)) {
            return null;
        }

        $auth = $claims['https://api.openai.com/auth'] ?? null;
        if (!is_array($auth)) {
            return null;
        }

        $accountId = $auth['chatgpt_account_id'] ?? null;
        return is_string($accountId) && $accountId !== '' ? $accountId : null;
    }
}
