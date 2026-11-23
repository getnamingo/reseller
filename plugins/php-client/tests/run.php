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

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 3) . '/src/Core.php';
require dirname(__DIR__, 3) . '/src/ProfileParams.php';

use Namingo\ResellerClient\ApiException;
use Namingo\ResellerClient\Client;
use Namingo\ResellerClient\HttpResponse;
use Namingo\ResellerClient\JobException;
use Namingo\ResellerClient\ProtocolException;
use Namingo\ResellerClient\TransportException;
use Namingo\ResellerClient\TransportInterface;
use Namingo\ResellerApi\CommandCatalog;

const REGISTRY = '11111111-1111-4111-8111-111111111111';
const JOB = '22222222-2222-4222-8222-222222222222';
$assertions = 0;
function check(bool $ok, string $message): void
{
    global $assertions; ++$assertions;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
}
function fails(callable $call, string $class): Throwable
{
    try { $call(); } catch (Throwable $error) { check($error instanceof $class, 'Expected ' . $class . ', got ' . get_class($error) . ': ' . $error->getMessage()); return $error; }
    throw new RuntimeException('Expected exception: ' . $class);
}

final class Fixture implements TransportInterface
{
    public array $calls = [];
    public array $writes = [];
    public string $status = 'succeeded';
    public string $profile = 'generic';
    public array $nextStates = [];
    public ?HttpResponse $next = null;
    public bool $loseWriteResponse = false;
    public bool $failRead = false;
    public int $logins = 0;

    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): HttpResponse
    {
        $path = parse_url($url, PHP_URL_PATH); parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $json = $body === null ? [] : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $this->calls[] = compact('method', 'path', 'headers', 'json', 'query', 'timeoutSeconds', 'body');
        if ($path === '/v1/login') { ++$this->logins; return $this->reply(['access_token' => 'test-token', 'expires_in' => 3600]); }
        check(($headers['Authorization'] ?? '') === 'Bearer test-token', 'Bearer authentication attached');
        if ($this->next !== null) { $next = $this->next; $this->next = null; return $next; }
        if (str_starts_with($path, '/v1/epp/')) {
            $command = basename($path);
            CommandCatalog::validateParams($command, $json['params'], $this->profile);
            if (CommandCatalog::isMutating($command, $json['params'])) {
                $key = $headers['Idempotency-Key'] ?? '';
                check($key !== '', 'Write carries an explicit idempotency key');
                if (isset($this->writes[$key])) { check($this->writes[$key] === $json, 'Retry carries identical payload'); }
                $this->writes[$key] = $json;
                if ($this->loseWriteResponse) { $this->loseWriteResponse = false; throw new TransportException('Simulated timeout after API commit'); }
            }
            return $this->reply($this->job(), $this->status === 'succeeded' ? 200 : 202);
        }
        if (str_ends_with($path, '/reconcile')) { return $this->reply(['job_uuid' => JOB, 'scheduled' => true], 202); }
        if ($path === '/v1/jobs/' . JOB) {
            if ($this->failRead) { $this->failRead = false; throw new TransportException('Temporary job read outage'); }
            if ($this->nextStates !== []) { $this->status = array_shift($this->nextStates); }
            return $this->reply($this->job());
        }
        if ($path === '/v1/logout') { return $this->reply(['revoked' => true]); }
        if (in_array($path, ['/v1/prices', '/v1/domains', '/v1/jobs'], true)) {
            $offset = (int) ($query['offset'] ?? 0);
            return new HttpResponse(200, [], json_encode(['data' => [['item' => $offset]], 'pagination' => [
                'limit' => 1, 'offset' => $offset, 'has_more' => $offset < 1, 'next_offset' => $offset < 1 ? 1 : null], 'request_id' => 'fixture-request']));
        }
        return $this->reply(['ok' => true]);
    }
    public function job(): array { return ['job_uuid' => JOB, 'status' => $this->status, 'epp_code' => 1000, 'result' => ['code' => 1000, 'authInfo' => 'sensitive-result']]; }
    public function reply(array $data, int $status = 200): HttpResponse { return new HttpResponse($status, [], json_encode(['data' => $data, 'request_id' => 'fixture-request'], JSON_THROW_ON_ERROR)); }
    public function count(string $path): int { return count(array_filter($this->calls, fn ($row) => $row['path'] === $path)); }
}
function client(Fixture $fixture): Client { return new Client('https://api.example.net', 'test-user', 'test-password', $fixture); }

fails(fn () => new Client('http://example.net'), InvalidArgumentException::class);
fails(fn () => new Client('https://user:pass@example.net'), InvalidArgumentException::class);
fails(fn () => new Client('https://example.net?token=secret'), InvalidArgumentException::class);
fails(fn () => new Client('https://example.net', 'username'), InvalidArgumentException::class);
$fixture = new Fixture(); $client = client($fixture);
check($client->account() === ['ok' => true], 'Account data unwrapped');
$client->registries(); $client->commands();
check($fixture->logins === 1, 'Lazy login reused across calls');
check(!str_contains(print_r($client, true), 'test-password') && !str_contains(print_r($client, true), 'test-token'), 'Client debug view redacts credentials');
$session = $client->session();
check($session['access_token'] === 'test-token' && $session['expires_at'] > time(), 'Session exports absolute expiry for encrypted caching');
$tokenClient = new Client('https://api.example.net', transport: $fixture);
$tokenClient->setAccessToken($session['access_token'], $session['expires_at']);
$tokenClient->account(); check($fixture->logins === 1, 'Cached-token-only authentication needs no extra login');
fails(fn () => $tokenClient->setAccessToken("token\r\nInjected: header"), InvalidArgumentException::class);
$tokenClient->setAccessToken('test-token', time() - 1);
fails(fn () => $tokenClient->account(), LogicException::class);
$client->setAccessToken('test-token', time() - 1); $client->account();
check($fixture->logins === 2, 'Expired credential-managed session logs in');
$fixture->next = new HttpResponse(401, [], '{"error":{"code":"invalid_token","message":"Expired"}}');
$before = count($fixture->calls);
fails(fn () => $client->account(), ApiException::class);
check(count($fixture->calls) === $before + 1 && $client->session() === null, '401 clears token without hidden retries');
$client->account(); check($fixture->logins === 3, 'Next explicit call authenticates again');

$page = $client->prices(['tld' => 'co.uk', 'offset' => 0]);
check($page->httpStatus === 200 && $page->requestId() === 'fixture-request', 'HTTP and request metadata preserved');
check($page->pagination()['has_more'] === true, 'Pagination retained');
check(iterator_to_array($client->iterate('domains', ['registry_uuid' => REGISTRY], 1), false) === [['item' => 0], ['item' => 1]], 'Lazy paginator follows next_offset');
fails(fn () => iterator_to_array($client->iterate('ledger')), InvalidArgumentException::class);
$client->ledger(10); $client->deposits(20);
check(end($fixture->calls)['query']['limit'] === '20', 'Non-paginated list limit passed');
$client->requestDeposit('12.3456', 'eur', 'deposit-123456');
$call = end($fixture->calls);
check($call['json']['amount'] === '12.3456' && $call['json']['currency'] === 'EUR' && $call['headers']['Idempotency-Key'] === 'deposit-123456', 'Deposit preserves exact decimal and reference');
fails(fn () => $client->requestDeposit('10.00', 'EUR', 'bad'), InvalidArgumentException::class);

$key = Client::newIdempotencyKey();
$params = ['domainname' => 'example.com', 'regperiod' => 1];
fails(fn () => $client->epp(REGISTRY, 'domain.renew', $params), InvalidArgumentException::class);
fails(fn () => $client->epp(REGISTRY, 'domain.transfer', ['domainname' => 'example.com', 'op' => 'cancel']), InvalidArgumentException::class);
fails(fn () => $client->epp(REGISTRY, 'domain.renew', $params, 'bad'), InvalidArgumentException::class);
fails(fn () => $client->epp(REGISTRY, 'domain.renew', $params, $key, true), InvalidArgumentException::class);
$fixture->loseWriteResponse = true;
fails(fn () => $client->epp(REGISTRY, 'domain.renew', $params, $key), TransportException::class);
check($fixture->count('/v1/epp/domain.renew') === 1, 'Timed-out write is never automatically replayed');
$job = $client->epp(REGISTRY, 'domain.renew', $params, $key);
check(count($fixture->writes) === 1, 'App-controlled same-key retry resolves one paid operation');
check(Client::assertSucceeded($job)['code'] === 1000, 'Explicit succeeded assertion returns native result');
$job = $client->epp(REGISTRY, 'domain.transfer', ['domainname' => 'example.com', 'op' => 'query']);
check($job['status'] === 'succeeded', 'Transfer query is read-only');
$job = $client->epp(REGISTRY, 'hello', []);
check(str_contains(end($fixture->calls)['body'], '"params":{}'), 'Empty native parameter object serialized as object');
$client->epp(REGISTRY, 'domain.check', ['domains' => ['example.com']], respondAsync: true);
check(end($fixture->calls)['headers']['Prefer'] === 'respond-async', 'Async check preference exact');
$fixture->profile = 'EU';
$euContact = ['id' => 'APP123', 'euType' => 'natural', 'firstname' => 'Test', 'lastname' => 'User', 'companyname' => '',
    'address1' => 'Street 1', 'address2' => '', 'city' => 'Sofia', 'state' => '', 'postcode' => '1000',
    'country' => 'BG', 'fullphonenumber' => '+359.888123456', 'email' => 'user@example.net', 'authInfoPw' => 'Secret!123'];
$client->epp(REGISTRY, 'contact.create', $euContact, 'eu-contact-123456');
check(end($fixture->calls)['json']['params'] === $euContact, 'Registry-specific legal/profile parameters are passed unchanged');
$fixture->profile = 'generic';

$fixture->status = 'queued'; $fixture->nextStates = ['processing', 'succeeded'];
$queued = $fixture->job();
check($client->waitForJob($queued, 1, .001)['status'] === 'succeeded', 'Polls same job through processing to success');
$fixture->status = 'pending'; $pending = $fixture->job(); $before = $fixture->count('/v1/jobs/' . JOB);
check($client->waitForJob($pending)['status'] === 'pending', 'Pending stops by default');
check($fixture->count('/v1/jobs/' . JOB) === $before, 'Pending default does not busy-poll');
$fixture->nextStates = ['succeeded'];
check($client->waitForJob($pending, 1, .001, true)['status'] === 'succeeded', 'Optional pending lifecycle polling');
$fixture->status = 'queued';
check($client->waitForJob($fixture->job(), .002, .1)['status'] === 'queued', 'Polling deadline returns latest state without inventing failure');
$fixture->failRead = true;
fails(fn () => $client->waitForJob($fixture->job(), 1, .001), TransportException::class);
foreach (['pending', 'queued', 'processing', 'rejected', 'unknown'] as $status) {
    $fixture->status = $status;
    $error = fails(fn () => Client::assertSucceeded($fixture->job()), JobException::class);
    check($error->status === $status && !str_contains($error->getMessage(), 'sensitive-result'), 'Unfinished/failed job status is explicit without leaking result');
}
$client->reconcile(JOB); check(end($fixture->calls)['path'] === '/v1/jobs/' . JOB . '/reconcile', 'Reconciliation routes to existing job');
fails(fn () => $client->job('invalid/../../path'), InvalidArgumentException::class);
fails(fn () => $client->request('GET', 'https://other.example/v1/account'), InvalidArgumentException::class);
fails(fn () => $client->request('GET', '/v1/../../account'), InvalidArgumentException::class);
fails(fn () => $client->request('GET', '/v1/account', ['body' => 'not allowed']), InvalidArgumentException::class);

$fixture->next = new HttpResponse(402, ['retry-after' => '5'], '{"error":{"code":"insufficient_funds","message":"Insufficient funds","details":{"required":"10.0000"}},"request_id":"error-id"}');
$error = fails(fn () => $client->account(), ApiException::class);
check($error->httpStatus === 402 && $error->apiCode === 'insufficient_funds' && $error->details['required'] === '10.0000' && $error->requestId === 'error-id' && $error->headers['retry-after'] === '5', 'Structured HTTP error metadata retained');
foreach (['<html>not json</html>', '{"data":null}', '{"data":{},"error":{"code":"unexpected"}}'] as $body) {
    $fixture->next = new HttpResponse(200, [], $body);
    fails(fn () => $client->account(), ProtocolException::class);
}
$fixture->next = new HttpResponse(503, ['x-request-id' => 'gateway-id'], '<html>gateway error</html>');
$error = fails(fn () => $client->account(), ApiException::class);
check($error->httpStatus === 503 && $error->requestId === 'gateway-id', 'Non-JSON gateway error retains HTTP status and request ID');
$fixture->next = new HttpResponse(500, [], '123');
fails(fn () => $client->account(), ApiException::class);
$fixture->next = new HttpResponse(200, [], '{"data":{"job_uuid":"22222222-2222-4222-8222-222222222222","status":"unrecognized"}}');
fails(fn () => $client->job(JOB), ProtocolException::class);
$fixture->next = new HttpResponse(200, [], '{"data":{"job_uuid":"22222222-2222-4222-8222-222222222222","status":"succeeded"}}');
fails(fn () => Client::assertSucceeded($client->job(JOB)), ProtocolException::class);
$fixture->next = new HttpResponse(200, [], '{"data":[{"id":1}],"pagination":{"has_more":true,"next_offset":0}}');
fails(fn () => iterator_to_array($client->iterate('jobs')), ProtocolException::class);
$client->logout(); check($client->session() === null, 'Logout clears local session');
echo "Standalone client: {$assertions} assertions passed.\n";
