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

// Load the real 0.8.9 contracts; no production FOSSBilling config or network access is used.
$root = rtrim((string) getenv('FOSSBILLING_ROOT'), '/');
if (!is_file($root . '/library/Registrar/AdapterAbstract.php') && is_dir($root . '/src/library')) { $root .= '/src'; }
if ($root === '' || !is_file($root . '/library/Registrar/AdapterAbstract.php')) {
    fwrite(STDERR, "Set FOSSBILLING_ROOT to the src directory of a FOSSBilling 0.8.9 checkout.\n");
    exit(2);
}
$configFile = tempnam(sys_get_temp_dir(), 'namingo-fossbilling-test-');
$testConfig = '<?php return ["info" => ["salt" => "disposable-test-installation-salt"], "debug_and_monitoring" => ["log_stacktrace" => false]];';
file_put_contents($configFile, $testConfig);
register_shutdown_function(static fn () => unlink($configFile));
define('PATH_CONFIG', $configFile);
define('DEBUG', false);
foreach (['FOSSBilling/Config.php', 'FOSSBilling/Exception.php', 'FOSSBilling/InjectionAwareInterface.php',
    'Box/Crypt.php', 'Registrar/Exception.php', 'Registrar/Domain/Contact.php', 'Registrar/Domain.php', 'Registrar/AdapterAbstract.php'] as $file) {
    require $root . '/library/' . $file;
}
// The RedBean order model is the only billing-model double; adapters need its persisted ID.
class Model_ClientOrder { public function __construct(public int $id, public string $expires_at = '2027-12-31 00:00:00') {} }

require dirname(__DIR__) . '/library/Registrar/Adapter/Namingo.php';
require dirname(__DIR__, 3) . '/src/Core.php';
require dirname(__DIR__, 3) . '/src/ProfileParams.php';
require __DIR__ . '/fixtures.php';

use Namingo\FossBilling\ApiClient;
use Namingo\FossBilling\Registrar;
use Namingo\FossBilling\Store;

final class TestAdapter extends Registrar_Adapter_Namingo
{
    public function __construct(public Fixture $fixture, public Store $store, array $extra = [], ?int $orderId = 42)
    {
        parent::__construct(array_replace(['api_url' => 'https://api.example.net', 'api_username' => 'reseller',
            'api_password' => 'password', 'default_registry' => Fixture::UUID], $extra));
        if ($orderId !== null) { $this->setOrder(new Model_ClientOrder($orderId, $extra['billing_expiry'] ?? '2027-12-31 00:00:00')); }
    }
    protected function createStore(): Store { return $this->store; }
    protected function createApi(Store $store): ApiClient { return new ApiClient($this->apiConfiguration(), $this->fixture, $store); }
    public function mapped(Registrar_Domain $domain): array { return $this->parameters($domain); }
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
    try { $call(); }
    catch (Throwable $error) {
        check($error instanceof Registrar_Exception || $error instanceof RuntimeException, 'Expected framework or runtime error');
        check(str_contains($error->getMessage(), $contains), 'Expected ' . $contains . '; got ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $contains);
}
function domain(): Registrar_Domain
{
    $contact = (new Registrar_Domain_Contact())->setFirstName('Iliya')->setLastName('Test')
        ->setCompany('Company')->setEmail('person@example.net')->setAddress1('Street 1')->setAddress2('')
        ->setCity('Sofia')->setState('')->setZip('1000')->setCountry('BG')->setTelCc('+359')->setTel('888 123-456');
    return (new Registrar_Domain())->setSld('example')->setTld('.com')->setRegistrationPeriod(1)
        ->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setEpp('Transfer1!')
        ->setExpirationTime(strtotime('2027-12-31T00:00:00Z'))->setPrivacyEnabled(false)
        ->setContactRegistrar($contact)->setContactAdmin($contact)->setContactTech($contact)->setContactBilling($contact);
}
function posts(Fixture $fixture, string $command): array
{
    return array_values(array_filter($fixture->posts, fn ($row) => $row['command'] === $command));
}

check(!(new ReflectionClass(Registrar_Adapter_Namingo::class))->isAbstract(), 'Implements every real FOSSBilling 0.8.9 abstract method');
check(Registrar_Adapter_Namingo::getConfig()['form']['api_password'][1]['secret'] === true, 'Secret credential schema');
check(in_array('api_password', Registrar_Adapter_Namingo::getSecretFields(), true), 'Credential masking fallback');
fails(fn () => new TestAdapter(new Fixture(), new MemoryStore(), ['api_url' => 'http://example.net']), 'HTTPS');
$adapter = new TestAdapter(new Fixture(), new MemoryStore());
check($adapter->mapped(domain())['domainid'] === 42, 'Uses persisted order ID');
check($adapter->mapped(domain())['fullphonenumber'] === '+359.888123456', 'Formats FOSSBilling contact telephone');
check($adapter->mapped(domain())['expirydate'] === '2027-12-31', 'Uses stored billing expiry date');
check($adapter->mapped(domain())['countrycode'] === 'BG', 'Maps registrant country');
check($adapter->mapped(domain())['admincountry'] === 'BG', 'Maps administrative country');
check(Registrar::registry('name.org.ua', ['RegistryMap' => '{"ua":"22222222-2222-4222-8222-222222222222","org.ua":"11111111-1111-4111-8111-111111111111"}']) === Fixture::UUID, 'Longest suffix routes multi-label TLD');
fails(fn () => Registrar::registry('name.net', []), 'Configure a registry');
fails(fn () => Registrar::hostname('name.com</domain:name>'), 'ASCII');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->timeout = 'domain.create';
$adapter = new TestAdapter($fixture, $store);
fails(fn () => $adapter->registerDomain(domain()), 'Simulated response timeout');
check(count(posts($fixture, 'contact.create')) === 4, 'Creates dedicated owned contacts before registration');
check((new TestAdapter($fixture, $store))->registerDomain(domain()), 'Registration timeout retry succeeds');
$requests = posts($fixture, 'domain.create');
check(count($requests) === 2 && $requests[0]['key'] === $requests[1]['key'], 'Registration retries keep one idempotency key');
check($requests[0]['params'] === $requests[1]['params'], 'Registration retries preserve auth code and exact payload');
check(count($fixture->keys) === 5, 'Only one registration and four contact jobs');
$fixture->failRead = true;
fails(fn () => (new TestAdapter($fixture, $store))->registerDomain(domain()), 'job read outage');
check((new TestAdapter($fixture, $store))->registerDomain(domain()), 'Read outage does not lose registration state');
check(count(posts($fixture, 'domain.create')) === 2, 'Known registration job uses GET instead of another POST');
check($fixture->logins === 1, 'Caches bearer token across adapter instances');
$fixture->denyToken = true;
fails(fn () => (new TestAdapter($fixture, $store))->getEpp(domain()), 'HTTP 401');
check((new TestAdapter($fixture, $store))->getEpp(domain()) === 'Secret1!', 'Expired/revoked token refreshes on retry');
check($fixture->logins === 2, 'Only one replacement login');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->timeout = 'domain.renew';
fails(fn () => (new TestAdapter($fixture, $store))->renewDomain(domain()), 'Simulated');
check((new TestAdapter($fixture, $store))->renewDomain(domain()), 'Renewal resumes after timeout');
check(count($fixture->keys) === 1, 'One paid renewal job after timeout');
check((new TestAdapter($fixture, $store))->renewDomain(domain()->setExpirationTime(strtotime('2028-12-31T00:00:00Z'))), 'Registry sync during a retry does not change billing renewal identity');
check(count($fixture->keys) === 1, 'Registry expiry advancing cannot cause duplicate paid renewal');
fails(fn () => (new TestAdapter($fixture, $store))->renewDomain(domain()->setRegistrationPeriod(2)), 'different input');
check((new TestAdapter($fixture, $store, ['billing_expiry' => '2028-12-31 00:00:00']))->renewDomain(domain()->setExpirationTime(strtotime('2028-12-31T00:00:00Z'))), 'Next billing cycle identifies next renewal');
check(count($fixture->keys) === 2, 'Next renewal has a distinct key');
fails(fn () => (new TestAdapter($fixture, $store))->renewDomain(domain()->setExpirationTime(null)), 'valid FOSSBilling expiry');
fails(fn () => (new TestAdapter($fixture, $store, ['billing_expiry' => '']))->renewDomain(domain()), 'order expiry date');
fails(fn () => (new TestAdapter(new Fixture(), new MemoryStore(), [], null))->registerDomain(domain()), 'order ID');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->timeout = 'domain.renew';
fails(fn () => (new TestAdapter($fixture, $store))->renewDomain(domain()), 'Simulated');
$fixture->rejectSubmission = 403;
fails(fn () => (new TestAdapter($fixture, $store))->renewDomain(domain()), 'HTTP 403');
$id = array_key_first($fixture->jobs); $fixture->jobs[$id]['status'] = 'unknown';
fails(fn () => (new TestAdapter($fixture, $store))->renewDomain(domain()), 'unknown');
fails(fn () => (new TestAdapter($fixture, $store))->renewDomain(domain()), 'unknown');
check(count($fixture->keys) === 1, 'Unknown result and rejected retry do not generate a new request');
$fixture->jobs[$id]['status'] = 'succeeded';
check((new TestAdapter($fixture, $store))->renewDomain(domain()), 'Reconciled original renewal can finish');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->reject = 'domain.renew';
fails(fn () => (new TestAdapter($fixture, $store))->renewDomain(domain()), 'rejected');
$fixture->reject = null;
check((new TestAdapter($fixture, $store))->renewDomain(domain()), 'Known rejected job allows a new attempt');
check(count($fixture->keys) === 2, 'Fresh attempt after confirmed rejection');

$fixture = new Fixture(); $store = new MemoryStore(); $fixture->timeout = 'domain.update_ns';
fails(fn () => (new TestAdapter($fixture, $store))->modifyNs(domain()), 'Simulated');
fails(fn () => (new TestAdapter($fixture, $store))->modifyNs(domain()->setNs1('changed.example.net')), 'different input');
check((new TestAdapter($fixture, $store))->modifyNs(domain()), 'Original nameserver action resumes');
check((new TestAdapter($fixture, $store))->modifyNs(domain()->setNs1('changed.example.net')), 'Later nameserver changes remain possible');

$fixture = new Fixture(); $store = new MemoryStore();
fails(fn () => (new TestAdapter($fixture, $store))->transferDomain(domain()), 'transfer is pending');
fails(fn () => (new TestAdapter($fixture, $store))->transferDomain(domain()), 'transfer is pending');
check(count(array_filter(posts($fixture, 'domain.transfer'), fn ($row) => $row['params']['op'] === 'request')) === 1, 'Pending activation retry does not submit a second transfer');
$fixture->transferStatus = 'serverApproved';
check((new TestAdapter($fixture, $store))->transferDomain(domain()), 'Confirmed transfer approval permits activation');
$fixture->transferStatus = 'clientRejected';
fails(fn () => (new TestAdapter($fixture, $store))->transferDomain(domain()), 'clientRejected');

$fixture = new Fixture(); $adapter = new TestAdapter($fixture, new MemoryStore());
check($adapter->isDomainAvailable(domain()), 'Availability is from API result');
fails(fn () => $adapter->isDomaincanBeTransferred(domain()), 'available for registration');
$fixture->available = false;
check($adapter->isDomaincanBeTransferred(domain()), 'Registered domain passes transfer existence precheck');
$info = $adapter->getDomainDetails(domain());
check($info instanceof Registrar_Domain && $info->getExpirationTime() === strtotime('2027-12-31T00:00:00Z'), 'Returns real FOSSBilling domain object and expiry');
check($info->getContactRegistrar()->getFirstName() === 'Iliya', 'Maps registry contact into real FOSSBilling contact');
check($info->getContactRegistrar()->getTelCc() === '359', 'Maps telephone calling code');
check($info->getNs1() === 'ns1.example.net' && $info->getNs4() === '', 'Maps four FOSSBilling nameserver fields');
check($adapter->modifyContact(domain()), 'Contact update validates server parameters');
check(count(posts($fixture, 'contact.update')) === 2, 'Updates only roles present at registry');
check($adapter->lock(domain()), 'Lock sends API update');
$fixture->info['status'] = ['clientTransferProhibited'];
$before = count(posts($fixture, 'domain.update_status'));
check($adapter->lock(domain()), 'Already locked is successful');
check(count(posts($fixture, 'domain.update_status')) === $before, 'Already locked skips redundant command');
check($adapter->unlock(domain()), 'Unlock sends API update');
$fixture->denyContacts = true;
check($adapter->getDomainDetails(domain())->getContactRegistrar()->getEmail() === 'person@example.net', 'Unowned transferred contacts preserve billing data');

$fixture = new Fixture(); $fixture->profile = 'VRSN'; $adapter = new TestAdapter($fixture, new MemoryStore());
check($adapter->registerDomain(domain()), 'Contactless profile registration');
check(posts($fixture, 'contact.create') === [], 'VRSN has no unsupported contact creates');
check($adapter->getDomainDetails(domain())->getContactRegistrar()->getFirstName() === 'Iliya', 'Contactless details keep local billing contact');
fails(fn () => $adapter->modifyContact(domain()), 'no EPP contact');
$fixture = new Fixture(); $fixture->profile = 'UA';
check((new TestAdapter($fixture, new MemoryStore()))->registerDomain(domain()), 'UA native parameter contract');
$fixture = new Fixture(); $fixture->profile = 'EE';
fails(fn () => (new TestAdapter($fixture, new MemoryStore()))->registerDomain(domain()), 'profile adapter');
check($fixture->posts === [], 'Unsupported profile fails before writes');

$fixture = new Fixture(); $adapter = new TestAdapter($fixture, new MemoryStore());
fails(fn () => $adapter->deleteDomain(domain()), 'deletion on cancellation is disabled');
check($fixture->posts === [], 'Cancellation does not delete at registry by default');
check((new TestAdapter($fixture, new MemoryStore(), ['allow_delete' => '1']))->deleteDomain(domain()), 'Explicitly enabled registry deletion');
fails(fn () => $adapter->enablePrivacyProtection(domain()), 'does not provide WHOIS');
fails(fn () => $adapter->disablePrivacyProtection(domain()), 'does not provide WHOIS');
fails(fn () => $adapter->registerDomain(domain()->setPrivacyEnabled(true)), 'not supported');
$adapter->enableTestMode();
$before = count($fixture->posts);
fails(fn () => $adapter->registerDomain(domain()), 'no simulated test mode');
check(count($fixture->posts) === $before, 'Test mode cannot submit production writes');

// Exercise the production store with actual FOSSBilling encryption and MySQL locks/autocommit.
if (getenv('FOSSBILLING_TEST_MYSQL_DSN')) {
    $connect = fn () => new PDO(getenv('FOSSBILLING_TEST_MYSQL_DSN'), getenv('FOSSBILLING_TEST_MYSQL_USER') ?: 'root',
        getenv('FOSSBILLING_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo = $connect();
    $pdo->exec('DROP TABLE IF EXISTS namingoregistrar_request');
    try {
        $store = new Store($pdo, new Box_Crypt());
        $key = hash('sha256', 'pdo-test');
        $store->put($key, ['secret' => 'contact-and-auth-code']);
        check($store->get($key) === ['secret' => 'contact-and-auth-code'], 'PDO round trip uses real FOSSBilling encryption');
        $cipher = $pdo->query('SELECT payload FROM namingoregistrar_request')->fetchColumn();
        check(str_starts_with($cipher, Box_Crypt::CURRENT_FORMAT_PREFIX) && !str_contains($cipher, 'contact-and-auth-code'), 'Only encrypted payload stored');
        $store->put($key, ['job' => 'updated']);
        check($store->get($key) === ['job' => 'updated'], 'PDO upsert');
        $second = new Store($connect(), new Box_Crypt());
        $store->lock($key);
        fails(fn () => $second->lock($key), 'Another Namingo action');
        $store->unlock($key); $second->lock($key); $second->unlock($key);
        $billingPdo = $connect(); $billingPdo->beginTransaction();
        $store->put($key, ['job' => 'survives-billing-rollback']);
        $billingPdo->rollBack();
        check($second->get($key)['job'] === 'survives-billing-rollback', 'Billing rollback cannot erase committed request');
        $pdo->beginTransaction();
        fails(fn () => new Store($pdo, new Box_Crypt()), 'separate autocommit');
        $pdo->rollBack();
        $store->remove($key); check($store->get($key) === null, 'PDO deletion');
    } finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } $pdo->exec('DROP TABLE namingoregistrar_request'); }
    echo "MySQL store, locking, transaction isolation and FOSSBilling encryption checks passed.\n";
}
echo "FOSSBilling 0.8.9 registrar: {$assertions} assertions passed.\n";
