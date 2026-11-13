<?php

declare(strict_types=1);

use Namingo\ResellerApi\ApiException;
use Namingo\ResellerApi\CommandCatalog;
use Namingo\ResellerApi\Ledger;
use Namingo\ResellerApi\Ownership;
use Namingo\ResellerApi\Util;

require dirname(__DIR__) . '/vendor/autoload.php';

// Creates and drops only a uniquely named test database. No registry/network commands are sent.
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$user = getenv('TEST_MYSQL_USER') ?: 'root';
$password = getenv('TEST_MYSQL_PASSWORD') ?: '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true];
if (($argv[1] ?? '') === 'race') {
    $pdo = new PDO($argv[2], $user, $password, $options);
    $_ENV['APP_MASTER_KEY'] = getenv('APP_MASTER_KEY');
    echo "ready\n";
    fgets(STDIN);
    try {
        Ledger::createJob($pdo, $argv[4], $argv[3], 'domain.create', Util::uuid(), ['domainname' => 'race.com', 'period' => 1, 'authInfoPw' => 'test-secret']);
        echo 'queued';
    } catch (ApiException $e) {
        echo $e->errorCode;
    }
    exit;
}
$admin = new PDO($dsn, $user, $password, $options);
$db = 'reseller_test_' . bin2hex(random_bytes(6));
$admin->exec("CREATE DATABASE `$db`");
$pdo = new PDO($dsn . ';dbname=' . $db, $user, $password, $options);
$_ENV['APP_MASTER_KEY'] = 'base64:' . base64_encode(random_bytes(32));
putenv('APP_MASTER_KEY=' . $_ENV['APP_MASTER_KEY']);
$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    ++$checks;
}
function denied(string $code, callable $call): void
{
    try {
        $call();
    } catch (ApiException $e) {
        check($e->errorCode === $code, "Expected {$code}, got {$e->errorCode}");
        return;
    }
    throw new RuntimeException("Expected {$code}");
}
function price(string $command, string $amount = '0', string $tld = '', int $years = 0, ?string $reseller = null, string $currency = 'EUR'): void
{
    global $pdo, $registry;
    $s = $pdo->prepare('INSERT INTO registry_prices (registry_id, reseller_id, command, tld, period, amount, currency) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), currency = VALUES(currency)');
    $s->execute([$registry, $reseller ?? CommandCatalog::GLOBAL_PRICE_UUID, $command, $tld, $years, $amount, $currency]);
}
function own(string $type, string $name, string $reseller): void
{
    global $pdo, $registry;
    $pdo->prepare('INSERT INTO object_ownership (registry_id, object_type, object_name, reseller_id, active) VALUES (?, ?, ?, ?, 1)')
        ->execute([$registry, $type, $name, $reseller]);
}
function job(string $reseller, string $command, array $params, ?string $key = null): array
{
    global $pdo, $registry;
    if ($command === 'domain.create') { $params += ['authInfoPw' => 'test-secret']; }
    return Ledger::createJob($pdo, $reseller, $registry, $command, $key ?? Util::uuid(), $params);
}
function finish(array $job, string $status = 'succeeded', array $result = []): void
{
    global $pdo;
    Ledger::completeJob($pdo, $job['job_id'], $status, $result, $status === 'succeeded' ? 1000 : null);
}
function countRows(string $table): int
{
    global $pdo;
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

try {
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $a = Util::uuid(); $b = Util::uuid(); $registry = Util::uuid();
    foreach ([$a, $b] as $i => $id) {
        $pdo->prepare('INSERT INTO resellers (id, username, password_hash, currency) VALUES (?, ?, ?, ?)')->execute([$id, 'test' . $i, 'unused', 'EUR']);
        $pdo->prepare("INSERT INTO wallets (reseller_id, currency, available_balance) VALUES (?, 'EUR', 1000)")->execute([$id]);
    }
    $pdo->prepare("INSERT INTO registries (id, name, profile, config_nonce, config_cipher) VALUES (?, 'shared', 'generic', ?, '')")->execute([$registry, str_repeat('x', 24)]);
    foreach ([$a, $b] as $id) {
        $pdo->prepare('INSERT INTO reseller_registries (reseller_id, registry_id) VALUES (?, ?)')->execute([$id, $registry]);
    }
    foreach (['com', 'net', 'org', 'uk', 'co.uk', 'free'] as $zone) {
        $pdo->prepare('INSERT INTO registry_tlds (registry_id, tld) VALUES (?, ?)')->execute([$registry, $zone]);
        foreach ([$a, $b] as $id) {
            $pdo->prepare('INSERT INTO reseller_tlds (reseller_id, registry_id, tld) VALUES (?, ?, ?)')->execute([$id, $registry, $zone]);
        }
    }
    denied('price_not_configured', fn () => job($a, 'domain.create', ['domainname' => 'absent.com', 'period' => 1]));
    check(countRows('epp_jobs') === 0 && countRows('object_ownership') === 0, 'Missing price must roll back job and reservation');
    price('domain.create', '1'); // Legacy catch-all must never price a term operation.
    denied('price_not_configured', fn () => job($a, 'domain.create', ['domainname' => 'absent.com', 'period' => 1]));
    price('domain.create', '12', 'com', 1);
    price('domain.create', '21', 'com', 2);
    price('domain.create', '7', 'net', 1);
    price('domain.create', '10', 'com', 1, $a);
    foreach ([['one.com', 1, '10.0000'], ['two.com', 2, '21.0000'], ['one.net', 1, '7.0000']] as [$name, $years, $amount]) {
        $j = job($a, 'domain.create', ['domainname' => $name, 'period' => $years]);
        check(Ledger::fetchJob($pdo, $j['job_id'])['price'] === $amount, 'TLD/term/override price');
        finish($j);
    }
    price('domain.create', '8', 'uk', 1, $a);
    price('domain.create', '16', 'co.uk', 1);
    $j = job($a, 'domain.create', ['domainname' => 'example.co.uk', 'period' => 1]);
    check(Ledger::fetchJob($pdo, $j['job_id'])['price'] === '16.0000', 'Longest zone precedes reseller override');
    finish($j);
    denied('price_not_configured', fn () => job($a, 'domain.create', ['domainname' => 'no.org', 'period' => 1]));
    foreach ([0, -1, 11, 1.5, true, '01', '1e0', [], null] as $period) {
        denied('invalid_period', fn () => job($a, 'domain.create', ['domainname' => 'invalid.com', 'period' => $period]));
    }
    price('domain.create', '1', 'org', 1, null, 'USD');
    denied('currency_mismatch', fn () => job($a, 'domain.create', ['domainname' => 'wrong.org', 'period' => 1]));
    price('domain.create', '1001', 'org', 1);
    denied('insufficient_funds', fn () => job($a, 'domain.create', ['domainname' => 'poor.org', 'period' => 1]));

    foreach (['domain.info', 'domain.delete', 'domain.update_contact', 'domain.update_ns', 'domain.transfer',
        'contact.create', 'contact.info', 'contact.update', 'contact.update_authinfo', 'contact.delete',
        'host.create', 'host.update', 'host.info', 'host.delete', 'domain.check'] as $command) {
        price($command); // Explicit free prices.
    }
    own('contact', 'a-contact', $a); own('contact', 'b-contact', $b);
    own('host', 'ns.one.com', $a);
    foreach (['domain.info', 'domain.delete', 'domain.update_contact', 'domain.update_ns'] as $command) {
        denied('object_not_owned', fn () => job($b, $command, ['domainname' => 'ONE.com']));
    }
    foreach (['contact.info' => ['contact' => 'a-contact'], 'contact.update' => ['id' => 'a-contact'],
        'contact.update_authinfo' => ['contactid' => 'a-contact', 'authInfo' => 'secret'], 'contact.delete' => ['contact' => 'a-contact'],
        'host.info' => ['hostname' => 'NS.one.com'], 'host.update' => ['hostname' => 'ns.one.com'], 'host.delete' => ['hostname' => 'ns.one.com']] as $command => $params) {
        denied('object_not_owned', fn () => job($b, $command, $params));
    }
    denied('object_not_owned', fn () => job($b, 'domain.info', ['domainname' => 'untracked.com']));
    denied('object_not_owned', fn () => job($a, 'domain.update_contact', ['domainname' => 'one.com', 'new_contactid' => 'b-contact']));
    denied('object_not_owned', fn () => job($a, 'domain.create', ['domainname' => 'ref.com', 'period' => 1, 'contacts' => ['tech' => 'b-contact']]));
    denied('object_not_owned', fn () => job($b, 'host.create', ['hostname' => 'ns2.one.com']));
    $j = job($a, 'domain.update_contact', ['domainname' => 'one.com', 'contacttype' => 'registrant', 'new_contactid' => 'a-contact']); finish($j);
    $j = job($a, 'domain.update_ns', ['domainname' => 'one.com', 'ns1' => 'ns.shared.net']); finish($j);
    $j = job($b, 'domain.check', ['domains' => ['one.com']]); finish($j);
    check(Ledger::fetchJob($pdo, $j['job_id'])['price'] === '0.0000', 'Explicit free check');

    $key = Util::uuid(); $params = ['domainname' => 'pending.com', 'period' => 1];
    $j = job($a, 'domain.create', $params, $key);
    check(job($a, 'domain.create', $params, $key)['existing'], 'Idempotent retry');
    denied('idempotency_conflict', fn () => job($a, 'domain.create', ['domainname' => 'other.com', 'period' => 1], $key));
    denied('object_not_owned', fn () => job($b, 'domain.create', $params));
    denied('object_busy', fn () => job($a, 'domain.create', $params));
    denied('object_busy', fn () => job($a, 'domain.info', ['domainname' => 'pending.com']));
    finish($j, 'unknown');
    denied('object_busy', fn () => job($a, 'domain.info', ['domainname' => 'pending.com']));
    Ledger::resolveUnknown($pdo, $j['job_id'], 'charge');
    $j = job($a, 'domain.info', ['domainname' => 'pending.com']); finish($j);
    $j = job($a, 'domain.delete', ['domainname' => 'pending.com']); finish($j, 'rejected');
    $j = job($a, 'domain.info', ['domainname' => 'pending.com']); finish($j);
    $j = job($a, 'domain.delete', ['domainname' => 'pending.com']); finish($j);
    denied('object_not_owned', fn () => job($b, 'domain.create', $params));
    $j = job($a, 'domain.info', ['domainname' => 'pending.com']); finish($j, 'rejected', ['code' => 2303]);
    $j = job($b, 'domain.create', $params); finish($j);
    denied('object_not_owned', fn () => job($a, 'domain.info', ['domainname' => 'pending.com']));

    $j = job($a, 'domain.create', ['domainname' => 'failed.com', 'period' => 1]); finish($j, 'rejected');
    $j = job($b, 'domain.create', ['domainname' => 'failed.com', 'period' => 1]); finish($j, 'unknown');
    Ledger::resolveUnknown($pdo, $j['job_id'], 'release');
    $j = job($a, 'domain.create', ['domainname' => 'failed.com', 'period' => 1]); finish($j);

    denied('object_not_owned', fn () => job($b, 'domain.transfer', ['domainname' => 'one.com', 'op' => 'request', 'years' => 1, 'authInfoPw' => 'known']));
    denied('missing_authinfo', fn () => job($a, 'domain.transfer', ['domainname' => 'external.com', 'op' => 'request', 'years' => 1]));
    denied('price_not_configured', fn () => job($a, 'domain.transfer', ['domainname' => 'external.com', 'op' => 'request', 'years' => 1, 'authInfoPw' => 'secret']));
    price('domain.transfer', '9', 'com', 1);
    $j = job($a, 'domain.transfer', ['domainname' => 'external.com', 'op' => 'request', 'years' => 1, 'authInfoPw' => 'secret']);
    finish($j, 'unknown');
    denied('object_not_owned', fn () => job($b, 'domain.transfer', ['domainname' => 'external.com', 'op' => 'query']));
    Ledger::resolveUnknown($pdo, $j['job_id'], 'charge');
    $j = job($a, 'domain.transfer', ['domainname' => 'external.com', 'op' => 'approve']); finish($j);
    denied('object_not_owned', fn () => job($a, 'domain.info', ['domainname' => 'external.com']));

    $before = $pdo->query("SELECT available_balance, held_balance FROM wallets WHERE reseller_id = '$a'")->fetch();
    price('domain.renew', '18', 'com', 2);
    $renew = job($a, 'domain.renew', ['domainname' => 'one.com', 'regperiod' => '2']);
    check(Ledger::fetchJob($pdo, $renew['job_id'])['price'] === '18.0000', 'Renewal exact regperiod');
    finish($renew, 'unknown');
    $held = $pdo->query("SELECT held_balance FROM wallets WHERE reseller_id = '$a'")->fetchColumn();
    check(Util::compareMoney($held, (string) ((float) $before['held_balance'] + 18)) === 0, 'Unknown retains wallet hold');
    Ledger::resolveUnknown($pdo, $renew['job_id'], 'release');
    check($pdo->query("SELECT available_balance, held_balance FROM wallets WHERE reseller_id = '$a'")->fetch() === $before,
        'Rejected unknown restores exact wallet balances');
    price('domain.create', '0', 'free', 1);
    $free = job($a, 'domain.create', ['domainname' => 'explicit.free', 'period' => 1]);
    check(Ledger::fetchJob($pdo, $free['job_id'])['price'] === '0.0000', 'Explicit zero term price allowed');
    finish($free);

    // Recheck references after queueing: no stale queued request may use a deleted contact.
    $j = job($a, 'domain.update_contact', ['domainname' => 'one.com', 'contacttype' => 'registrant', 'new_contactid' => 'a-contact']);
    $delete = job($a, 'contact.delete', ['contact' => 'a-contact']); finish($delete);
    denied('object_not_owned', fn () => Ownership::authorize($pdo, $registry, $a, 'domain.update_contact',
        ['domainname' => 'one.com', 'contacttype' => 'registrant', 'new_contactid' => 'a-contact'], 'generic', $j['job_id']));
    finish($j, 'rejected');

    $pdo->prepare("UPDATE registries SET profile = 'FRED' WHERE id = ?")->execute([$registry]);
    own('contact', 'ns-tech', $a);
    $j = job($a, 'host.create', ['hostname' => 'ns.test.net', 'ipaddress' => '192.0.2.1',
        'hostname2' => 'ns2.test.net', 'ipaddress2' => '192.0.2.2', 'nsid' => 'nsset-a', 'nstech' => 'ns-tech']); finish($j);
    denied('object_not_owned', fn () => job($b, 'host.update', ['nsid' => 'nsset-a']));
    $j = job($a, 'host.update', ['nsid' => 'nsset-a', 'newhostname' => 'ns3.test.net', 'oldhostname' => 'ns.test.net',
        'newipaddress' => '192.0.2.3', 'nstech_new' => 'ns-tech', 'nstech_old' => 'ns-tech']); finish($j);
    $pdo->prepare("UPDATE registries SET profile = 'LT' WHERE id = ?")->execute([$registry]);
    $j = job($a, 'contact.create', ['type' => 'loc', 'firstname' => 'First', 'lastname' => 'Last', 'companyname' => '',
        'address1' => 'Street', 'city' => 'City', 'state' => '', 'postcode' => '1000', 'country' => 'LT',
        'fullphonenumber' => '+370.123456789', 'email' => 'test@example.com', 'orgcode' => '', 'role' => 'registrant']);
    finish($j, 'succeeded', ['id' => 'generated-lt']);
    $j = job($a, 'contact.info', ['contact' => 'generated-lt']); finish($j);
    denied('object_not_owned', fn () => job($b, 'contact.info', ['contact' => 'generated-lt']));

    $pdo->prepare("UPDATE registries SET profile = 'generic' WHERE id = ?")->execute([$registry]);
    $children = [];
    foreach ([$a, $b] as $id) {
        $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'race', $dsn . ';dbname=' . $db, $registry, $id],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        check(is_resource($process) && fgets($pipes[1]) === "ready\n", 'Race worker ready');
        $children[] = [$process, $pipes];
    }
    foreach ($children as [$process, $pipes]) {
        fwrite($pipes[0], "go\n"); fclose($pipes[0]);
    }
    $outcomes = [];
    foreach ($children as [$process, $pipes]) {
        $outcomes[] = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, 'Race worker failed: ' . $errors . end($outcomes));
    }
    check(count(array_filter($outcomes, fn ($value) => $value === 'queued')) === 1, 'Exactly one concurrent create wins');
    check(count(array_filter($outcomes, fn ($value) => in_array($value, ['object_not_owned', 'object_busy'], true))) === 1,
        'Other concurrent reseller is refused');

    // Verify the upgrade preserves legacy prices but gives them no term fallback.
    $pdo->exec('DROP TABLE object_ownership');
    $pdo->exec('DELETE FROM registry_prices');
    $pdo->exec('ALTER TABLE registry_prices DROP PRIMARY KEY, DROP COLUMN tld, DROP COLUMN period, ADD PRIMARY KEY (registry_id, reseller_id, command)');
    $pdo->exec("DELETE FROM schema_migrations WHERE version = '002_ownership_pricing'");
    $pdo->prepare("INSERT INTO registry_prices (registry_id, command, amount, currency) VALUES (?, 'domain.create', 12, 'EUR')")->execute([$registry]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/002_ownership_pricing.sql'));
    check((int) $pdo->query('SELECT period FROM registry_prices')->fetchColumn() === 0, 'Legacy price preserved without term');
    denied('price_not_configured', fn () => job($a, 'domain.create', ['domainname' => 'migrated.com', 'period' => 1]));
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    check(countRows('schema_migrations') === 5, 'Fresh schema can be reapplied after migration');
    echo "PASS: {$checks} assertions\n";
} finally {
    $pdo = null;
    $admin->exec("DROP DATABASE `$db`");
}
