<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Namingo\ResellerApi\Access;
use Namingo\ResellerApi\Admin;
use Namingo\ResellerApi\ApiException;
use Namingo\ResellerApi\Auth;
use Namingo\ResellerApi\CommandCatalog;
use Namingo\ResellerApi\Crypto;
use Namingo\ResellerApi\Db;
use Namingo\ResellerApi\EppWorker;
use Namingo\ResellerApi\Ledger;
use Namingo\ResellerApi\PanelApi;
use Namingo\ResellerApi\PanelController;
use Namingo\ResellerApi\PanelData;
use Namingo\ResellerApi\PanelSession;
use Namingo\ResellerApi\Util;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Slim\Views\Twig;

require dirname(__DIR__) . '/vendor/autoload.php';
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true];
$adminDb = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$db = 'reseller_panel_test_' . bin2hex(random_bytes(6));
$adminDb->exec("CREATE DATABASE `$db`");
$pdo = new PDO($dsn . ';dbname=' . $db, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$_ENV['APP_MASTER_KEY'] = 'base64:' . base64_encode(random_bytes(32));
$_ENV['APP_ENV'] = 'local';
$_ENV['PANEL_ORIGIN'] = 'http://localhost';
$sessionRoot = sys_get_temp_dir() . '/' . $db;
PanelSession::start($sessionRoot);
$assertions = 0;
function verify(bool $ok, string $message): void
{
    global $assertions;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}
function denied(string $error, callable $call): void
{
    try {
        $call();
    } catch (ApiException $e) {
        verify($e->errorCode === $error, 'Expected ' . $error . ', got ' . $e->errorCode);
        return;
    }
    throw new RuntimeException('Expected ' . $error);
}
function request(string $path, string $method = 'GET', array $data = [], bool $csrf = true, string $origin = ''): Response
{
    global $panel;
    if ($method === 'POST' && $csrf) {
        $data['csrf'] = $_SESSION['csrf'];
    }
    $request = new ServerRequest($method, 'http://localhost' . $path, $origin === '' ? [] : ['Origin' => $origin], '', '1.1', ['REMOTE_ADDR' => '127.0.0.1']);
    parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
    return $panel->handle($request->withQueryParams($query)->withParsedBody($data), new Response());
}
function body(Response $response): string { return (string) $response->getBody(); }
function action(string $action, array $params): string
{
    global $pdo, $rootUser;
    return Admin::perform($pdo, $rootUser, $action, $params, '127.0.0.1');
}
function login(string $username, string $password): void
{
    global $pdo;
    PanelSession::login(Auth::login($pdo, $username, $password, '127.0.0.1'));
}

try {
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $rootUser = Util::uuid();
    $password = 'correct horse battery staple';
    $hash = password_hash($password, PASSWORD_ARGON2ID);
    $pdo->prepare("INSERT INTO resellers (id, username, password_hash, currency, role) VALUES (?, 'administrator', ?, 'EUR', 0)")->execute([$rootUser, $hash]);
    $pdo->prepare("INSERT INTO wallets (reseller_id, currency, available_balance) VALUES (?, 'EUR', 500)")->execute([$rootUser]);
    $a = action('user.create', ['username' => 'reseller-a', 'currency' => 'EUR', 'password' => $password, 'password_confirm' => $password]);
    $b = action('user.create', ['username' => 'reseller-b', 'currency' => 'EUR', 'password' => $password, 'password_confirm' => $password]);
    $pdo->prepare('UPDATE wallets SET available_balance = 100 WHERE reseller_id IN (?, ?)')->execute([$a, $b]);
    $config = ['connection' => ['host' => 'registry.invalid', 'port' => 700], 'login' => ['clID' => 'shared', 'pw' => 'upstream-secret']];
    $registry = action('registry.save', ['name' => 'Shared registry', 'profile' => 'generic', 'config_json' => Util::json($config)]);
    foreach ([$a, $b, $rootUser] as $reseller) {
        action('registry.access', ['registry_id' => $registry, 'reseller_id' => $reseller, 'enabled' => '1']);
    }
    foreach (['com', 'net', 'uk', 'co.uk'] as $tld) {
        action('tld.save', ['registry_id' => $registry, 'tld' => $tld, 'enabled' => '1']);
    }
    foreach (['com', 'uk', 'co.uk'] as $tld) {
        action('tld.access', ['registry_id' => $registry, 'reseller_id' => $a, 'tld' => $tld, 'enabled' => '1']);
    }
    action('tld.access', ['registry_id' => $registry, 'reseller_id' => $b, 'tld' => 'net', 'enabled' => '1']);
    $basePrice = ['registry_id' => $registry, 'scope' => 'global', 'command' => 'domain.create', 'tld' => 'com', 'period' => '1', 'amount' => '12', 'currency' => 'EUR'];
    action('price.set', $basePrice);
    action('price.set', array_replace($basePrice, ['scope' => $a, 'amount' => '9']));
    action('price.set', array_replace($basePrice, ['scope' => $b, 'amount' => '999']));
    action('price.set', array_replace($basePrice, ['tld' => 'net', 'amount' => '7']));
    foreach (CommandCatalog::all() as $entry) {
        if (!CommandCatalog::requiresPrice($entry['command'])) {
            denied('unsupported_price_command', fn () => action('price.set', array_replace($basePrice, ['command' => $entry['command'], 'tld' => '', 'period' => '0', 'amount' => '0'])));
        }
    }
    // Old rows remain readable and removable, but cannot be updated through the panel.
    $pdo->prepare("INSERT INTO registry_prices (registry_id, command, amount, currency) VALUES (?, 'domain.check', 0, 'EUR')")->execute([$registry]);
    denied('unsupported_price_command', fn () => action('price.set', array_replace($basePrice, ['command' => 'domain.check', 'tld' => '', 'period' => '0', 'amount' => '1'])));
    verify(count(array_filter(PanelData::prices($pdo, $a), fn ($p) => $p['command'] === 'domain.check')) === 0, 'Unsupported legacy prices are excluded from reseller price lists');
    action('price.delete', array_replace($basePrice, ['command' => 'domain.check', 'tld' => '', 'period' => '0']));
    verify(count(array_filter(PanelData::prices($pdo, $a), fn ($p) => $p['command'] === 'domain.check')) === 0, 'Legacy prices can be removed');
    $pdo->prepare("INSERT INTO registry_prices (registry_id, command, amount, currency) VALUES (?, 'domain.check', 0, 'EUR')")->execute([$registry]);
    denied('invalid_price_scope', fn () => action('price.set', array_replace($basePrice, ['tld' => '', 'period' => '0'])));
    denied('admin_required', fn () => Admin::perform($pdo, $a, 'user.role', ['reseller_id' => $a, 'role' => '0'], '127.0.0.1'));
    denied('invalid_state', fn () => action('user.role', ['reseller_id' => $rootUser, 'role' => '1']));
    $prices = PanelData::prices($pdo, $a);
    verify(count($prices) === 1 && $prices[0]['amount'] !== '999.0000', 'Only effective own/global prices for permitted TLDs are visible');
    verify(count(array_filter($prices, fn ($p) => $p['amount'] === '9.0000')) === 1, 'Own override replaces same global price');
    denied('tld_not_permitted', fn () => Access::domains($pdo, $registry, $a, 'domain.check', ['domains' => ['other.net']]));
    Access::domains($pdo, $registry, $a, 'domain.check', ['domains' => ['mine.com', 'mine.co.uk']]);
    action('tld.access', ['registry_id' => $registry, 'reseller_id' => $a, 'tld' => 'co.uk', 'enabled' => '0']);
    denied('tld_not_permitted', fn () => Access::domains($pdo, $registry, $a, 'domain.check', ['domains' => ['mine.co.uk']]));
    action('tld.save', ['registry_id' => $registry, 'tld' => 'com', 'enabled' => '0']);
    denied('tld_not_permitted', fn () => Ledger::createJob($pdo, $a, $registry, 'domain.create', Util::uuid(), ['domainname' => 'blocked.com', 'period' => 1]));
    action('tld.save', ['registry_id' => $registry, 'tld' => 'com', 'enabled' => '1']);
    denied('invalid_domains', fn () => Access::domains($pdo, $registry, $a, 'domain.check', ['domains' => []]));
    denied('invalid_domains', fn () => Access::domains($pdo, $registry, $a, 'domain.check', ['domains' => array_fill(0, 51, 'mine.com')]));

    $history = [];
    $mock = new MockHandler();
    $stack = HandlerStack::create($mock); $stack->push(Middleware::history($history));
    $api = new PanelApi(new Client(['handler' => $stack, 'base_uri' => 'http://localhost/']));
    $view = Twig::create(dirname(__DIR__) . '/panel/views', ['cache' => false, 'autoescape' => 'html']);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/panel/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
            $view->getEnvironment()->load(substr($file->getPathname(), strlen(dirname(__DIR__) . '/panel/views/')));
        }
    }
    $panel = new PanelController($pdo, $view, $api);
    verify(request('/')->getStatusCode() === 303, 'Guest redirects to login');
    verify(request('/login')->getStatusCode() === 200, 'Login page renders');
    verify(request('/login', 'POST', ['username' => 'reseller-a', 'password' => $password], false)->getStatusCode() === 403, 'Login CSRF enforced');
    verify(request('/login', 'POST', ['username' => 'reseller-a', 'password' => $password], true, 'https://attacker.invalid')->getStatusCode() === 403, 'Cross-origin login blocked');
    $oldSession = session_id();
    verify(request('/login', 'POST', ['username' => 'reseller-a', 'password' => $password])->getStatusCode() === 303, 'API credentials log in to panel');
    verify(session_id() !== $oldSession, 'Session ID rotates at login');
    $cookie = session_get_cookie_params();
    verify($cookie['httponly'] && $cookie['samesite'] === 'Strict' && $cookie['path'] === '/', 'Cookie protections');
    $bearer = $_SESSION['api_token'];
    verify(Auth::authenticate($pdo, 'Bearer ' . $bearer)['id'] === $a, 'Panel uses real scoped API token');
    action('object.assign', ['registry_id' => $registry, 'reseller_id' => $a, 'object_type' => 'domain', 'object_name' => 'own.com']);
    action('object.assign', ['registry_id' => $registry, 'reseller_id' => $b, 'object_type' => 'domain', 'object_name' => 'private.net']);
    foreach (['/', '/account', '/prices', '/access', '/wallet', '/domains', '/checks', '/jobs'] as $path) {
        $response = request($path);
        verify($response->getStatusCode() === 200, 'Reseller page renders: ' . $path . ' ' . body($response));
        verify(!str_contains(body($response), $bearer) && !str_contains(body($response), 'upstream-secret'), 'No bearer token or registry secrets in HTML');
    }
    $walletHtml = body(request('/wallet'));
    verify(str_contains($walletHtml, 'action="/wallet/deposits"') && str_contains($walletHtml, 'only after receipt of funds is verified'), 'Wallet offers a deposit request with approval explanation');
    verify(preg_match('/name="reference" value="[a-f0-9-]{36}"/', $walletHtml) === 1, 'Deposit form generates a reusable reference');
    $depositInput = ['amount' => '25.50', 'reference' => Util::uuid(), 'currency' => 'USD', 'reseller_id' => $b];
    verify(request('/wallet/deposits', 'POST', $depositInput, false)->getStatusCode() === 403, 'Deposit request requires CSRF');
    verify(request('/wallet/deposits', 'POST', $depositInput, true, 'https://attacker.invalid')->getStatusCode() === 403, 'Cross-origin deposit blocked');
    foreach (['0', '-1', '1e3', '25.12345'] as $amount) {
        verify(request('/wallet/deposits', 'POST', array_replace($depositInput, ['amount' => $amount]))->getStatusCode() === 422, 'Invalid deposit amount is rejected');
    }
    verify(request('/wallet/deposits', 'POST', array_replace($depositInput, ['reference' => '<script>']))->getStatusCode() === 422, 'Invalid deposit reference rejected');
    $balance = $pdo->query("SELECT available_balance FROM wallets WHERE reseller_id='$a'")->fetchColumn();
    $depositId = Util::uuid();
    for ($retry = 0; $retry < 2; ++$retry) {
        $mock->append(new Response(202, [], Util::json(['data' => ['deposit_uuid' => $depositId, 'status' => 'pending']])));
        verify(request('/wallet/deposits', 'POST', $depositInput)->getHeaderLine('Location') === '/wallet', 'Deposit submitted through API and redirects to wallet');
        $sent = end($history)['request'];
        verify($sent->getUri()->getPath() === '/v1/deposits' && $sent->getHeaderLine('Authorization') === 'Bearer ' . $bearer, 'Deposit uses scoped API bearer');
        verify(json_decode((string)$sent->getBody(), true) === ['amount' => '25.5000', 'currency' => 'EUR', 'reference' => $depositInput['reference']], 'Deposit fixes currency to wallet, preserves retry reference, and ignores foreign reseller');
    }
    verify(str_contains(body(request('/wallet')), $depositId), 'Wallet confirms deposit reference after submission');
    verify($pdo->query("SELECT available_balance FROM wallets WHERE reseller_id='$a'")->fetchColumn() === $balance, 'Panel request never credits wallet directly');
    $mock->append(new Response(409, [], Util::json(['error' => ['message' => 'Reference already used with a different amount.']])));
    verify(request('/wallet/deposits', 'POST', $depositInput)->getStatusCode() === 409, 'Deposit API errors are shown without reporting success');
    $history = [];
    $objects = body(request('/domains'));
    verify(str_contains($objects, 'own.com') && !str_contains($objects, 'private.net'), 'Object list scoped to current reseller');
    verify(!str_contains(body(request('/prices')), '999.0000'), 'Other reseller price override hidden');
    verify(str_contains(body(request('/jobs')), 'All statuses') && str_contains(body(request('/jobs')), 'All commands'), 'Job history filters are available');
    for ($i = 0; $i < 51; ++$i) {
        Ledger::createJob($pdo, $a, $registry, 'domain.check', Util::uuid(), ['domains' => ['history.com']]);
    }
    $foreignJob = Ledger::createJob($pdo, $b, $registry, 'domain.check', Util::uuid(), ['domains' => ['private.net']]);
    verify(request('/admin/resellers/' . $b)->getStatusCode() === 403, 'Reseller cannot open account editor');
    verify(request('/admin/resellers/' . $b, 'POST', ['username' => 'hijacked'])->getStatusCode() === 403, 'Reseller cannot edit another account');
    verify(request('/admin/jobs/' . $foreignJob['job_id'])->getStatusCode() === 403, 'Reseller cannot investigate another reseller job');
    $historyHtml = body(request('/jobs?status=queued&command=domain.check'));
    verify(str_contains($historyHtml, '/jobs?page=2&amp;status=queued&amp;command=domain.check'), 'Job pagination retains filters');
    verify(!str_contains($historyHtml, $foreignJob['job_id']), 'Panel history cannot show another reseller job');
    $lastPage = body(request('/jobs?page=2&status=queued&command=domain.check'));
    verify(substr_count($lastPage, '<td class="uuid">') === 1 && str_contains($lastPage, 'Previous'), 'Panel can browse beyond the first 50 jobs');

    foreach (['resellers', 'registries', 'tlds', 'prices', 'deposits', 'jobs', 'ownership'] as $section) {
        verify(request('/admin/' . $section)->getStatusCode() === 403, 'Reseller cannot view admin page');
        verify(request('/admin/' . $section, 'POST', ['action' => 'user.role', 'reseller_id' => $a, 'role' => '0'])->getStatusCode() === 403, 'Reseller cannot submit admin actions');
    }
    verify(!str_contains(body(request('/checks')), 'name="registry_id"'), 'Availability form has no registry dropdown');
    $unsupported = request('/checks', 'POST', ['key' => Util::uuid(), 'domains' => 'other.net']);
    verify($unsupported->getStatusCode() === 303 && str_contains(body(request($unsupported->getHeaderLine('Location'))), 'Not supported'), 'Unpermitted TLD gets its own result');
    verify(request('/checks', 'POST', ['key' => Util::uuid(), 'domains' => ''])->getStatusCode() === 422, 'Empty batch is rejected');
    verify(request('/checks', 'POST', ['key' => Util::uuid(), 'domains' => implode(' ', array_fill(0, 51, 'too.com'))])->getStatusCode() === 422, 'Oversized batch is rejected');
    verify(count($history) === 0, 'Invalid panel check never calls API');
    $checkId = Util::uuid();
    $checkJob = ['job_uuid' => $checkId, 'registry_name' => 'Shared registry', 'command' => 'domain.check', 'status' => 'succeeded',
        'price' => '0.0000', 'currency' => 'EUR', 'result' => ['domains' => [['name' => 'available.com', 'avail' => true, 'reason' => ''], ['name' => 'taken.com', 'avail' => false, 'reason' => '<script>alert(1)</script>']]]];
    $mock->append(new Response(200, [], Util::json(['data' => $checkJob])));
    $key = Util::uuid();
    $response = request('/checks', 'POST', ['key' => $key, 'domains' => "available.com\ntaken.com"]);
    verify($response->getHeaderLine('Location') === '/checks?batch=' . $key, 'Check redirects to batch results');
    verify($history[0]['request']->getUri()->getPath() === '/v1/epp/domain.check'
        && $history[0]['request']->getHeaderLine('Authorization') === 'Bearer ' . $bearer
        && $history[0]['request']->getHeaderLine('Idempotency-Key') === 'check-' . hash('sha256', $key . ':' . $registry)
        && $history[0]['request']->getHeaderLine('Prefer') === 'respond-async', 'Availability endpoint uses scoped token, deterministic registry key and async submission');
    verify(json_decode((string) $history[0]['request']->getBody(), true)['params']['domains'] === ['available.com', 'taken.com'], 'Checks sent natively through API JSON');
    $mock->append(new Response(200, [], Util::json(['data' => $checkJob])));
    $result = body(request('/checks/' . $checkId));
    verify(str_contains($result, 'Available') && str_contains($result, 'Unavailable'), 'Readable check result');
    verify(!str_contains($result, '<script>alert(1)</script>') && str_contains($result, '&lt;script&gt;'), 'Registry result HTML escaped');
    $mock->append(new Response(200, [], Util::json(['data' => $checkJob])));
    $batchResult = body(request('/checks?batch=' . $key));
    verify(str_contains($batchResult, 'Available') && str_contains($batchResult, 'Unavailable') && !str_contains($batchResult, '<script>'), 'Batch renders escaped per-domain availability');
    $calls = count($history);
    verify(request('/checks', 'POST', ['key' => $key, 'domains' => "available.com\ntaken.com"])->getStatusCode() === 303 && count($history) === $calls, 'Repeated form does not submit more jobs');
    verify(request('/checks', 'POST', ['key' => $key, 'domains' => 'different.com'])->getStatusCode() === 409, 'Batch key cannot be reused with other input');
    verify(request('/checks?batch=' . Util::uuid())->getStatusCode() === 404, 'Unknown session batch is rejected');

    $second = action('registry.save', ['name' => 'Second registry', 'profile' => 'generic', 'config_json' => Util::json($config)]);
    action('registry.access', ['registry_id' => $second, 'reseller_id' => $a, 'enabled' => '1']);
    foreach (['net', 'co.uk'] as $zone) {
        action('tld.save', ['registry_id' => $second, 'tld' => $zone, 'enabled' => '1']);
        action('tld.access', ['registry_id' => $second, 'reseller_id' => $a, 'tld' => $zone, 'enabled' => '1']);
    }
    action('tld.access', ['registry_id' => $registry, 'reseller_id' => $a, 'tld' => 'co.uk', 'enabled' => '1']);
    $routes = PanelData::checkRoutes($pdo, $a, ['UPPER.COM', 'name.co.uk', 'unknown.xyz', '<bad>']);
    verify($routes[0]['registry_id'] === $registry && $routes[0]['name'] === 'upper.com', 'Routing normalizes case');
    verify($routes[1]['status'] === 'Ambiguous TLD' && !isset($routes[1]['registry_id']), 'Equal registry matches are not guessed');
    verify($routes[2]['status'] === 'Not supported' && $routes[3]['status'] === 'Invalid domain', 'Unsupported and invalid names get individual errors');
    action('tld.access', ['registry_id' => $registry, 'reseller_id' => $a, 'tld' => 'co.uk', 'enabled' => '0']);
    $routes = PanelData::checkRoutes($pdo, $a, ['name.co.uk']);
    verify($routes[0]['registry_id'] === $second, 'Disabled specific zone never falls back to broader zone in same registry');
    action('tld.access', ['registry_id' => $registry, 'reseller_id' => $a, 'tld' => 'co.uk', 'enabled' => '1']);
    // With only a broader zone in the first registry, prefer the more specific second one.
    $pdo->prepare('DELETE FROM reseller_tlds WHERE registry_id = ? AND tld = ?')->execute([$registry, 'co.uk']);
    $pdo->prepare('DELETE FROM registry_tlds WHERE registry_id = ? AND tld = ?')->execute([$registry, 'co.uk']);
    verify(PanelData::checkRoutes($pdo, $a, ['name.co.uk'])[0]['registry_id'] === $second, 'Longest multi-label suffix wins across registries');
    action('tld.save', ['registry_id' => $registry, 'tld' => 'co.uk', 'enabled' => '1']);
    action('tld.access', ['registry_id' => $registry, 'reseller_id' => $a, 'tld' => 'co.uk', 'enabled' => '1']);

    $mixedKey = Util::uuid();
    $pendingId = Util::uuid();
    $mock->append(new Response(202, [], Util::json(['data' => ['job_uuid' => $pendingId]])));
    $mock->append(new Response(503, [], Util::json(['error' => ['message' => 'Registry unavailable.']])));
    $mixed = request('/checks', 'POST', ['key' => $mixedKey, 'domains' => 'first.com second.net unknown.xyz bad<script>']);
    $submitted = array_slice($history, -2);
    verify(json_decode((string) $submitted[0]['request']->getBody(), true)['registry_uuid'] === $registry
        && json_decode((string) $submitted[1]['request']->getBody(), true)['registry_uuid'] === $second, 'Mixed TLDs submit separate registry groups');
    verify($submitted[0]['request']->getHeaderLine('Idempotency-Key') !== $submitted[1]['request']->getHeaderLine('Idempotency-Key'), 'Registry groups have distinct stable keys');
    $mock->append(new Response(200, [], Util::json(['data' => ['job_uuid' => $pendingId, 'command' => 'domain.check', 'status' => 'queued']])));
    $mixedHtml = body(request($mixed->getHeaderLine('Location')));
    verify(str_contains($mixedHtml, 'Queued') && str_contains($mixedHtml, 'Registry unavailable.') && str_contains($mixedHtml, 'Not supported') && str_contains($mixedHtml, 'Invalid domain'), 'Pending, failed, unsupported and invalid domains coexist');
    verify(!str_contains($mixedHtml, 'bad<script>'), 'Invalid domain input is HTML escaped');
    $mock->append(new Response(200, [], Util::json(['data' => ['job_uuid' => $pendingId, 'command' => 'domain.check', 'status' => 'rejected', 'result' => ['message' => 'Check not supported.']]])));
    verify(str_contains(body(request('/checks?batch=' . $mixedKey)), 'Check not supported.'), 'Registry rejection appears in batch results');
    action('registry.status', ['registry_id' => $second, 'enabled' => '0']);
    verify(!isset(PanelData::checkRoutes($pdo, $a, ['second.net'])[0]['registry_id']), 'Disabled registry is not selected');
    // Remove the temporary registry access so later migration fixture counts stay unchanged.
    $pdo->prepare('DELETE FROM reseller_tlds WHERE registry_id = ?')->execute([$second]);
    $pdo->prepare('DELETE FROM reseller_registries WHERE registry_id = ?')->execute([$second]);
    $mock->append(new Response(403, [], Util::json(['error' => ['message' => 'Job unavailable.']])));
    verify(request('/checks/' . Util::uuid())->getStatusCode() === 403, 'API denies foreign job reads');
    $mock->append(new Response(200, [], Util::json(['data' => array_replace($checkJob, ['command' => 'domain.create'])])));
    verify(request('/checks/' . Util::uuid())->getStatusCode() === 403, 'Panel does not expose provisioning job results');
    verify(request('/domains', 'POST', [])->getStatusCode() === 405, 'No domain registration or mutation route');
    $lifecycleId = Util::uuid();
    $lifecycleJob = array_replace($checkJob, ['job_uuid' => $lifecycleId, 'registry_uuid' => $registry, 'domainname' => 'own.com',
        'command' => 'domain.restore', 'profile' => 'generic', 'transfer_op' => null, 'status' => 'pending', 'parent_job_uuid' => null, 'last_error' => null, 'result' => ['code' => 1001]]);
    $mock->append(new Response(200, [], Util::json(['data' => $lifecycleJob])));
    $detail = body(request('/jobs/' . $lifecycleId));
    verify(str_contains($detail, 'Submit a restore report') && str_contains($detail, 'Check registry outcome'), 'Pending restore page exposes report and reconciliation');
    verify(request('/jobs/' . $lifecycleId . '/reconcile', 'POST', [], false)->getStatusCode() === 403, 'Lifecycle reconciliation requires CSRF');
    $mock->append(new Response(202, [], Util::json(['data' => $lifecycleJob])));
    verify(request('/jobs/' . $lifecycleId . '/reconcile', 'POST')->getHeaderLine('Location') === '/jobs/' . $lifecycleId, 'Panel schedules pending reconciliation through API');
    verify(end($history)['request']->getUri()->getPath() === '/v1/jobs/' . $lifecycleId . '/reconcile', 'Reconciliation uses scoped API endpoint');
    $pdo->prepare("UPDATE object_ownership SET domain_state = 'deleted' WHERE registry_id = ? AND object_name = 'own.com'")->execute([$registry]);
    verify(str_contains(body(request('/domains')), 'Restore at configured price'), 'Deleted owned domain can be restored from Objects');
    $restoreInput = ['command' => 'domain.restore', 'registry_id' => $registry, 'domainname' => 'own.com', 'key' => Util::uuid()];
    $mock->append(new Response(202, [], Util::json(['data' => $lifecycleJob])));
    verify(request('/lifecycle', 'POST', $restoreInput)->getHeaderLine('Location') === '/jobs/' . $lifecycleId, 'Panel restore submits through API');
    verify(end($history)['request']->getUri()->getPath() === '/v1/epp/domain.restore', 'Panel restore uses correct command');
    $reportInput = array_replace($restoreInput, ['command' => 'domain.restore_report', 'key' => Util::uuid(), 'pre_data' => 'Before', 'post_data' => 'After',
        'deleted_at' => '2026-01-01T12:00:00Z', 'restored_at' => '2026-01-02T12:00:00Z', 'reason' => 'Registrant request', 'statement1' => 'Accurate first statement', 'statement2' => 'Accurate second statement']);
    $mock->append(new Response(202, [], Util::json(['data' => $lifecycleJob])));
    verify(request('/lifecycle', 'POST', $reportInput)->getStatusCode() === 303, 'Panel validates and submits actual restore report');
    verify(json_decode((string) end($history)['request']->getBody(), true)['params']['statements'] === [$reportInput['statement1'], $reportInput['statement2']], 'Report statements are preserved');
    verify(request('/lifecycle', 'POST', array_replace($restoreInput, ['command' => 'domain.create']))->getStatusCode() === 422, 'Lifecycle controls do not expose registration');
    $transferJob = array_replace($lifecycleJob, ['command' => 'domain.transfer', 'transfer_op' => 'request']);
    $mock->append(new Response(200, [], Util::json(['data' => $transferJob])));
    verify(str_contains(body(request('/jobs/' . $lifecycleId)), 'Cancel pending incoming transfer'), 'Pending transfer page offers query and cancellation');
    $mock->append(new Response(200, [], Util::json(['data' => array_replace($transferJob, ['status' => 'succeeded'])])));
    verify(request('/lifecycle', 'POST', array_replace($restoreInput, ['command' => 'domain.transfer', 'op' => 'query']))->getStatusCode() === 303, 'Panel submits transfer query through API');
    verify(request('/lifecycle', 'POST', array_replace($restoreInput, ['command' => 'domain.transfer', 'op' => 'request']))->getStatusCode() === 422, 'Panel does not start new transfers');
    $mock->append(new Response(200, [], Util::json(['data' => $checkJob])));
    $batchResult = body(request('/checks?batch=' . $key));
    verify(str_contains($batchResult, 'Available') && str_contains($batchResult, 'Unavailable') && !str_contains($batchResult, '<script>'), 'Batch renders escaped per-domain availability');
    $calls = count($history);
    verify(request('/checks', 'POST', ['key' => $key, 'domains' => "available.com\ntaken.com"])->getStatusCode() === 303 && count($history) === $calls, 'Repeated form does not submit more jobs');
    verify(request('/checks', 'POST', ['key' => $key, 'domains' => 'different.com'])->getStatusCode() === 409, 'Batch key cannot be reused with other input');
    verify(request('/checks?batch=' . Util::uuid())->getStatusCode() === 404, 'Unknown session batch is rejected');

    $second = action('registry.save', ['name' => 'Second registry', 'profile' => 'generic', 'config_json' => Util::json($config)]);
    action('registry.access', ['registry_id' => $second, 'reseller_id' => $a, 'enabled' => '1']);
    foreach (['net', 'co.uk'] as $zone) {
        action('tld.save', ['registry_id' => $second, 'tld' => $zone, 'enabled' => '1']);
        action('tld.access', ['registry_id' => $second, 'reseller_id' => $a, 'tld' => $zone, 'enabled' => '1']);
    }
    action('tld.access', ['registry_id' => $registry, 'reseller_id' => $a, 'tld' => 'co.uk', 'enabled' => '1']);
    $routes = PanelData::checkRoutes($pdo, $a, ['UPPER.COM', 'name.co.uk', 'unknown.xyz', '<bad>']);
    verify($routes[0]['registry_id'] === $registry && $routes[0]['name'] === 'upper.com', 'Routing normalizes case');
    verify($routes[1]['status'] === 'Ambiguous TLD' && !isset($routes[1]['registry_id']), 'Equal registry matches are not guessed');
    verify($routes[2]['status'] === 'Not supported' && $routes[3]['status'] === 'Invalid domain', 'Unsupported and invalid names get individual errors');
    action('tld.access', ['registry_id' => $registry, 'reseller_id' => $a, 'tld' => 'co.uk', 'enabled' => '0']);
    $routes = PanelData::checkRoutes($pdo, $a, ['name.co.uk']);
    verify($routes[0]['registry_id'] === $second, 'Disabled specific zone never falls back to broader zone in same registry');
    action('tld.access', ['registry_id' => $registry, 'reseller_id' => $a, 'tld' => 'co.uk', 'enabled' => '1']);
    // With only a broader zone in the first registry, prefer the more specific second one.
    $pdo->prepare('DELETE FROM reseller_tlds WHERE registry_id = ? AND tld = ?')->execute([$registry, 'co.uk']);
    $pdo->prepare('DELETE FROM registry_tlds WHERE registry_id = ? AND tld = ?')->execute([$registry, 'co.uk']);
    verify(PanelData::checkRoutes($pdo, $a, ['name.co.uk'])[0]['registry_id'] === $second, 'Longest multi-label suffix wins across registries');
    action('tld.save', ['registry_id' => $registry, 'tld' => 'co.uk', 'enabled' => '1']);
    action('tld.access', ['registry_id' => $registry, 'reseller_id' => $a, 'tld' => 'co.uk', 'enabled' => '1']);

    $mixedKey = Util::uuid();
    $pendingId = Util::uuid();
    $mock->append(new Response(202, [], Util::json(['data' => ['job_uuid' => $pendingId]])));
    $mock->append(new Response(503, [], Util::json(['error' => ['message' => 'Registry unavailable.']])));
    $mixed = request('/checks', 'POST', ['key' => $mixedKey, 'domains' => 'first.com second.net unknown.xyz bad<script>']);
    $submitted = array_slice($history, -2);
    verify(json_decode((string) $submitted[0]['request']->getBody(), true)['registry_uuid'] === $registry
        && json_decode((string) $submitted[1]['request']->getBody(), true)['registry_uuid'] === $second, 'Mixed TLDs submit separate registry groups');
    verify($submitted[0]['request']->getHeaderLine('Idempotency-Key') !== $submitted[1]['request']->getHeaderLine('Idempotency-Key'), 'Registry groups have distinct stable keys');
    $mock->append(new Response(200, [], Util::json(['data' => ['job_uuid' => $pendingId, 'command' => 'domain.check', 'status' => 'queued']])));
    $mixedHtml = body(request($mixed->getHeaderLine('Location')));
    verify(str_contains($mixedHtml, 'Queued') && str_contains($mixedHtml, 'Registry unavailable.') && str_contains($mixedHtml, 'Not supported') && str_contains($mixedHtml, 'Invalid domain'), 'Pending, failed, unsupported and invalid domains coexist');
    verify(!str_contains($mixedHtml, 'bad<script>'), 'Invalid domain input is HTML escaped');
    $mock->append(new Response(200, [], Util::json(['data' => ['job_uuid' => $pendingId, 'command' => 'domain.check', 'status' => 'rejected', 'result' => ['message' => 'Check not supported.']]])));
    verify(str_contains(body(request('/checks?batch=' . $mixedKey)), 'Check not supported.'), 'Registry rejection appears in batch results');
    action('registry.status', ['registry_id' => $second, 'enabled' => '0']);
    verify(!isset(PanelData::checkRoutes($pdo, $a, ['second.net'])[0]['registry_id']), 'Disabled registry is not selected');
    // Remove the temporary registry access so later migration fixture counts stay unchanged.
    $pdo->prepare('DELETE FROM reseller_tlds WHERE registry_id = ?')->execute([$second]);
    $pdo->prepare('DELETE FROM reseller_registries WHERE registry_id = ?')->execute([$second]);
    $mock->append(new Response(403, [], Util::json(['error' => ['message' => 'Job unavailable.']])));
    verify(request('/jobs/' . Util::uuid())->getStatusCode() === 403, 'Job details preserve API owner isolation');
    $pdo->prepare("UPDATE object_ownership SET domain_state = 'active' WHERE registry_id = ? AND object_name = 'own.com'")->execute([$registry]);


    login('administrator', $password);
    $accountPage = request('/admin/resellers/' . $a);
    verify($accountPage->getStatusCode() === 200 && str_contains(body($accountPage), 'name="username" value="reseller-a"') && str_contains(body($accountPage), 'name="address"'), 'Account editor preloads username and general details');
    verify(request('/admin/resellers/' . $a, 'POST', ['company' => 'Example'], false)->getStatusCode() === 403, 'Account editing requires CSRF');
    verify(request('/admin/resellers/' . $a, 'POST', ['reseller_id' => $b, 'company' => '<script>Company</script>', 'currency' => 'USD'])->getStatusCode() === 303, 'Admin can save general account details');
    verify($pdo->query("SELECT company FROM resellers WHERE id='$a'")->fetchColumn() === '<script>Company</script>' && $pdo->query("SELECT company FROM resellers WHERE id='$b'")->fetchColumn() === '', 'Account editor uses route ID and cannot be redirected to another account');
    verify($pdo->query("SELECT currency FROM resellers WHERE id='$a'")->fetchColumn() === 'EUR', 'Account edit does not change wallet currency');
    $editedHtml = body(request('/admin/resellers/' . $a));
    verify(str_contains($editedHtml, '&lt;script&gt;Company&lt;/script&gt;') && !str_contains($editedHtml, '<script>Company</script>'), 'Account details are HTML escaped');
    verify(request('/admin/resellers/' . Util::uuid())->getStatusCode() === 404, 'Missing account editor returns 404');
    verify(str_contains(body(request('/admin/resellers')), '/admin/resellers/' . $a), 'Reseller table links to editable accounts');
    $tldHtml = body(request('/admin/tlds'));
    verify(str_contains($tldHtml, 'value="tld.delete"') && str_contains($tldHtml, 'Remove unused TLD') && str_contains($tldHtml, 'value="tld.unassign"'), 'TLD table offers removal, state and assignment controls');
    verify(request('/admin/tlds', 'POST', ['action' => 'tld.delete', 'registry_id' => $registry, 'tld' => 'com'])->getStatusCode() === 422, 'TLD removal requires explicit form confirmation');
    verify(request('/admin/tlds', 'POST', ['action' => 'tld.delete', 'registry_id' => $registry, 'tld' => 'com', 'confirmed' => '1'])->getStatusCode() === 409, 'Panel blocks removal of used TLD');
    $emptyRegistry = action('registry.save', ['name' => 'Unused registry', 'profile' => 'generic', 'config_json' => Util::json($config)]);
    verify(request('/admin/tlds', 'POST', ['action' => 'tld.save', 'registry_id' => $emptyRegistry, 'tld' => 'test', 'enabled' => '1'])->getStatusCode() === 303, 'Panel adds TLD');
    verify(request('/admin/tlds', 'POST', ['action' => 'tld.save', 'registry_id' => $emptyRegistry, 'tld' => 'test', 'enabled' => '0'])->getStatusCode() === 303, 'Panel edits TLD state');
    verify(request('/admin/tlds', 'POST', ['action' => 'tld.delete', 'registry_id' => $emptyRegistry, 'tld' => 'test', 'confirmed' => '1'])->getStatusCode() === 303, 'Panel removes unused TLD');
    foreach (['resellers', 'registries', 'tlds', 'prices', 'deposits', 'jobs', 'ownership'] as $section) {
        verify(request('/admin/' . $section)->getStatusCode() === 200, 'Admin page renders: ' . $section);
    }
    verify(str_contains(body(request('/admin/jobs')), '/admin/jobs/' . $foreignJob['job_id']), 'Admin job table links to job details');
    Ledger::completeJob($pdo, $foreignJob['job_id'], 'rejected', ['code' => 2304, 'message' => '<script>registry failure</script>'], 2304);
    $pdo->prepare("UPDATE epp_jobs SET last_error = 'Support diagnostic' WHERE id = ?")->execute([$foreignJob['job_id']]);
    $adminDetail = request('/admin/jobs/' . $foreignJob['job_id']);
    verify($adminDetail->getStatusCode() === 200 && str_contains(body($adminDetail), 'reseller-b') && str_contains(body($adminDetail), $b), 'Admin can investigate another reseller and identify its owner');
    verify(str_contains(body($adminDetail), '2304') && str_contains(body($adminDetail), 'Support diagnostic') && str_contains(body($adminDetail), 'registry failure'), 'Admin details include registry response, EPP code and last error');
    verify(!str_contains(body($adminDetail), '<script>registry failure</script>') && str_contains(body($adminDetail), '&lt;script&gt;'), 'Admin response is HTML escaped');
    verify(!str_contains(body($adminDetail), 'upstream-secret') && !str_contains(body($adminDetail), $bearer), 'Admin details do not expose registry configuration or API token');
    verify(!str_contains(body($adminDetail), 'action="/lifecycle"') && !str_contains(body($adminDetail), '/reconcile"'), 'Admin investigation does not submit reseller-scoped actions with admin token');
    verify(request('/admin/jobs/' . Util::uuid())->getStatusCode() === 404, 'Missing admin job returns 404');
    $supportJob = Ledger::createJob($pdo, $b, $registry, 'domain.check', Util::uuid(), ['domains' => ['private.net']]);
    Ledger::completeJob($pdo, $supportJob['job_id'], 'unknown', ['error' => 'timeout'], null);
    $supportHtml = body(request('/admin/jobs/' . $supportJob['job_id']));
    verify(str_contains($supportHtml, 'action="/admin/jobs"') && str_contains($supportHtml, 'name="id" value="' . $supportJob['job_id'] . '"') && str_contains($supportHtml, 'name="confirmed"'), 'Admin details offer verified resolution for the selected unknown job');
    verify(request('/admin/jobs', 'POST', ['action' => 'job.resolve', 'id' => $supportJob['job_id'], 'resolution' => 'release', 'confirmed' => '1'], false)->getStatusCode() === 403, 'Detail resolution still requires CSRF');
    verify(request('/admin/jobs', 'POST', ['action' => 'job.resolve', 'id' => $supportJob['job_id'], 'resolution' => 'release'])->getStatusCode() === 422, 'Detail resolution still requires independent verification');
    verify(request('/admin/jobs', 'POST', ['action' => 'job.resolve', 'id' => $supportJob['job_id'], 'resolution' => 'release', 'confirmed' => '1'])->getStatusCode() === 303, 'Admin can resolve investigated job using existing action');
    verify(request('/admin/tlds', 'POST', ['action' => 'tld.save', 'registry_id' => $registry, 'tld' => 'test', 'enabled' => '1'], false)->getStatusCode() === 403, 'Admin mutations require CSRF');
    verify(request('/admin/tlds', 'POST', ['action' => 'user.role', 'reseller_id' => $a, 'role' => '0'])->getStatusCode() === 422, 'Admin action allowlist tied to page');
    $created = request('/admin/resellers', 'POST', ['action' => 'user.create', 'username' => 'panel-created', 'currency' => 'EUR', 'password' => $password, 'password_confirm' => $password]);
    verify($created->getStatusCode() === 303 && (int) $pdo->query("SELECT role FROM resellers WHERE username = 'panel-created'")->fetchColumn() === 1, 'Panel creates role-1 reseller in main tables');
    $pdo->prepare('UPDATE resellers SET role = 1 WHERE id = ?')->execute([$rootUser]);
    verify(request('/admin/resellers')->getStatusCode() === 403, 'Role demotion takes effect in current panel session');
    verify(request('/admin/jobs/' . $foreignJob['job_id'])->getStatusCode() === 403, 'Role demotion immediately blocks cross-reseller job details');
    $pdo->prepare('UPDATE resellers SET role = 0 WHERE id = ?')->execute([$rootUser]);
    login('reseller-a', $password);
    action('tokens.revoke', ['reseller_id' => $a]);
    verify(request('/wallet')->getHeaderLine('Location') === '/login', 'API token revocation invalidates panel session');
    login('reseller-a', $password);
    $_SESSION['last_seen'] = time() - 3600;
    verify(request('/wallet')->getHeaderLine('Location') === '/login', 'Idle timeout enforced');
    login('reseller-a', $password);
    action('user.password', ['reseller_id' => $a, 'password' => 'replacement long password', 'password_confirm' => 'replacement long password']);
    verify(request('/wallet')->getHeaderLine('Location') === '/login', 'Password reset invalidates active panel session');
    denied('invalid_credentials', fn () => Auth::login($pdo, 'reseller-a', $password, '192.0.2.3'));
    verify(Auth::login($pdo, 'reseller-a', 'replacement long password', '192.0.2.3')['reseller_uuid'] === $a, 'New password works for API too');
    $_ENV['API_LOGIN_LIMIT_PER_MINUTE'] = '2';
    denied('invalid_credentials', fn () => Auth::login($pdo, 'unknown-account', 'wrong', '192.0.2.4'));
    denied('invalid_credentials', fn () => Auth::login($pdo, 'unknown-account', 'wrong', '192.0.2.4'));
    denied('rate_limited', fn () => Auth::login($pdo, 'reseller-a', 'replacement long password', '192.0.2.4'));

    // A permission revoked after enqueue must stop the worker before any EPP connection.
    $job = Ledger::createJob($pdo, $a, $registry, 'domain.create', Util::uuid(), ['domainname' => 'queued.com', 'period' => 1, 'authInfoPw' => 'test-secret']);
    action('tld.access', ['registry_id' => $registry, 'reseller_id' => $a, 'tld' => 'com', 'enabled' => '0']);
    (new ReflectionProperty(Db::class, 'pdo'))->setValue(null, $pdo);
    $processed = EppWorker::process($job['job_id']);
    verify($processed['status'] === 'rejected', 'Worker rechecks TLD before connecting');
    $finished = Ledger::fetchJob($pdo, $job['job_id'], $a);
    verify(str_contains(Util::json($finished['result']), 'TLD is unavailable'), 'Worker rejected specifically on TLD access');
    verify(!(bool) $pdo->query("SELECT 1 FROM object_ownership WHERE object_name = 'queued.com'")->fetchColumn(), 'Rejected worker releases ownership reservation');

    // Upgrade an existing v2 database; no existing account is made administrator.
    $pdo->exec('DROP TABLE reseller_tlds, registry_tlds, auth_login_limits');
    $pdo->exec('ALTER TABLE resellers DROP CONSTRAINT chk_reseller_role, DROP COLUMN role');
    $pdo->exec("DELETE FROM schema_migrations WHERE version = '003_foundry_panel'");
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/003_foundry_panel.sql'));
    verify((int) $pdo->query('SELECT COUNT(*) FROM resellers WHERE role = 0')->fetchColumn() === 0, 'Migration defaults every existing account to reseller');
    verify((int) $pdo->query('SELECT COUNT(*) FROM registry_tlds')->fetchColumn() === 2, 'Migration backfills explicitly priced zones');
    verify((int) $pdo->query('SELECT COUNT(*) FROM reseller_tlds')->fetchColumn() === 6, 'Migration grants known zones to existing registry members');
    verify((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 5, 'Migration version recorded');
    $_ENV['APP_ENV'] = 'production'; $_ENV['PANEL_ORIGIN'] = 'https://panel.example.test';
    $_SERVER['HTTPS'] = '';
    try {
        PanelSession::start($sessionRoot);
        throw new LogicException('Plain HTTP was accepted in production');
    } catch (RuntimeException $e) {
        verify(str_contains($e->getMessage(), 'requires HTTPS'), 'Production panel refuses untrusted plaintext transport');
    }
    session_destroy(); $_COOKIE = []; $_SERVER['HTTPS'] = 'on';
    PanelSession::start($sessionRoot);
    verify(session_get_cookie_params()['secure'] && str_starts_with(session_name(), '__Host-'), 'Production uses host-only Secure session cookie');
    echo "PASS: {$assertions} panel/access assertions\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    Db::reset();
    $pdo = null;
    $adminDb->exec("DROP DATABASE `$db`");
    session_destroy();
    foreach (glob($sessionRoot . '/var/panel-sessions/*') ?: [] as $file) { unlink($file); }
    @rmdir($sessionRoot . '/var/panel-sessions'); @rmdir($sessionRoot . '/var'); @rmdir($sessionRoot);
}
