<?php

declare(strict_types=1);

namespace Namingo\Whmcs;

use RuntimeException;

final class ApiClient
{
    private string $token = '';
    private string $url;

    public function __construct(private array $config, private $transport = null, private ?Store $store = null)
    {
        $this->url = rtrim(trim((string) ($config['APIURL'] ?? '')), '/');
        $parts = parse_url($this->url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Namingo API URL must be an HTTPS base URL without credentials, query or fragment.');
        }
        if (empty($config['APIUsername']) || empty($config['APIPassword'])) {
            throw new RuntimeException('Enter the reseller API username and password.');
        }
    }

    public function identity(): string
    {
        return hash('sha256', $this->url . '|' . $this->config['APIUsername']);
    }

    public function request(string $method, string $path, ?array $body = null, ?string $key = null): array
    {
        $cacheKey = hash('sha256', $this->identity() . '|token|' . $this->config['APIPassword']);
        if ($this->token === '') {
            $this->store?->lock($cacheKey);
            try {
                $cached = $this->store?->get($cacheKey);
                if (($cached['expires'] ?? 0) > time() + 30) { $this->token = $cached['token']; }
                if ($this->token === '') {
                    $login = $this->send('POST', '/v1/login', [
                        'username' => $this->config['APIUsername'], 'password' => $this->config['APIPassword'],
                    ]);
                    $this->token = (string) ($login['access_token'] ?? '');
                    if ($this->token === '') { throw new RuntimeException('Namingo API did not return an access token.'); }
                    $this->store?->put($cacheKey, ['token' => $this->token, 'expires' => time() + (int) ($login['expires_in'] ?? 0)]);
                }
            } finally { $this->store?->unlock($cacheKey); }
        }
        try { return $this->send($method, $path, $body, $key); }
        catch (RuntimeException $error) {
            if ($error->getCode() === 401) {
                $this->token = '';
                $this->store?->remove($cacheKey);
            }
            throw $error;
        }
    }

    private function send(string $method, string $path, ?array $body, ?string $key = null): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($this->token !== '') { $headers[] = 'Authorization: Bearer ' . $this->token; }
        if ($key !== null) { $headers[] = 'Idempotency-Key: ' . $key; }
        $json = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        if ($this->transport !== null) {
            [$status, $response] = ($this->transport)($method, $this->url . $path, $headers, $json);
        } else {
            if (!function_exists('curl_init')) { throw new RuntimeException('Namingo requires the PHP cURL extension.'); }
            $handle = curl_init($this->url . $path);
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            if ($json !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, $json); }
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if ($response === false) {
                throw new RuntimeException('Namingo API connection failed or timed out. Retry the same WHMCS action to resume its saved request.');
            }
        }
        try { $decoded = json_decode((string) $response, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Namingo returned an invalid JSON response. Retry the same action.'); }
        if ($status < 200 || $status >= 300 || !is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            // Do not log credentials, contact details, auth codes or raw registry responses.
            $code = $decoded['error']['code'] ?? 'invalid_response';
            $code = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $code);
            throw new RuntimeException('Namingo API error (HTTP ' . $status . ', ' . $code . '). Check account access, balance and API configuration.', (int) $status);
        }
        return $decoded['data'];
    }

    public function job(string $registry, string $command, array $params, ?string $key = null): array
    {
        return $this->request('POST', '/v1/epp/' . $command, ['registry_uuid' => $registry, 'params' => $params], $key);
    }

    public function await(array $job): array
    {
        // The POST already waits up to the API sync timeout; bound additional polling.
        $until = microtime(true) + 8;
        while (in_array($job['status'] ?? '', ['queued', 'processing'], true) && microtime(true) < $until) {
            usleep(500000);
            $job = $this->request('GET', '/v1/jobs/' . rawurlencode((string) $job['job_uuid']));
        }
        return $job;
    }

    public static function result(array $job, bool $allowPending = false): array
    {
        if (($job['status'] ?? '') === 'succeeded' || ($allowPending && ($job['status'] ?? '') === 'pending')) {
            if (is_array($job['result'] ?? null)) { return $job['result']; }
        }
        $id = (string) ($job['job_uuid'] ?? 'unavailable');
        $status = (string) ($job['status'] ?? 'invalid');
        throw new RuntimeException("Namingo job {$id}: {$status} (EPP " . ($job['epp_code'] ?? 'n/a')
            . '). Retry this action to check the same job; unknown outcomes require registrar reconciliation.');
    }
}
