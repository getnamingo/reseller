<?php

/*
 * Namingo Reseller Plugins
 *
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the Apache License, Version 2.0.
 * See the LICENSE-APACHE-2.0 file at the repository root
 * for the full license terms.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

// No WHMCS installation, credentials or network access required.
define('WHMCS', true);
require dirname(__DIR__) . '/modules/registrars/namingo/namingo.php';
require dirname(__DIR__, 3) . '/src/Core.php';
require dirname(__DIR__, 3) . '/src/ProfileParams.php';

use Namingo\Whmcs\ApiClient;
use Namingo\Whmcs\Registrar;
use Namingo\Whmcs\Store;
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
    public bool $locked = false;
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
                    'domain.check' => ['domains' => [['name' => $data['params']['domains'][0], 'avail' => true]]],
                    'domain.transfer' => ['trStatus' => $this->transferStatus],
                    'contact.info' => ['name' => 'Iliya Test', 'org' => 'Company', 'email' => 'person@example.net', 'country' => 'BG'],
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

$assertions = 0;
function check(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function fails(callable $call, string $contains): void
{
    try { $call(); } catch (Throwable $error) { check(str_contains($error->getMessage(), $contains), 'Expected error: ' . $contains . '; got ' . $error->getMessage()); return; }
    throw new RuntimeException('Expected failure: ' . $contains);
}
function config(array $extra = []): array
{
    return array_replace(['APIURL' => 'https://api.example.net', 'APIUsername' => 'reseller', 'APIPassword' => 'password',
        'DefaultRegistry' => Fixture::UUID, 'domainid' => 42, 'sld' => 'example', 'tld' => 'com', 'regperiod' => 1,
        'expirydate' => '2027-12-31', 'ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net',
        'firstname' => 'Iliya', 'lastname' => 'Test', 'companyname' => '', 'address1' => 'Street 1', 'address2' => '',
        'city' => 'Sofia', 'state' => '', 'postcode' => '1000', 'countrycode' => 'BG',
        'fullphonenumber' => '+359.888123456', 'email' => 'person@example.net', 'eppcode' => 'Transfer1!'], $extra);
}
function service(Fixture $fixture, Store $store, array $extra = []): Registrar
{
    $params = config($extra);
    return new Registrar($params, new ApiClient($params, $fixture, $store), $store);
}
function operation(Fixture $fixture, string $method, array $extra = []): mixed
{
    return service($fixture, new MemoryStore(), $extra)->locked(fn ($registrar) => $registrar->$method());
}
function posts(Fixture $fixture, string $command): array
{
    return array_values(array_filter($fixture->posts, fn ($row) => $row['command'] === $command));
}

check(namingo_MetaData()['APIVersion'] === '1.1', 'WHMCS metadata uses API 1.1');
check(namingo_getConfigArray()['APIPassword']['Type'] === 'password', 'API password is a secret setting');
fails(fn () => new ApiClient(config(['APIURL' => 'http://example.net'])), 'HTTPS');
fails(fn () => new ApiClient(config(['APIURL' => 'https://user:secret@example.net'])), 'HTTPS');
fails(fn () => Registrar::hostname('a.com</domain:name>'), 'ASCII');
check(Registrar::domain(config(['domain_punycode' => 'xn--bcher-kva.example'])) === 'xn--bcher-kva.example', 'Punycode domain passed unchanged');
check(Registrar::domain(config(['domain_punycode' => '', 'sld_punycode' => '', 'tld_punycode' => ''])) === 'example.com', 'Empty optional punycode fields fall back to ASCII domain');
check(Registrar::registry('example.org.ua', config(['RegistryMap' => '{"ua":"22222222-2222-4222-8222-222222222222","org.ua":"11111111-1111-4111-8111-111111111111"}'])) === Fixture::UUID, 'Longest TLD mapping wins');
fails(fn () => Registrar::registry('example.net', config(['DefaultRegistry' => ''])), 'Configure a registry');

$fixture = new Fixture(); $store = new MemoryStore();
$fixture->timeout = 'domain.create';
fails(fn () => service($fixture, $store)->register(), 'Simulated response timeout');
check(count(posts($fixture, 'contact.create')) === 4, 'Creates four owned contacts before registration');
check(service($fixture, $store)->register() === [], 'Registration retry succeeds');
$creates = posts($fixture, 'domain.create');
check(count($creates) === 2 && $creates[0]['key'] === $creates[1]['key'], 'Timeout retries use same registration idempotency key');
check($creates[0]['params'] === $creates[1]['params'], 'Timeout retries preserve auth code, contacts and nameservers');
check(count($fixture->keys) === 5, 'Only four contact jobs and one registration job exist');
check($fixture->logins === 1, 'Bearer token reused across requests through encrypted store');
$fixture->failRead = true;
fails(fn () => service($fixture, $store)->register(), 'job read outage');
check(service($fixture, $store)->register() === [], 'Completed registration retry is harmless');
check(count(posts($fixture, 'domain.create')) === 2, 'Known job is fetched instead of a new registration POST');
$fixture->denyToken = true;
fails(fn () => service($fixture, $store)->info(), 'HTTP 401');
check(service($fixture, $store)->info()['name'] === 'example.com', 'Revoked token is replaced on next request');
check($fixture->logins === 2, 'Only one new login after token revocation');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->timeout = 'domain.renew';
fails(fn () => service($fixture, $store)->renew(), 'Simulated response timeout');
check(service($fixture, $store)->renew() === [], 'Renewal timeout recovers');
check(count($fixture->keys) === 1, 'Renewal retry creates only one paid job');
check(service($fixture, $store, ['expirydate' => '2028-12-31'])->renew() === [], 'Next expiry identifies next legitimate renewal');
check(count($fixture->keys) === 2, 'Next renewal is distinct');
fails(fn () => operation(new Fixture(), 'renew', ['expirydate' => '']), 'valid WHMCS expiry');

// Regression: a registry success can be synchronized into WHMCS before the timed-out action is retried.
$fixture = new Fixture(); $store = new MemoryStore(); $fixture->timeout = 'domain.renew';
fails(fn () => service($fixture, $store)->renew(), 'Simulated response timeout');
$fixture->info['exDate'] = '2028-12-31T00:00:00Z';
$synced = service($fixture, $store)->sync();
check($synced['expirydate'] === '2028-12-31', 'Domain sync advances the local expiry after registry renewal');
check(service($fixture, $store, ['expirydate' => $synced['expirydate'], 'regperiod' => 2])->renew() === [], 'Changed expiry and term resume outstanding renewal');
$renewals = posts($fixture, 'domain.renew');
check(count($renewals) === 2 && $renewals[0]['key'] === $renewals[1]['key'], 'Synced expiry retry retains original idempotency key');
check($renewals[0]['params'] === $renewals[1]['params'] && $renewals[1]['params']['regperiod'] === 1, 'Retry uses original term rather than changed input');
check(count($fixture->keys) === 1, 'Timeout then sync then retry creates only one paid renewal job');
check(service($fixture, $store, ['expirydate' => $synced['expirydate'], 'regperiod' => 2])->renew() === [], 'A later separate renewal is allowed after outstanding success is returned');
check(count($fixture->keys) === 2, 'Later confirmed purchase receives a distinct renewal job');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->timeout = 'domain.renew';
fails(fn () => service($fixture, $store)->renew(), 'Simulated');
$id = array_key_first($fixture->jobs);
$fixture->jobs[$id]['status'] = 'pending';
fails(fn () => service($fixture, $store, ['expirydate' => '2028-12-31', 'regperiod' => 2])->renew(), 'pending');
$fixture->failRead = true;
fails(fn () => service($fixture, $store, ['expirydate' => '2028-12-31', 'regperiod' => 3])->renew(), 'job read outage');
$fixture->jobs[$id]['status'] = 'unknown';
fails(fn () => service($fixture, $store, ['expirydate' => '2028-12-31', 'regperiod' => 3])->renew(), 'unknown');
check(count($fixture->keys) === 1, 'Pending, read failure and unknown states retain outstanding renewal across input changes');
$fixture->jobs[$id]['status'] = 'succeeded';
check(service($fixture, $store, ['expirydate' => '', 'regperiod' => 0])->renew() === [], 'Outstanding reconciliation precedes validation of new expiry and term');
check(count($fixture->keys) === 1, 'Reconciliation does not create a new purchase');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->timeout = 'domain.renew';
fails(fn () => service($fixture, $store)->renew(), 'Simulated');
$id = array_key_first($fixture->jobs); $fixture->jobs[$id]['status'] = 'unknown';
fails(fn () => service($fixture, $store)->renew(), 'unknown');
fails(fn () => service($fixture, $store)->renew(), 'unknown');
check(count(posts($fixture, 'domain.renew')) === 2, 'Unknown request is never enqueued again after job ID is saved');
$fixture->jobs[$id]['status'] = 'succeeded';
check(service($fixture, $store)->renew() === [], 'Reconciled original job can complete');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->timeout = 'domain.update_ns';
fails(fn () => service($fixture, $store)->saveNameservers(), 'Simulated');
fails(fn () => service($fixture, $store, ['ns1' => 'changed.example.net'])->saveNameservers(), 'different input');
check(service($fixture, $store)->saveNameservers() === [], 'Original nameserver action recovers');
check(service($fixture, $store, ['ns1' => 'changed.example.net'])->saveNameservers() === [], 'A subsequent nameserver change is permitted');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->timeout = 'domain.renew';
fails(fn () => service($fixture, $store)->renew(), 'Simulated');
$fixture->rejectSubmission = 403;
fails(fn () => service($fixture, $store)->renew(), 'HTTP 403');
check(service($fixture, $store)->renew() === [], 'Access restoration resumes ambiguous original request');
check(count($fixture->keys) === 1, 'A rejected retry after a timeout never clears original idempotency state');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->rejectSubmission = 422;
fails(fn () => service($fixture, $store)->saveNameservers(), 'HTTP 422');
check(service($fixture, $store, ['ns1' => 'corrected.example.net'])->saveNameservers() === [], 'Initial validation failure permits corrected input');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->reject = 'domain.renew';
fails(fn () => service($fixture, $store)->renew(), 'rejected');
$fixture->reject = null;
check(service($fixture, $store, ['expirydate' => '2028-12-31', 'regperiod' => 2])->renew() === [] && count($fixture->keys) === 2, 'Definitively rejected request permits a fresh retry');
check(posts($fixture, 'domain.renew')[1]['params']['regperiod'] === 2, 'Confirmed rejection clears the outstanding original term');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->rejectSubmission = 422;
fails(fn () => service($fixture, $store)->renew(), 'HTTP 422');
check(service($fixture, $store, ['regperiod' => 2])->renew() === [], 'Initial API validation rejection clears outstanding renewal reference');
check(count($fixture->keys) === 1 && posts($fixture, 'domain.renew')[0]['params']['regperiod'] === 2, 'Corrected renewal after initial rejection creates only the corrected job');

$fixture = new Fixture(); $store = new MemoryStore();
check(service($fixture, $store)->transfer() === [], 'Accepted pending transfer returns success');
check(service($fixture, $store)->transferSync() === [], 'Pending transfer is not completed');
$fixture->transferStatus = 'serverApproved';
check(service($fixture, $store)->transferSync() === ['completed' => true, 'expirydate' => '2027-12-31'], 'Approved transfer completion includes registry expiry');
$fixture->transferStatus = 'clientRejected';
check(service($fixture, $store)->transferSync()['failed'] === true, 'Registry rejection is reported');
check(count(array_filter(posts($fixture, 'domain.transfer'), fn ($row) => $row['params']['op'] === 'request')) === 1, 'TransferSync never submits another paid transfer');

$fixture = new Fixture(); $fixture->profile = 'VRSN';
check(operation($fixture, 'register') === [], 'Contactless VRSN registration works');
check(operation($fixture, 'register', ['premiumCost' => '0.00']) === [], 'Zero premium cost does not reject a standard registration');
check(posts($fixture, 'contact.create') === [], 'VRSN does not create unsupported contact objects');
fails(fn () => operation($fixture, 'getContacts'), 'no EPP contact');
$fixture = new Fixture(); $fixture->profile = 'UA';
check(operation($fixture, 'register') === [], 'UA native parameters validate');
$fixture = new Fixture(); $fixture->profile = 'EE';
fails(fn () => operation($fixture, 'register'), 'profile adapter');
check($fixture->posts === [], 'Unsupported profile fails before writes');

$fixture = new Fixture();
check(operation($fixture, 'availability') === true, 'Availability uses exact response domain');
check(Registrar::nameserverResult($fixture->info) === ['ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net', 'ns3' => '', 'ns4' => '', 'ns5' => ''], 'WHMCS nameservers use ns1..ns5 keys');
check(operation($fixture, 'sync') === ['expirydate' => '2027-12-31', 'active' => true], 'Sync maps expiry');
$fixture->info['status'] = ['pendingDelete'];
check(operation($fixture, 'sync')['active'] === false, 'Pending registry state is not active');
check(operation($fixture, 'getContacts')['Registrant']['Country'] === 'BG', 'Contact info maps to WHMCS labels');
check(operation($fixture, 'saveContacts', ['contactdetails' => ['Registrant' => ['First Name' => 'Updated', 'Country' => 'BG']]]) === [], 'Contact update uses native API parameters');
check(service($fixture, new MemoryStore(), ['nameserver' => 'ns1.example.com', 'ipaddress' => '192.0.2.1'])->host('create') === [], 'Host registration validates native parameters');
check(service($fixture, new MemoryStore(), ['nameserver' => 'ns1.example.com', 'currentipaddress' => '192.0.2.1', 'newipaddress' => '2001:db8::1'])->host('update') === [], 'Host update supports IPv6');
check(service($fixture, new MemoryStore(), ['nameserver' => 'ns1.example.com'])->host('delete') === [], 'Host deletion validates native parameters');
check(operation($fixture, 'saveLock', ['lockenabled' => 'locked']) === [], 'Lock uses native update parameters');
$fixture->info['status'] = ['clientTransferProhibited'];
$before = count(posts($fixture, 'domain.update_status'));
check(operation($fixture, 'saveLock', ['lockenabled' => 'locked']) === [], 'Already locked action succeeds');
check(count(posts($fixture, 'domain.update_status')) === $before, 'Already locked skips redundant EPP update');
check(operation($fixture, 'delete') === [], 'Domain delete validates native parameters');
fails(fn () => operation(new Fixture(), 'register', ['idprotection' => true]), 'not supported');
fails(fn () => operation(new Fixture(), 'register', ['premiumEnabled' => true, 'premiumCost' => '120.00']), 'not supported');
fails(fn () => operation(new Fixture(), 'renew', ['isInRedemptionGracePeriod' => true]), 'restoration');
fails(fn () => ApiClient::result(['status' => 'pending', 'job_uuid' => 'pending-job']), 'pending');
fails(fn () => Registrar::expiry(['exDate' => '2027-02-31']), 'valid expiry');

// Exercise the production PDO store against a disposable database when explicitly configured.
if (getenv('WHMCS_TEST_MYSQL_DSN')) {
    function encrypt(string $plain): string { return 'test-fixture:' . base64_encode($plain); }
    function decrypt(string $cipher): string { return base64_decode(substr($cipher, 13), true); }
    $pdo = new PDO(getenv('WHMCS_TEST_MYSQL_DSN'), getenv('WHMCS_TEST_MYSQL_USER') ?: 'root', getenv('WHMCS_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('DROP TABLE IF EXISTS mod_namingo_requests');
    try {
        $store = new Store($pdo); $key = hash('sha256', 'database-test');
        $store->lock($key);
        $store->put($key, ['secret' => 'not plaintext']);
        check($store->get($key) === ['secret' => 'not plaintext'], 'PDO store round trips through WHMCS encryption interface');
        check(!str_contains($pdo->query('SELECT payload FROM mod_namingo_requests')->fetchColumn(), 'not plaintext'), 'PDO store does not store raw payload');
        $store->put($key, ['job' => 'updated']);
        check($store->get($key) === ['job' => 'updated'], 'PDO store upsert works');
        $store->remove($key); check($store->get($key) === null, 'PDO store delete works');
        $store->unlock($key);
    } finally { $pdo->exec('DROP TABLE mod_namingo_requests'); }
    echo "PDO store checks passed (test encryption double).\n";
}
echo "WHMCS module: {$assertions} assertions passed.\n";
