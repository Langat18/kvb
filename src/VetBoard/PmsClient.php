<?php

declare(strict_types=1);

namespace VetBoard;

final class PmsClient
{
    public const FOUND       = 'found';
    public const NOT_FOUND   = 'not_found';
    public const UNAVAILABLE = 'unavailable';

    private const DEFAULT_BASE = 'https://mms.kenyavetboard.or.ke/api';

    private string $base;
    private ?string $token;
    private int $timeout;
    private int $connectTimeout;

    public function __construct(?string $base = null, ?string $token = null, ?int $timeout = null, ?int $connectTimeout = null)
    {
        $this->base           = rtrim($base ?? (string) self::setting('PMS_API_BASE', self::DEFAULT_BASE), '/');
        $this->token          = $token ?? self::setting('PMS_API_TOKEN');
        $this->timeout        = max(1, $timeout ?? (int) self::setting('PMS_API_TIMEOUT', '5'));
        $this->connectTimeout = max(1, $connectTimeout ?? (int) self::setting('PMS_API_CONNECT_TIMEOUT', '3'));
    }

    public function configured(): bool
    {
        return $this->token !== null && $this->safeBase();
    }


    public function registration(string $regNo): array
    {
        return $this->lookup('/registration/search', 'reg_number', strtoupper(trim($regNo)));
    }

    public function provider(string $accredNum): array
    {
        return $this->lookup('/cpd-provider/search', 'accred_num', trim($accredNum));
    }

    public function activity(string $activityNumber): array
    {
        return $this->lookup('/cpd-activity/search', 'activity_number', trim($activityNumber));
    }

    public function providers(): array
    {
        return $this->request('/cpd-provider/index', [], 30);
    }

    public function activities(): array
    {
        return $this->request('/cpd-activity/index', [], 30);
    }

    private function lookup(string $path, string $param, string $value): array
    {
        if (!preg_match('/^[A-Za-z0-9\-\/ ._]{1,60}$/', $value)) {
            return $this->result(self::NOT_FOUND, null, 'Invalid lookup value', 0, 0);
        }

        return $this->request($path, [$param => $value], $this->timeout);
    }

    private function request(string $path, array $params, int $timeout): array
    {
        if ($this->token === null || !$this->safeBase()) {
            return $this->result(self::UNAVAILABLE, null, 'PMS API is not configured', 0, 0);
        }

        $secure = str_starts_with($this->base, 'https://');
        $url    = $this->base . $path . ($params ? '?' . http_build_query($params) : '');
        $start  = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => $secure ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
                'User-Agent: KVB-CPD-Attendance/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $ms   = (int) round((microtime(true) - $start) * 1000);

        if ($body === false) {
            error_log('PMS ' . $path . ' failed: ' . $err);

            return $this->result(self::UNAVAILABLE, null, 'PMS API unreachable', $http, $ms);
        }

        if ($http === 401 || $http === 403) {
            error_log('PMS ' . $path . ' rejected the token (HTTP ' . $http . ')');

            return $this->result(self::UNAVAILABLE, null, 'PMS API authentication failed', $http, $ms);
        }

        if ($http !== 200) {
            error_log('PMS ' . $path . ' returned HTTP ' . $http);

            return $this->result(self::UNAVAILABLE, null, 'PMS API returned HTTP ' . $http, $http, $ms);
        }

        $json = json_decode((string) $body, true);

        if (!is_array($json) || !array_key_exists('success', $json)) {
            error_log('PMS ' . $path . ' returned an unexpected response');

            return $this->result(self::UNAVAILABLE, null, 'PMS API returned an unexpected response', $http, $ms);
        }

        if ($json['success'] === true) {
            $data = $json['data'] ?? [];

            return $this->result(self::FOUND, is_array($data) ? $data : [], null, $http, $ms);
        }

        return $this->result(self::NOT_FOUND, null, (string) ($json['message'] ?? 'Not found'), $http, $ms);
    }

    private function result(string $status, ?array $data, ?string $message, int $http, int $ms): array
    {
        return ['status' => $status, 'data' => $data, 'message' => $message, 'http' => $http, 'ms' => $ms];
    }

    private function safeBase(): bool
    {
        return str_starts_with($this->base, 'https://')
            || (bool) preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#', $this->base);
    }

    private static function setting(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return ($value !== false && $value !== null && $value !== '') ? (string) $value : $default;
    }
}
