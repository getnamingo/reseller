<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Namingo\ResellerApi\{Admin, ApiException, Auth, CommandCatalog, Crypto, Ledger, PanelApi, PanelController, PanelData, PanelSession, Util};
use Nyholm\Psr7\{Response, ServerRequest};
use Slim\Views\Twig;

require dirname(__DIR__) . '/vendor/autoload.php';
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true];
$admin = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$db = 'reseller_price_groups_' . bin2hex(random_bytes(6));
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
function request(string $method, array $input = [], string $path = '/admin/prices'): Response {
    global $panel;
    return $panel->handle((new ServerRequest($method, 'http://localhost' . $path, [], '', '1.1', ['REMOTE_ADDR' => '127.0.0.1']))->withParsedBody($input), new Response());
}

function group(string $scope, string $tld, string $currency = 'EUR'): array {
    global $pdo, $registry;
    foreach (PanelData::adminPriceGroups($pdo, 1000, 0) as $group) {
        if ($group['registry_id'] === $registry && $group['scope'] === $scope && $group['tld'] === $tld && $group['currency'] === $currency) { return $group; }
    }
    throw new RuntimeException('Missing test price group');
}
function identity(array $group): array { return array_intersect_key($group, array_flip(['registry_id', 'scope', 'tld', 'currency', 'version'])); }
function value(string $sql, array $params): mixed { global $pdo; $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
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

    $pdo->prepare("INSERT INTO registry_tlds (registry_id, tld) VALUES (?, 'net')")->execute([$registry]);
    $amounts = [];
    foreach (CommandCatalog::priceOperations() as $command => $_) {
        foreach ($command === 'domain.restore' ? [0] : range(1, 10) as $period) { $amounts[$command][$period] = '10'; }
    }
    $batch = ['zone' => $registry . ':com', 'scope' => 'global', 'currency' => 'EUR', 'amounts' => $amounts];
    action('price.batch', $batch);
    action('price.batch', array_replace($batch, ['scope' => $reseller]));
    action('price.batch', array_replace($batch, ['zone' => $registry . ':net']));
    Admin::savePrice($pdo, $registry, CommandCatalog::GLOBAL_PRICE_UUID, 'domain.restore', '', 0, '7', 'EUR');
    $pdo->prepare("INSERT INTO registry_prices (registry_id, command, tld, period, amount, currency) VALUES (?, 'domain.create_dnssec', 'com', 1, 999, 'EUR'), (?, 'hello', 'com', 0, 999, 'USD')")->execute([$registry, $registry]);
    $group = group('global', 'com');
    check((int) $group['price_count'] === 32 && $group['editable'], '31 supported prices and legacy row appear in one TLD group');
    check($group['prices']['domain.create'][1] === '10.0000' && !isset($group['prices']['domain.create_dnssec']), 'Editor payload prefills supported prices only');
    $html = (string) request('GET')->getBody();
    preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $table);
    check(substr_count($table[1], '<tr>') === 5 && substr_count($html, 'value="price.group_delete"') === 5, 'Table groups by registry, TLD, scope and currency');
    check(substr_count($html, 'data-price-edit=') === 4, 'Legacy-only group is deletion-only');
    preg_match('/<dialog[^>]+id="price-editor"(.*?)<\/dialog>/s', $html, $editor);
    check(substr_count($editor[1], 'data-price-amount') === 31 && !str_contains($editor[1], '<select') && !str_contains($editor[1], 'name="zone"'), 'Edit uses full table without metadata selectors');
    foreach (['scope', 'tld', 'currency'] as $key) { check(str_contains($editor[1], 'type="hidden" name="' . $key . '"'), 'Edit fixes ' . $key . ' as hidden group identity'); }
    $updated = $amounts;
    foreach ($updated as &$values) { foreach ($values as $period => &$value) { $value = (string) (20 + $period); } unset($value); } unset($values);
    check(request('POST', ['action' => 'price.group_update', 'amounts' => $updated] + identity($group))->getStatusCode() === 403, 'Grouped update requires CSRF');
    check(request('POST', ['action' => 'price.group_update', 'csrf' => $_SESSION['csrf'], 'amounts' => $updated] + identity($group))->getStatusCode() === 303, 'Full group update saves through panel');
    $current = group('global', 'com');
    check($current['prices']['domain.create'][1] === '21.0000' && $current['prices']['domain.renew'][10] === '30.0000' && $current['prices']['domain.restore'][0] === '20.0000', 'One save updates all operations and terms');
    check(group($reseller, 'com')['prices']['domain.create'][1] === '10.0000' && group('global', 'net')['prices']['domain.create'][1] === '10.0000', 'Group update leaves other scopes and TLDs unchanged');
    denied('price_group_changed', fn () => action('price.group_delete', identity($group)));
    denied('price_group_changed', fn () => action('price.group_update', identity($group) + ['amounts' => $amounts]));
    $bad = $updated; $bad['domain.create'][1] = '888'; $bad['domain.restore'][0] = '';
    denied('required_price', fn () => action('price.group_update', identity($current) + ['amounts' => $bad]));
    check(group('global', 'com')['prices']['domain.create'][1] === '21.0000', 'Invalid bulk update rolls back earlier rows');
    $wide = group('global', '');
    check(request('POST', ['action' => 'price.group_update', 'csrf' => $_SESSION['csrf'], 'amounts' => ['domain.restore' => [0 => '9']]] + identity($wide))->getStatusCode() === 303, 'Registry-wide legacy restore remains editable without term fields');
    check(group('global', '')['prices']['domain.restore'][0] === '9.0000', 'Registry-wide restore amount updated');
    $legacy = group('global', 'com', 'USD');
    denied('unsupported_price_group', fn () => action('price.group_update', identity($legacy) + ['amounts' => $amounts]));
    // A mixed-currency term must never be silently overwritten by a grouped save.
    $pdo->prepare("UPDATE registry_prices SET currency='USD' WHERE registry_id=? AND reseller_id=? AND tld='com' AND command='domain.renew' AND period=1")->execute([$registry, CommandCatalog::GLOBAL_PRICE_UUID]);
    denied('mixed_price_currency', fn () => action('price.group_update', identity(group('global', 'com')) + ['amounts' => $updated]));
    check(group('global', 'com', 'USD')['prices']['domain.renew'][1] === '21.0000', 'Mixed-currency conflict preserves existing prices');
    action('price.batch', array_replace($batch, ['amounts' => $updated]));
    $override = group($reseller, 'com');
    check(request('POST', ['action' => 'price.group_delete', 'csrf' => $_SESSION['csrf']] + identity($override))->getStatusCode() === 303, 'Delete removes entire override group');
    check((int) value('SELECT COUNT(*) FROM registry_prices WHERE reseller_id=? AND tld=?', [$reseller, 'com']) === 0, 'Every operation/year in the selected override is removed');
    $job = Ledger::createJob($pdo, $reseller, $registry, 'domain.create', Util::uuid(), ['domainname' => 'fallback-group.com', 'period' => 1, 'authInfoPw' => 'secret']);
    check(Ledger::fetchJob($pdo, $job['job_id'])['price'] === '21.0000', 'Group deletion exposes normal global fallback');
    $current = group('global', 'com');
    check(request('POST', ['action' => 'price.group_delete'] + identity($current))->getStatusCode() === 403, 'Group deletion requires CSRF');
    action('price.group_delete', identity($current));
    check((int) value("SELECT COUNT(*) FROM registry_prices WHERE registry_id=? AND tld='com' AND currency='EUR'", [$registry]) === 0, 'Delete all includes ignored legacy prices in the group');
    check((int) value("SELECT COUNT(*) FROM registry_prices WHERE registry_id=? AND tld='com' AND currency='USD'", [$registry]) === 1 && (int) value("SELECT COUNT(*) FROM registry_prices WHERE registry_id=? AND tld='net'", [$registry]) === 31, 'Delete leaves other currency and TLD groups intact');
    denied('price_not_configured', fn () => Ledger::createJob($pdo, $reseller, $registry, 'domain.create', Util::uuid(), ['domainname' => 'missing-group.com', 'period' => 1, 'authInfoPw' => 'secret']));
    check(Ledger::fetchJob($pdo, $job['job_id'])['price'] === '21.0000', 'Existing jobs retain their recorded amounts');
    denied('price_group_changed', fn () => action('price.group_delete', identity($current)));
    PanelSession::login(Auth::login($pdo, 'user-1', $password, '127.0.0.1'));
    check(request('POST', ['action' => 'price.group_delete', 'csrf' => $_SESSION['csrf']] + identity($legacy))->getStatusCode() === 403, 'Resellers cannot delete admin groups');
    echo "PASS: $checks grouped price assertions\n";
} finally {
    $pdo = null; $admin->exec("DROP DATABASE `$db`"); session_destroy();
    foreach (glob($sessionRoot . '/var/panel-sessions/*') ?: [] as $file) { unlink($file); }
    @rmdir($sessionRoot . '/var/panel-sessions'); @rmdir($sessionRoot . '/var'); @rmdir($sessionRoot);
}
