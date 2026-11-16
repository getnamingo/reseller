<?php

declare(strict_types=1);

use Namingo\FossBilling\Store;
use Namingo\ResellerApi\CommandCatalog;

final class MemoryStore extends Store
{
    public array $rows = [];
    public function __construct() {}
    public function lock(string $key): void {}
    public function unlock(string $key): void {}
    public function get(string $key): ?array { return $this->rows[$key] ?? null; }
    public function put(string $key, array $payload): void { $this->rows[$key] = $payload; }
    public function remove(string $key): void { unset($this->rows[$key]); }
}

final class Fixture
{
    public const UUID = '11111111-1111-4111-8111-111111111111';
    public array $posts = [];
    public array $jobs = [];
    public array $keys = [];
    public int $logins = 0;
    public int $reads = 0;
    public string $profile = 'generic';
    public ?string $timeout = null;
    public ?string $reject = null;
    public string $transferStatus = 'pending';
    public string $transferJobStatus = 'pending';
    public bool $available = true;
    public bool $denyContacts = false;
    public bool $failRead = false;
    public bool $denyToken = false;
    public ?int $rejectSubmission = null;
    public array $info = ['name' => 'example.com', 'exDate' => '2027-12-31T00:00:00Z',
        'status' => [1 => 'ok'], 'ns' => [1 => 'ns1.example.net', 2 => 'ns2.example.net'],
        'registrant' => 'C1', 'contact' => [1 => ['type' => 'admin', 'id' => 'C2']], 'authInfo' => 'Secret1!'];

    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        $path = parse_url($url, PHP_URL_PATH);
        $data = $body === null ? [] : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $result = [];
        if ($path === '/v1/login') { ++$this->logins; $result = ['access_token' => 'token', 'expires_in' => 3600]; }
        elseif ($path === '/v1/registries') {
            if ($this->denyToken) { $this->denyToken = false; return [401, '{"error":{"code":"invalid_token"}}']; }
            $result = [['registry_uuid' => self::UUID, 'name' => 'Test registry', 'profile' => $this->profile]];
        }
        elseif (str_starts_with($path, '/v1/jobs/')) {
            if ($this->failRead) { $this->failRead = false; throw new RuntimeException('Simulated job read outage'); }
            ++$this->reads; $result = $this->jobs[basename($path)];
        }
        elseif (str_starts_with($path, '/v1/epp/')) {
            $command = basename($path);
            CommandCatalog::validateParams($command, $data['params'], $this->profile);
            if ($command === 'contact.info' && $this->denyContacts) { return [403, '{"error":{"code":"object_not_owned"}}']; }
            if ($this->rejectSubmission !== null) {
                $http = $this->rejectSubmission; $this->rejectSubmission = null;
                return [$http, '{"error":{"code":"test_rejection"}}'];
            }
            $key = null;
            foreach ($headers as $header) { if (str_starts_with($header, 'Idempotency-Key: ')) { $key = substr($header, 17); } }
            if (CommandCatalog::isMutating($command, $data['params'])) { check($key !== null, 'Write has idempotency key'); }
            $this->posts[] = ['command' => $command, 'params' => $data['params'], 'key' => $key];
            if ($key !== null && isset($this->keys[$key])) {
                check($this->keys[$key]['payload'] === $data, 'Retry payload is identical');
                $result = $this->jobs[$this->keys[$key]['id']];
            } else {
                $id = 'job-' . (count($this->jobs) + 1);
                $status = $command === $this->reject ? 'rejected' : 'succeeded';
                if ($command === 'domain.transfer' && $data['params']['op'] === 'request') { $status = $this->transferJobStatus; }
                $payload = match ($command) {
                    'domain.info' => $this->info,
                    'domain.check' => ['domains' => [['name' => $data['params']['domains'][0], 'avail' => $this->available]]],
                    'domain.transfer' => ['trStatus' => $this->transferStatus],
                    'contact.info' => ['name' => 'Iliya Test', 'org' => 'Company', 'email' => 'person@example.net', 'country' => 'BG', 'voice' => '+359.888123456', 'street1' => 'Street 1', 'city' => 'Sofia', 'postal' => '1000'],
                    default => ['code' => 1000, 'id' => $data['params']['id'] ?? '', 'name' => 'example.com'],
                };
                $result = ['job_uuid' => $id, 'status' => $status, 'epp_code' => $status === 'rejected' ? 2306 : 1000, 'result' => $payload];
                $this->jobs[$id] = $result;
                if ($key !== null) { $this->keys[$key] = ['payload' => $data, 'id' => $id]; }
            }
            if ($command === $this->timeout) { $this->timeout = null; throw new RuntimeException('Simulated response timeout after API commit'); }
        } else { throw new RuntimeException('Unexpected request: ' . $method . ' ' . $path); }
        return [200, json_encode(['data' => $result], JSON_THROW_ON_ERROR)];
    }

}
