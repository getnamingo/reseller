#!/usr/bin/env php
<?php

declare(strict_types=1);

use Namingo\ResellerApi\ApiException;
use Namingo\ResellerApi\Audit;
use Namingo\ResellerApi\Auth;
use Namingo\ResellerApi\CommandCatalog;
use Namingo\ResellerApi\Config;
use Namingo\ResellerApi\Crypto;
use Namingo\ResellerApi\Db;
use Namingo\ResellerApi\Domains;
use Namingo\ResellerApi\EppWorker;
use Namingo\ResellerApi\Ledger;
use Namingo\ResellerApi\Lifecycle;
use Namingo\ResellerApi\Queue;
use Namingo\ResellerApi\SelfService;
use Namingo\ResellerApi\Util;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Table;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Config::load($root);

if (PHP_SAPI !== 'cli' || !extension_loaded('swoole')) {
    fwrite(STDERR, "This server must run from PHP CLI with ext-swoole loaded.\n");
    exit(1);
}

$envFile = $root . '/.env';
if (Config::production() && is_file($envFile) && ((fileperms($envFile) & 0027) !== 0)) {
    fwrite(STDERR, ".env may be group-readable but must not be group-writable or accessible to others (use chmod 0640 or stricter).\n");
    exit(1);
}

Crypto::key();
Db::pdo()->query('SELECT 1')->fetchColumn();
Db::reset();

$host = Config::get('API_HOST', '127.0.0.1') ?? '127.0.0.1';
$port = Config::int('API_PORT', 9501);
$tlsCert = Config::get('API_TLS_CERT');
$tlsKey = Config::get('API_TLS_KEY');
if (($tlsCert === null) !== ($tlsKey === null)) {
    throw new RuntimeException('API_TLS_CERT and API_TLS_KEY must be configured together.');
}
$tls = $tlsCert !== null;
if ($tls && (!is_readable($tlsCert) || !is_readable($tlsKey))) {
    throw new RuntimeException('The API TLS certificate or key is not readable.');
}
if ($tls && Config::production() && ((fileperms($tlsKey) & 0027) !== 0)) {
    throw new RuntimeException('The API TLS private key may be group-readable but must not be group-writable or accessible to others.');
}
$loopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
$trustedProxies = Config::get('API_TRUSTED_PROXIES', '') ?? '';
Util::clientIp('127.0.0.1', null, $trustedProxies); // Reject invalid trust configuration at startup.
if (Config::production() && !$tls && !$loopback && !Config::bool('API_ALLOW_INSECURE_HTTP', false)) {
    throw new RuntimeException('Production plaintext HTTP may bind only to loopback. Configure API TLS or a loopback reverse proxy.');
}

$socketType = SWOOLE_SOCK_TCP | ($tls ? SWOOLE_SSL : 0);
$server = new Server($host, $port, SWOOLE_PROCESS, $socketType);
$settings = [
    'worker_num' => max(1, Config::int('API_WORKERS', 4)),
    'task_worker_num' => max(1, Config::int('API_TASK_WORKERS', 4)),
    'max_request' => 10000,
    'task_max_request' => 1000,
    'package_max_length' => max(1024, Config::int('API_MAX_BODY_BYTES', 262144)),
    'http_compression' => true,
    'http_compression_level' => 4,
    'log_file' => '/run/namingo-epp-api/swoole.log',
    'daemonize' => false,
];
if ($tls) {
    $settings['ssl_cert_file'] = $tlsCert;
    $settings['ssl_key_file'] = $tlsKey;
    $settings['ssl_protocols'] = SWOOLE_SSL_TLSv1_2 | (defined('SWOOLE_SSL_TLSv1_3') ? SWOOLE_SSL_TLSv1_3 : 0);
}
$server->set($settings);

$rateTable = new Table(16384);
$rateTable->column('window', Table::TYPE_INT, 8);
$rateTable->column('count', Table::TYPE_INT, 4);
$rateTable->create();

$allowRate = static function (string $key, int $limit) use ($rateTable): bool {
    $key = substr(hash('sha256', $key), 0, 48);
    $window = intdiv(time(), 60);
    $row = $rateTable->get($key);
    if ($row === false || (int) $row['window'] !== $window) {
        return $rateTable->set($key, ['window' => $window, 'count' => 1]);
    }
    $count = $rateTable->incr($key, 'count', 1);
    return $count !== false && $count <= $limit;
};

$respond = static function (Response $response, int $status, array $payload, string $requestId) use ($tls): void {
    $response->status($status);
    $response->header('Content-Type', 'application/json; charset=utf-8');
    $response->header('Cache-Control', 'no-store');
    $response->header('Pragma', 'no-cache');
    $response->header('X-Content-Type-Options', 'nosniff');
    $response->header('X-Frame-Options', 'DENY');
    $response->header('Referrer-Policy', 'no-referrer');
    $response->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    $response->header('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
    $response->header('X-Request-ID', $requestId);
    if ($tls) {
        $response->header('Strict-Transport-Security', 'max-age=31536000');
    }
    $payload['request_id'] = $requestId;
    try {
        $response->end(Util::json($payload));
    } catch (Throwable) {
        $response->status(500);
        $response->end('{"error":{"code":"encoding_failed","message":"Response encoding failed."}}');
    }
};

$readBody = static function (Request $request): array {
    $length = (int) ($request->header['content-length'] ?? 0);
    if ($length > Config::int('API_MAX_BODY_BYTES', 262144)) {
        throw new ApiException(413, 'body_too_large', 'Request body is too large.');
    }
    $contentType = strtolower(trim(explode(';', $request->header['content-type'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        throw new ApiException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
    }
    $raw = $request->rawContent();
    if ($raw === false || $raw === '') {
        throw new ApiException(400, 'empty_body', 'A JSON request body is required.');
    }
    if (strlen($raw) > Config::int('API_MAX_BODY_BYTES', 262144)) {
        throw new ApiException(413, 'body_too_large', 'Request body is too large.');
    }
    return Util::decodeObject($raw);
};

$server->on('WorkerStart', static function (Server $server, int $workerId) use ($rateTable): void {
    Db::reset();
    if ($workerId === 0) {
        \Swoole\Timer::tick(max(250, Config::int('API_QUEUE_TICK_MS', 1000)), static function () use ($server): void {
            try { Queue::tick(Db::pdo(), static fn (array $task) => $server->task($task)); }
            catch (Throwable $e) { Db::reset(); error_log(Util::json(['event' => 'queue_dispatch_failed', 'type' => get_class($e)])); }
        });
        \Swoole\Timer::tick(5000, static function (): void {
            try { Domains::tick(Db::pdo()); }
            catch (Throwable $e) { Db::reset(); error_log(Util::json(['event' => 'panel_domain_progress_failed', 'type' => get_class($e)])); }
        });
        \Swoole\Timer::tick(120_000, static function () use ($rateTable): void {
            $expiredBefore = intdiv(time(), 60) - 2;
            foreach ($rateTable as $key => $row) {
                if ((int) $row['window'] < $expiredBefore) {
                    $rateTable->del((string) $key);
                }
            }
        });
    }
});

$server->on('Task', static function (Server $server, int $taskId, int $sourceWorkerId, mixed $data): array {
    Db::reset();
    if (!is_array($data)) { return EppWorker::process((string) $data); }
    return match ($data['kind'] ?? '') {
        'job' => EppWorker::process($data['id']),
        'reconcile' => EppWorker::reconcile($data['id']),
        'deleted' => EppWorker::checkDeleted($data['registry_id'], $data['object_name'], $data['reseller_id']),
        default => ['ok' => false, 'error' => 'invalid_task'],
    };
});

$server->on('Finish', static function (): void {
});

$server->on('Start', static function (Server $server) use ($host, $port, $tls): void {
    error_log(Util::json([
        'level' => 'info',
        'event' => 'server_started',
        'pid' => $server->master_pid,
        'listen' => ($tls ? 'https://' : 'http://') . $host . ':' . $port,
    ]));
});

$server->on('Request', static function (Request $request, Response $response) use (
    $server,
    $allowRate,
    $respond,
    $readBody,
    $trustedProxies,
): void {
    $incomingRequestId = (string) ($request->header['x-request-id'] ?? '');
    $requestId = preg_match('/^[A-Za-z0-9._:-]{8,64}$/D', $incomingRequestId) === 1 ? $incomingRequestId : Util::uuid();
    $method = strtoupper((string) ($request->server['request_method'] ?? 'GET'));
    $uri = (string) ($request->server['request_uri'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $path = $path !== '/' ? rtrim($path, '/') : $path;
    $ip = Util::clientIp((string) ($request->server['remote_addr'] ?? 'unknown'),
        $request->header['x-forwarded-for'] ?? null, $trustedProxies);

    try {
        if ($method === 'GET' && $path === '/healthz') {
            $respond($response, 200, ['data' => ['status' => 'ok']], $requestId);
            return;
        }
        if ($method === 'GET' && $path === '/readyz') {
            Db::pdo()->query('SELECT 1')->fetchColumn();
            $respond($response, 200, ['data' => ['status' => 'ready']], $requestId);
            return;
        }
        if ($path === '/v1/login') {
            if ($method !== 'POST') {
                $response->header('Allow', 'POST');
                throw new ApiException(405, 'method_not_allowed', 'Use POST for this endpoint.');
            }
            if (!$allowRate('login:' . $ip, Config::int('API_LOGIN_LIMIT_PER_MINUTE', 10))) {
                throw new ApiException(429, 'rate_limited', 'Too many login attempts.');
            }
            $body = $readBody($request);
            if (!is_string($body['username'] ?? null) || !is_string($body['password'] ?? null)) {
                throw new ApiException(422, 'invalid_credentials', 'username and password are required.');
            }
            $token = Auth::login(Db::pdo(), $body['username'], $body['password'], $ip);
            $respond($response, 200, ['data' => $token], $requestId);
            return;
        }

        if (!str_starts_with($path, '/v1/')) {
            throw new ApiException(404, 'not_found', 'Endpoint not found.');
        }
        $pdo = Db::pdo();
        $user = Auth::authenticate($pdo, $request->header['authorization'] ?? null);
        if (!$allowRate('reseller:' . $user['id'], Config::int('API_RATE_LIMIT_PER_MINUTE', 120))) {
            throw new ApiException(429, 'rate_limited', 'API request limit exceeded.');
        }

        if ($path === '/v1/logout') {
            if ($method !== 'POST') {
                $response->header('Allow', 'POST');
                throw new ApiException(405, 'method_not_allowed', 'Use POST for this endpoint.');
            }
            Auth::revoke($pdo, $user['token_id']);
            Audit::record($pdo, $user['id'], 'auth.logout', 'token', $user['token_id'], $ip);
            $respond($response, 200, ['data' => ['revoked' => true]], $requestId);
            return;
        }

        if ($method === 'GET' && $path === '/v1/account') {
            $profile = $pdo->prepare('SELECT display_name, company, email, phone, address, country_code FROM resellers WHERE id = ?');
            $profile->execute([$user['id']]);
            $wallet = $pdo->prepare('SELECT available_balance, held_balance, currency FROM wallets WHERE reseller_id = ?');
            $wallet->execute([$user['id']]);
            $respond($response, 200, ['data' => [
                'reseller_uuid' => $user['id'],
                'username' => $user['username'],
                'profile' => $profile->fetch(),
                'wallet' => $wallet->fetch(),
            ]], $requestId);
            return;
        }

        if ($method === 'GET' && $path === '/v1/registries') {
            $stmt = $pdo->prepare(
                'SELECT r.id AS registry_uuid, r.name, r.profile FROM registries r
                 JOIN reseller_registries rr ON rr.registry_id = r.id
                 WHERE rr.reseller_id = ? AND r.enabled = 1 AND rr.enabled = 1 ORDER BY r.name'
            );
            $stmt->execute([$user['id']]);
            $respond($response, 200, ['data' => $stmt->fetchAll()], $requestId);
            return;
        }

        if (in_array($path, ['/v1/jobs', '/v1/prices', '/v1/domains'], true)) {
            if ($method !== 'GET') {
                throw new ApiException(405, 'method_not_allowed', 'Use GET for self-service listings.');
            }
            $respond($response, 200, SelfService::listing($pdo, $user['id'], substr($path, 4), $request->get ?? []), $requestId);
            return;
        }

        if ($method === 'GET' && $path === '/v1/commands') {
            $respond($response, 200, ['data' => [
                'commands' => CommandCatalog::all(),
                'profiles' => CommandCatalog::profiles(),
                'parameter_contract' => 'params keys are passed unchanged to the selected pinga/tembo profile method',
                'profile_parameter_source' => 'https://github.com/getnamingo/epp-client/tree/2a7610d286f75b74462b51dc2a6cf1a470ef863e/src/Registries',
            ]], $requestId);
            return;
        }

        if ($method === 'GET' && $path === '/v1/ledger') {
            $limit = min(100, max(1, (int) ($request->get['limit'] ?? 50)));
            $stmt = $pdo->prepare(
                'SELECT id AS entry_uuid, job_id AS job_uuid, deposit_id AS deposit_uuid, kind,
                        available_delta, held_delta, available_after, held_after, currency, description, created_at
                 FROM ledger_entries WHERE reseller_id = ? ORDER BY created_at DESC, id DESC LIMIT ?'
            );
            $stmt->bindValue(1, $user['id']);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $respond($response, 200, ['data' => $stmt->fetchAll()], $requestId);
            return;
        }

        if ($path === '/v1/deposits' && $method === 'POST') {
            $body = $readBody($request);
            $amount = Util::money($body['amount'] ?? null);
            $currency = strtoupper((string) ($body['currency'] ?? $user['currency']));
            if ($currency !== $user['currency']) {
                throw new ApiException(409, 'currency_mismatch', 'Deposit currency must match the wallet.');
            }
            $reference = (string) ($body['reference'] ?? $request->header['idempotency-key'] ?? Util::uuid());
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $reference) !== 1) {
                throw new ApiException(422, 'invalid_reference', 'Deposit reference must contain 8-128 safe ASCII characters.');
            }
            $depositId = Util::uuid();
            $depositStatus = 'pending';
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO deposit_requests (id, reseller_id, client_reference, amount, currency) VALUES (?, ?, ?, ?, ?)'
                );
                $insert->execute([$depositId, $user['id'], $reference, $amount, $currency]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $existing = $pdo->prepare(
                    'SELECT id, amount, currency, status FROM deposit_requests WHERE reseller_id = ? AND client_reference = ? LIMIT 1'
                );
                $existing->execute([$user['id'], $reference]);
                $row = $existing->fetch();
                if ($row === false || $row['amount'] !== $amount || $row['currency'] !== $currency) {
                    throw new ApiException(409, 'idempotency_conflict', 'Deposit reference was used with different details.');
                }
                $depositId = $row['id'];
                $depositStatus = $row['status'];
            }
            Audit::record($pdo, $user['id'], 'deposit.requested', 'deposit', $depositId, $ip, ['amount' => $amount, 'currency' => $currency]);
            $respond($response, $depositStatus === 'pending' ? 202 : 200, ['data' => [
                'deposit_uuid' => $depositId,
                'status' => $depositStatus,
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $reference,
            ]], $requestId);
            return;
        }

        if ($path === '/v1/deposits' && $method === 'GET') {
            $limit = min(100, max(1, (int) ($request->get['limit'] ?? 50)));
            $stmt = $pdo->prepare(
                'SELECT id AS deposit_uuid, client_reference AS reference, amount, currency, status, created_at, decided_at
                 FROM deposit_requests WHERE reseller_id = ? ORDER BY created_at DESC LIMIT ?'
            );
            $stmt->bindValue(1, $user['id']);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $respond($response, 200, ['data' => $stmt->fetchAll()], $requestId);
            return;
        }

        if ($method === 'POST' && preg_match('#^/v1/jobs/([0-9a-f-]{36})/reconcile$#iD', $path, $matches) === 1) {
            $respond($response, 202, ['data' => Lifecycle::schedule($pdo, strtolower($matches[1]), $user['id'])], $requestId);
            return;
        }

        if ($method === 'GET' && preg_match('#^/v1/jobs/([0-9a-f-]{36})$#iD', $path, $matches) === 1) {
            if (!Util::isUuid($matches[1])) {
                throw new ApiException(404, 'job_not_found', 'Job was not found.');
            }
            $respond($response, 200, ['data' => Ledger::fetchJob($pdo, $matches[1], $user['id'])], $requestId);
            return;
        }

        if (preg_match('#^/v1/epp/([a-z0-9._-]+)$#D', $path, $matches) === 1) {
            if ($method !== 'POST') {
                $response->header('Allow', 'POST');
                throw new ApiException(405, 'method_not_allowed', 'Use POST for EPP commands.');
            }
            $command = $matches[1];
            CommandCatalog::method($command);
            $body = $readBody($request);
            $registryId = (string) ($body['registry_uuid'] ?? '');
            if (!Util::isUuid($registryId)) {
                throw new ApiException(422, 'invalid_registry_uuid', 'registry_uuid must be a UUID.');
            }
            $params = $body['params'] ?? [];
            if (!is_array($params)) {
                throw new ApiException(422, 'invalid_params', 'params must be a JSON object.');
            }
            CommandCatalog::validateParams($command, $params);
            $idempotencyKey = (string) ($request->header['idempotency-key'] ?? '');
            if ($idempotencyKey === '' && CommandCatalog::isMutating($command, $params)) {
                throw new ApiException(428, 'idempotency_required', 'Mutating EPP commands require an Idempotency-Key header.');
            }
            if ($idempotencyKey === '') {
                $idempotencyKey = 'read-' . Util::uuid();
            }

            $created = Ledger::createJob($pdo, $user['id'], $registryId, $command, $idempotencyKey, $params);
            if (!$created['existing']) {
                Audit::record($pdo, $user['id'], 'epp.job_queued', 'job', $created['job_id'], $ip, [
                    'command' => $command,
                    'registry_uuid' => $registryId,
                ]);
            }

            $async = $command === 'domain.check' && ($request->header['prefer'] ?? '') === 'respond-async';
            if ($async) { $response->header('Preference-Applied', 'respond-async'); }
            if (!$async && (!$created['existing'] || $created['status'] === 'queued')) {
                $server->taskwait($created['job_id'], Config::float('API_SYNC_WAIT_SECONDS', 25.0));
            }
            $job = Ledger::fetchJob($pdo, $created['job_id'], $user['id']);
            $httpStatus = match ($job['status']) {
                'succeeded', 'rejected', 'unknown' => 200,
                default => 202,
            };
            $respond($response, $httpStatus, ['data' => $job], $requestId);
            return;
        }

        throw new ApiException(404, 'not_found', 'Endpoint not found.');
    } catch (ApiException $e) {
        $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        if ($e->details !== []) {
            $error['details'] = $e->details;
        }
        $respond($response, $e->httpStatus, ['error' => $error], $requestId);
    } catch (JsonException) {
        $respond($response, 400, ['error' => ['code' => 'invalid_json', 'message' => 'Malformed JSON request body.']], $requestId);
    } catch (PDOException $e) {
        error_log(Util::json(['level' => 'error', 'event' => 'database_error', 'request_id' => $requestId, 'sqlstate' => $e->getCode()]));
        $respond($response, 503, ['error' => ['code' => 'service_unavailable', 'message' => 'Database service is unavailable.']], $requestId);
    } catch (Throwable $e) {
        error_log(Util::json(['level' => 'error', 'event' => 'request_failed', 'request_id' => $requestId, 'message' => $e->getMessage()]));
        $respond($response, 500, ['error' => ['code' => 'internal_error', 'message' => 'The request could not be completed.']], $requestId);
    }
});

$server->start();

