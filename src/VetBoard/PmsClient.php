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

    /**
     * Looks up several registration numbers at once (a few at a time) so a long list does not take minutes.
     * @param list<string> $regNos
     * @return array<string, array> keyed by the number asked for, in the same shape as registration()
     */
    public function registrations(array $regNos, int $concurrency = 6): array
    {
        $out = [];

        if ($this->token === null || !$this->safeBase()) {
            foreach ($regNos as $r) {
                $out[$r] = $this->result(self::UNAVAILABLE, null, 'PMS API is not configured', 0, 0);
            }

            return $out;
        }

        $secure = str_starts_with($this->base, 'https://');
        $mh     = curl_multi_init();
        // A fixed number of connections is kept busy and reused, so the secure handshake is paid once, not per lookup.
        curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, max(1, $concurrency));
        $hs = [];

        foreach (array_map('strval', array_values(array_unique($regNos))) as $reg) {
            $ch = curl_init($this->base . '/registration/search?' . http_build_query(['reg_number' => strtoupper(trim($reg))]));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => $secure ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS),
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_ENCODING       => '',
                CURLOPT_TCP_KEEPALIVE  => 1,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token, 'Accept: application/json', 'User-Agent: KVB-CPD-Attendance/1.0'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[$reg] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($hs as $reg => $ch) {
            $body = curl_multi_getcontent($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ms   = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $json = json_decode((string) $body, true);

            if ($http === 0 || $body === '' || $body === null) {
                $out[$reg] = $this->result(self::UNAVAILABLE, null, 'No answer from PMS', $http, $ms);
            } elseif (in_array($http, [401, 403], true)) {
                $out[$reg] = $this->result(self::UNAVAILABLE, null, 'PMS refused the access token', $http, $ms);
            } elseif (is_array($json) && ($json['success'] ?? null) === true && $http === 200) {
                $d = $json['data'] ?? [];
                $out[$reg] = $this->result(self::FOUND, is_array($d) ? $d : [], null, $http, $ms);
            } elseif (is_array($json) && ($json['success'] ?? null) === false && in_array($http, [200, 404, 422], true)) {
                $out[$reg] = $this->result(self::NOT_FOUND, null, (string) ($json['message'] ?? 'Not found'), $http, $ms);
            } else {
                $out[$reg] = $this->result(self::UNAVAILABLE, null, 'PMS returned HTTP ' . $http, $http, $ms);
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        return $out;
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

    /**
     * Uploads CPD points. A write is never retried: a repeat could credit the points twice.
     * @return array{status: string, json: ?array, message: ?string, http: int, ms: int}
     */
    public function uploadPoints(array $records, string $providerName = ''): array
    {
        if ($this->token === null || !$this->safeBase()) {
            return ['status' => self::UNAVAILABLE, 'json' => null, 'message' => 'PMS API is not configured', 'http' => 0, 'ms' => 0];
        }

        $secure  = str_starts_with($this->base, 'https://');
        $payload = ['records' => array_values($records)];
        if ($providerName !== '') {
            $payload['provider_name'] = $providerName;
        }

        $start = microtime(true);
        $ch    = curl_init($this->base . '/cpd-points/upload-points');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => $secure ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: KVB-CPD-Attendance/1.0',
            ],
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $ms   = (int) round((microtime(true) - $start) * 1000);

        if ($body === false) {
            error_log('PMS upload-points failed after ' . $ms . 'ms: ' . $err);

            return ['status' => self::UNAVAILABLE, 'json' => null, 'message' => 'No answer from PMS: ' . $err, 'http' => $http, 'ms' => $ms];
        }

        $json = json_decode((string) $body, true);

        if (!is_array($json) || !array_key_exists('success', $json)) {
            return ['status' => self::UNAVAILABLE, 'json' => null, 'message' => 'Unexpected reply (HTTP ' . $http . ')', 'http' => $http, 'ms' => $ms];
        }

        return ['status' => self::FOUND, 'json' => $json, 'message' => isset($json['message']) ? (string) $json['message'] : null, 'http' => $http, 'ms' => $ms];
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
        $errno = curl_errno($ch);
        $err  = curl_error($ch);

        // One quick retry for transient faults (DNS/connect failure, 429, 5xx). Timeouts are not retried.
        if (($body === false && in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_RECV_ERROR, CURLE_SEND_ERROR], true))
            || ($body !== false && ($http === 429 || $http >= 500))) {
            usleep(300000);
            $body  = curl_exec($ch);
            $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err   = curl_error($ch);
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($body === false) {
            error_log('PMS ' . $path . ' failed after ' . $ms . 'ms: ' . $err);

            return $this->result(self::UNAVAILABLE, null, 'PMS API unreachable', $http, $ms);
        }

        if ($http === 401 || $http === 403) {
            error_log('PMS ' . $path . ' rejected the token (HTTP ' . $http . ')');

            return $this->result(self::UNAVAILABLE, null, 'PMS API authentication failed', $http, $ms);
        }

        // A missing record may come back as HTTP 404/422 with the usual JSON body rather than HTTP 200.
        if (($http === 404 || $http === 422) && is_array($nf = json_decode((string) $body, true)) && ($nf['success'] ?? null) === false) {
            return $this->result(self::NOT_FOUND, null, (string) ($nf['message'] ?? 'Not found'), $http, $ms);
        }

        if ($http !== 200) {
            error_log('PMS ' . $path . ' returned HTTP ' . $http . ' in ' . $ms . 'ms');

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
