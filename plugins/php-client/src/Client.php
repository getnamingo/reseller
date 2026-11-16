<?php

declare(strict_types=1);

namespace Namingo\ResellerClient;

final class Client
{
    private string $baseUrl;
    private string $token = '';
    private ?int $expiresAt = null;
    private TransportInterface $transport;

    public function __construct(string $baseUrl, private readonly ?string $username = null,
        #[\SensitiveParameter] private readonly ?string $password = null,
        ?TransportInterface $transport = null, private readonly float $timeoutSeconds = 40)
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($this->baseUrl);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $this->baseUrl)) {
            throw new \InvalidArgumentException('Use an HTTPS API base URL without credentials, query or fragment.');
        }
        if (($username === null) !== ($password === null) || $username === '' || $password === '') {
            throw new \InvalidArgumentException('Supply both username and API password, or neither when using an existing token.');
        }
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0) { throw new \InvalidArgumentException('Request timeout must be positive.'); }
        $this->transport = $transport ?? new CurlTransport();
    }

    public function __debugInfo(): array { return ['baseUrl' => $this->baseUrl, 'authenticated' => $this->token !== '', 'credentials' => '[redacted]']; }

    public static function newIdempotencyKey(): string { return 'app-' . bin2hex(random_bytes(24)); }

    private static function key(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $key)) {
            throw new \InvalidArgumentException('Idempotency keys/references require 8–128 safe ASCII characters.');
        }
    }

    private static function uuid(string $uuid): string
    {
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $uuid)) {
            throw new \InvalidArgumentException('A valid UUID is required.');
        }
        return strtolower($uuid);
    }

    public function login(): array
    {
        if ($this->username === null || $this->password === null) { throw new \LogicException('No login credentials configured; supply a valid access token.'); }
        $data = $this->exchange('POST', '/v1/login', ['username' => $this->username, 'password' => $this->password], [], [], false)->data();
        if (!is_string($data['access_token'] ?? null) || !preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $data['access_token'])
            || !is_int($data['expires_in'] ?? null) || $data['expires_in'] < 1) {
            throw new ProtocolException('Login did not return a valid access token and expiry.');
        }
        $this->setAccessToken($data['access_token'], time() + $data['expires_in']);
        return $data;
    }

    public function setAccessToken(#[\SensitiveParameter] string $token, ?int $expiresAt = null): void
    {
        if (!preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token)) { throw new \InvalidArgumentException('Invalid bearer token.'); }
        $this->token = $token;
        $this->expiresAt = $expiresAt;
    }

    /** Sensitive: persist only in an application-controlled encrypted store, never logs. */
    public function session(): ?array
    {
        return $this->token === '' ? null : ['access_token' => $this->token, 'expires_at' => $this->expiresAt];
    }

    public function clearSession(): void { $this->token = ''; $this->expiresAt = null; }

    public function logout(): void
    {
        if ($this->token === '') { return; }
        try { $this->exchange('POST', '/v1/logout', null, [], [], false); }
        finally { $this->clearSession(); }
    }

    private function authenticate(): void
    {
        if ($this->token === '' || ($this->expiresAt !== null && $this->expiresAt <= time())) {
            $this->clearSession();
            $this->login();
        }
    }

    /** Low-level escape hatch; paths are relative to this API, never arbitrary URLs. */
    public function request(string $method, string $path, #[\SensitiveParameter] ?array $body = null, array $query = [], ?string $idempotencyKey = null): Response
    {
        if ($idempotencyKey !== null) { self::key($idempotencyKey); }
        return $this->exchange(strtoupper($method), $path, $body, $query,
            $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey]);
    }

    private function exchange(string $method, string $path, #[\SensitiveParameter] ?array $body, array $query,
        array $extraHeaders, bool $authenticated = true, ?float $timeout = null): Response
    {
        if (!in_array($method, ['GET', 'POST'], true) || !preg_match('#^/v1/[A-Za-z0-9._/-]+$#D', $path)
            || str_contains($path, '..') || str_contains($path, '//')) {
            throw new \InvalidArgumentException('Use GET or POST with a relative /v1/ API path.');
        }
        if ($method === 'GET' && $body !== null) { throw new \InvalidArgumentException('GET requests cannot have a JSON body.'); }
        foreach ($query as $value) {
            if ($value !== null && !is_scalar($value)) { throw new \InvalidArgumentException('Query filters must be scalar values.'); }
        }
        $json = $body === null ? null : json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR);
        if ($authenticated) { $this->authenticate(); }
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'User-Agent' => 'Namingo-Reseller-PHP/1.0'] + $extraHeaders;
        if ($this->token !== '') { $headers['Authorization'] = 'Bearer ' . $this->token; }
        $url = $this->baseUrl . $path;
        if ($query !== []) { $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986); }
        $http = $this->transport->send($method, $url, $headers, $json, $timeout ?? $this->timeoutSeconds);
        if ($http->status === 401) { $this->clearSession(); }
        try { $decoded = json_decode($http->body, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) {
            if ($http->status < 200 || $http->status >= 300) {
                throw new ApiException($http->status, 'invalid_response', 'Non-JSON HTTP error response.', [], $http->headers['x-request-id'] ?? null, $http->headers);
            }
            throw new ProtocolException('The API returned invalid JSON; a submitted write may still have been accepted.');
        }
        if ($http->status < 200 || $http->status >= 300) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            throw new ApiException($http->status, is_string($error['code'] ?? null) ? $error['code'] : 'http_error',
                is_string($error['message'] ?? null) ? $error['message'] : 'Namingo API request failed.',
                is_array($error['details'] ?? null) ? $error['details'] : [],
                is_string($decoded['request_id'] ?? null) ? $decoded['request_id'] : ($http->headers['x-request-id'] ?? null), $http->headers);
        }
        if (!is_array($decoded) || !is_array($decoded['data'] ?? null) || isset($decoded['error'])
            || (isset($decoded['pagination']) && !is_array($decoded['pagination']))
            || (isset($decoded['request_id']) && !is_string($decoded['request_id']))) {
            throw new ProtocolException('The API returned an invalid response envelope; a submitted write may still have been accepted.');
        }
        return new Response($http->status, $decoded, $http->headers);
    }

    public function account(): array { return $this->request('GET', '/v1/account')->data(); }
    public function registries(): array { return $this->request('GET', '/v1/registries')->data(); }
    public function commands(): array { return $this->request('GET', '/v1/commands')->data(); }
    public function prices(array $filters = []): Response { return $this->request('GET', '/v1/prices', null, $filters); }
    public function domains(array $filters = []): Response { return $this->request('GET', '/v1/domains', null, $filters); }
    public function jobs(array $filters = []): Response { return $this->request('GET', '/v1/jobs', null, $filters); }
    public function ledger(int $limit = 50): array { return $this->request('GET', '/v1/ledger', null, ['limit' => $limit])->data(); }
    public function deposits(int $limit = 50): array { return $this->request('GET', '/v1/deposits', null, ['limit' => $limit])->data(); }

    public function requestDeposit(string $amount, string $currency, string $reference): array
    {
        self::key($reference);
        return $this->request('POST', '/v1/deposits', ['amount' => $amount, 'currency' => strtoupper($currency), 'reference' => $reference], [], $reference)->data();
    }

    /** Native params are passed unchanged. Unknown commands default to requiring a write key. */
    public function epp(string $registryUuid, string $command, #[\SensitiveParameter] array $params, ?string $idempotencyKey = null, bool $respondAsync = false): array
    {
        $registryUuid = self::uuid($registryUuid);
        if (!preg_match('/^[a-z][a-z0-9._-]*$/D', $command)) { throw new \InvalidArgumentException('Invalid command name.'); }
        $read = in_array($command, ['hello', 'domain.check', 'domain.info', 'contact.check', 'contact.info', 'host.check', 'host.info'], true)
            || ($command === 'domain.transfer' && ($params['op'] ?? '') === 'query');
        if (!$read && $idempotencyKey === null) { throw new \InvalidArgumentException('Writes require an application-persisted idempotency key. Save the key and exact payload before submitting.'); }
        if ($idempotencyKey !== null) { self::key($idempotencyKey); }
        if ($respondAsync && $command !== 'domain.check') { throw new \InvalidArgumentException('respond-async is supported only for domain.check.'); }
        $headers = $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey];
        if ($respondAsync) { $headers['Prefer'] = 'respond-async'; }
        $job = $this->exchange('POST', '/v1/epp/' . $command,
            ['registry_uuid' => $registryUuid, 'params' => $params === [] ? new \stdClass() : $params], [], $headers)->data();
        self::validateJob($job);
        return $job;
    }

    public function job(string $jobUuid): array
    {
        $job = $this->request('GET', '/v1/jobs/' . self::uuid($jobUuid))->data();
        self::validateJob($job);
        return $job;
    }

    public function reconcile(string $jobUuid): array { return $this->request('POST', '/v1/jobs/' . self::uuid($jobUuid) . '/reconcile')->data(); }

    /** Returns the latest job on timeout; pending/unknown/rejected never mean success. */
    public function waitForJob(#[\SensitiveParameter] array|string $job, float $timeoutSeconds = 30, float $pollSeconds = 1, bool $waitForPending = false): array
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || !is_finite($pollSeconds) || $pollSeconds <= 0) {
            throw new \InvalidArgumentException('Polling timeout and interval must be positive.');
        }
        $this->authenticate();
        $deadline = hrtime(true) / 1e9 + $timeoutSeconds;
        if (is_string($job)) {
            $job = $this->exchange('GET', '/v1/jobs/' . self::uuid($job), null, [], [], true, min($this->timeoutSeconds, $timeoutSeconds))->data();
        }
        self::validateJob($job);
        $polling = $waitForPending ? ['queued', 'processing', 'pending'] : ['queued', 'processing'];
        while (in_array($job['status'], $polling, true)) {
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) { break; }
            usleep(max(1, (int) (min($pollSeconds, $remaining) * 1000000)));
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) { break; }
            $job = $this->exchange('GET', '/v1/jobs/' . $job['job_uuid'], null, [], [], true, min($this->timeoutSeconds, $remaining))->data();
            self::validateJob($job);
        }
        return $job;
    }

    private static function validateJob(#[\SensitiveParameter] array $job): void
    {
        if (!is_string($job['job_uuid'] ?? null) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $job['job_uuid'])
            || !in_array($job['status'] ?? '', ['queued', 'processing', 'pending', 'succeeded', 'rejected', 'unknown'], true)) {
            throw new ProtocolException('The API returned an invalid job reference/status.');
        }
    }

    public static function assertSucceeded(#[\SensitiveParameter] array $job): array
    {
        self::validateJob($job);
        if ($job['status'] !== 'succeeded') {
            throw new JobException($job['job_uuid'], $job['status'], isset($job['epp_code']) ? (int) $job['epp_code'] : null);
        }
        if (!is_array($job['result'] ?? null)) { throw new ProtocolException('Succeeded job has no result object.'); }
        return $job['result'];
    }

    /** Lazy listing of paginated resources; other list endpoints only support a limit. */
    public function iterate(string $resource, array $filters = [], int $pageSize = 100): \Generator
    {
        if (!in_array($resource, ['prices', 'domains', 'jobs'], true) || $pageSize < 1 || $pageSize > 100) {
            throw new \InvalidArgumentException('Iterate prices/domains/jobs with a page size of 1–100.');
        }
        $offset = $filters['offset'] ?? 0;
        if (!is_int($offset) || $offset < 0 || $offset > 1000000) { throw new \InvalidArgumentException('Invalid listing offset.'); }
        do {
            $page = $this->$resource(array_replace($filters, ['limit' => $pageSize, 'offset' => $offset]));
            $pagination = $page->pagination();
            if (!is_array($pagination) || !is_bool($pagination['has_more'] ?? null)) { throw new ProtocolException('Missing listing pagination metadata.'); }
            foreach ($page->data() as $row) { yield $row; }
            if (!$pagination['has_more']) { return; }
            $next = $pagination['next_offset'] ?? null;
            if (!is_int($next) || $next <= $offset || $next > 1000000 || $page->data() === []) { throw new ProtocolException('Invalid next page offset.'); }
            $offset = $next;
        } while (true);
    }
}
