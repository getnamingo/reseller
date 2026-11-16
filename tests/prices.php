<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Namingo\ResellerApi\{Admin, ApiException, Auth, CommandCatalog, Crypto, Ledger, PanelApi, PanelController, PanelSession, Util};
use Nyholm\Psr7\{Response, ServerRequest};
use Slim\Views\Twig;

require dirname(__DIR__) . '/vendor/autoload.php';
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true];
$admin = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$db = 'reseller_prices_' . bin2hex(random_bytes(6));
$admin->exec("CREATE DATABASE `$db`");
$pdo = new PDO($dsn . ';dbname=' . $db, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$_ENV['APP_MASTER_KEY'] = 'base64:' . base64_encode(random_bytes(32));
$_ENV['APP_ENV'] = 'local'; $_ENV['PANEL_ORIGIN'] = 'http://localhost';
$sessionRoot = sys_get_temp_dir() . '/' . $db;
PanelSession::start($sessionRoot);
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function denied(string $code, callable $call): void {
    try { $call(); } catch (ApiException $e) { check($e->errorCode === $code, "Expected $code, got {$e->errorCode}"); return; }
    throw new RuntimeException("Expected $code");
}
function action(string $action, array $input): void { global $pdo, $root; Admin::perform($pdo, $root, $action, $input, '127.0.0.1'); }
function request(string $method, array $input = []): Response {
    global $panel;
    return $panel->handle((new ServerRequest($method, 'http://localhost/admin/prices', [], '', '1.1', ['REMOTE_ADDR' => '127.0.0.1']))->withParsedBody($input), new Response());
}
try {
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $root = Util::uuid(); $reseller = Util::uuid(); $registry = Util::uuid(); $password = 'a long test password';
    foreach ([$root => 0, $reseller => 1] as $id => $role) {
        $pdo->prepare('INSERT INTO resellers (id, username, password_hash, currency, role) VALUES (?, ?, ?, ?, ?)')->execute([$id, 'user-' . $role, password_hash($password, PASSWORD_ARGON2ID), 'EUR', $role]);
        $pdo->prepare("INSERT INTO wallets (reseller_id, currency, available_balance) VALUES (?, 'EUR', 1000)")->execute([$id]);
    }
    [$nonce, $cipher] = Crypto::encrypt(Util::json(['connection' => ['host' => 'registry.invalid'], 'login' => ['clID' => 'test', 'pw' => 'secret']]), 'registry-config:' . $registry);
    $pdo->prepare("INSERT INTO registries (id, name, profile, config_nonce, config_cipher) VALUES (?, 'Example registry', 'generic', ?, ?)")->execute([$registry, $nonce, $cipher]);
    $pdo->prepare('INSERT INTO reseller_registries (reseller_id, registry_id) VALUES (?, ?)')->execute([$reseller, $registry]);
    $pdo->prepare("INSERT INTO registry_tlds (registry_id, tld) VALUES (?, 'com')")->execute([$registry]);
    $pdo->prepare("INSERT INTO reseller_tlds (reseller_id, registry_id, tld) VALUES (?, ?, 'com')")->execute([$reseller, $registry]);
    $panel = new PanelController($pdo, Twig::create(dirname(__DIR__) . '/panel/views', ['cache' => false, 'autoescape' => 'html']), new PanelApi(new Client()));
    PanelSession::login(Auth::login($pdo, 'user-0', $password, '127.0.0.1'));
    $html = (string) request('GET')->getBody();
    check(substr_count($html, 'name="amounts[') === 42, 'One complete TLD table has 42 price fields');
    preg_match_all('/<input[^>]+name="amounts\[[^>]+>/', $html, $inputs);
    check(count($inputs[0]) === 42 && count(array_filter($inputs[0], fn ($field) => str_contains($field, 'required') && !str_contains($field, 'value='))) === 42, 'Price fields start blank and are all required');
    check(!str_contains($html, '<option>contact.') && !str_contains($html, 'Set exact price'), 'Removed operations cannot be selected');
    $amounts = [];
    foreach (CommandCatalog::priceOperations() as $command => $_) {
        foreach ($command === 'domain.restore' ? [0] : range($command === 'domain.transfer' ? 0 : 1, 10) as $period) { $amounts[$command][$period] = (string) ($period * 10); }
    }
    $batch = ['zone' => $registry . ':com', 'scope' => 'global', 'currency' => 'EUR', 'amounts' => $amounts];
    check(request('POST', ['action' => 'price.batch'] + $batch)->getStatusCode() === 403, 'Batch writes require CSRF');
    check(request('POST', ['action' => 'price.batch', 'csrf' => $_SESSION['csrf']] + $batch)->getStatusCode() === 303, 'Price table saves through the panel');
    check((int) $pdo->query('SELECT COUNT(*) FROM registry_prices')->fetchColumn() === 42, 'All 42 rows saved');
    $html = (string) request('GET')->getBody();
    check(substr_count($html, 'data-price-edit=') === 42 && substr_count($html, 'value="price.delete"') === 42 && str_contains($html, '<dialog id="price-editor"'), 'Each supported row has edit modal and delete controls');
    $bad = $batch; $bad['amounts']['domain.restore'][0] = '';
    $bad['amounts']['domain.create'][1] = '999';
    denied('required_price', fn () => action('price.batch', $bad));
    check($pdo->query("SELECT amount FROM registry_prices WHERE command = 'domain.create' AND period = 1")->fetchColumn() === '10.0000', 'Incomplete table rolls back earlier updates');
    $bad = $batch; unset($bad['amounts']['domain.renew'][10]);
    denied('required_price', fn () => action('price.batch', $bad));
    $bad = $batch; $bad['amounts']['contact.create'] = [0 => '0'];
    denied('invalid_price_table', fn () => action('price.batch', $bad));
    $edit = ['registry_id' => $registry, 'scope' => 'global', 'tld' => 'com', 'command' => 'domain.create', 'period' => '1', 'amount' => '12', 'currency' => 'EUR'];
    check(request('POST', ['action' => 'price.set', 'csrf' => $_SESSION['csrf']] + $edit)->getStatusCode() === 303, 'Modal updates an exact price');
    check($pdo->query("SELECT amount FROM registry_prices WHERE command = 'domain.create' AND period = 1")->fetchColumn() === '12.0000', 'Modal amount persists');
    foreach (CommandCatalog::all() as $entry) {
        if (CommandCatalog::requiresPrice($entry['command'])) { continue; }
        denied('unsupported_price_command', fn () => action('price.set', array_replace($edit, ['command' => $entry['command']])));
        denied('unsupported_price_command', fn () => Admin::savePrice($pdo, $registry, CommandCatalog::GLOBAL_PRICE_UUID, $entry['command'], '', 0, '0', 'EUR'));
    }
    denied('unknown_tld', fn () => Admin::savePrice($pdo, $registry, CommandCatalog::GLOBAL_PRICE_UUID, 'domain.create', 'net', 1, '1', 'EUR'));
    $override = array_replace($edit, ['scope' => $reseller, 'amount' => '1']); action('price.set', $override);
    $job = Ledger::createJob($pdo, $reseller, $registry, 'domain.create', Util::uuid(), ['domainname' => 'before.com', 'period' => 1, 'authInfoPw' => 'secret']);
    check(Ledger::fetchJob($pdo, $job['job_id'])['price'] === '1.0000', 'Override used before deletion');
    action('price.delete', $override);
    $next = Ledger::createJob($pdo, $reseller, $registry, 'domain.create', Util::uuid(), ['domainname' => 'fallback.com', 'period' => 1, 'authInfoPw' => 'secret']);
    check(Ledger::fetchJob($pdo, $next['job_id'])['price'] === '12.0000', 'Deleting override behaves as absent and exposes global price');
    check(request('POST', ['action' => 'price.delete', 'csrf' => $_SESSION['csrf']] + $edit)->getStatusCode() === 303, 'Row delete works through panel');
    denied('price_not_configured', fn () => Ledger::createJob($pdo, $reseller, $registry, 'domain.create', Util::uuid(), ['domainname' => 'missing.com', 'period' => 1, 'authInfoPw' => 'secret']));
    check(Ledger::fetchJob($pdo, $job['job_id'])['price'] === '1.0000', 'Deleting price never rewrites existing job amounts');
    $pdo->prepare("INSERT INTO registry_prices (registry_id, command, amount, currency) VALUES (?, 'domain.check', 100, 'EUR')")->execute([$registry]);
    $html = (string) request('GET')->getBody();
    check(str_contains($html, 'Ignored') && substr_count($html, 'value="price.delete"') === 42 && substr_count($html, 'data-price-edit=') === 41, 'Legacy unsupported rows can only be deleted');
    action('price.delete', array_replace($edit, ['command' => 'domain.check', 'tld' => '', 'period' => '0']));
    PanelSession::login(Auth::login($pdo, 'user-1', $password, '127.0.0.1'));
    check(request('POST', ['action' => 'price.batch', 'csrf' => $_SESSION['csrf']] + $batch)->getStatusCode() === 403, 'Resellers cannot write price tables');
    echo "PASS: $checks price-management assertions\n";
} finally {
    $pdo = null; $admin->exec("DROP DATABASE `$db`"); session_destroy();
    foreach (glob($sessionRoot . '/var/panel-sessions/*') ?: [] as $file) { unlink($file); }
    @rmdir($sessionRoot . '/var/panel-sessions'); @rmdir($sessionRoot . '/var'); @rmdir($sessionRoot);
}
