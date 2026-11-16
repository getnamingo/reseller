<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Namingo\ResellerApi\{Auth, Contacts, Crypto, Ledger, Ownership, PanelApi, PanelController, PanelSession, Util};
use Nyholm\Psr7\{Response, ServerRequest};
use Slim\Views\Twig;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Contacts.php';

// Complete a worker job precisely between the pending refresh and ownership discovery.
final class ContactTestPDO extends PDO
{
    public ?Closure $beforeOwnershipDiscovery = null;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->beforeOwnershipDiscovery !== null && str_starts_with($query, 'SELECT o.registry_id, o.object_name, o.reseller_id, r.name FROM object_ownership')) {
            $callback = $this->beforeOwnershipDiscovery;
            $this->beforeOwnershipDiscovery = null;
            $callback();
        }
        return parent::prepare($query, $options);
    }
}

$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true];
$dbAdmin = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$db = 'reseller_contacts_' . bin2hex(random_bytes(6)); $dbAdmin->exec("CREATE DATABASE `$db`");
$pdo = new ContactTestPDO($dsn . ';dbname=' . $db, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$_ENV['APP_MASTER_KEY'] = 'base64:' . base64_encode(random_bytes(32)); $_ENV['APP_ENV'] = 'local'; $_ENV['PANEL_ORIGIN'] = 'http://localhost';
$sessionRoot = sys_get_temp_dir() . '/' . $db; PanelSession::start($sessionRoot); $checks = 0;
function check(bool $ok, string $message): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($message); } }
function request(string $path, string $method = 'GET', array $input = [], bool $csrf = true): Response {
    global $panel; if ($csrf && $method === 'POST') { $input['csrf'] = $_SESSION['csrf']; }
    parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
    return $panel->handle((new ServerRequest($method, 'http://localhost' . $path, [], '', '1.1', ['REMOTE_ADDR' => '127.0.0.1']))->withParsedBody($input)->withQueryParams($query), new Response());
}
function html(string $path): string { return (string) request($path)->getBody(); }
function value(string $sql, array $params = []): mixed { global $pdo; $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
function input(array $regs): array {
    return ['contact_id' => Util::uuid(), 'key' => Util::uuid(), 'action' => 'save', 'registries' => $regs,
        'fields' => ['id' => 'C' . bin2hex(random_bytes(6)), 'type' => 'int', 'firstname' => 'Contact', 'lastname' => 'Test', 'companyname' => 'Contact Company',
            'email' => 'private@example.test', 'address1' => 'Private address', 'address2' => '', 'city' => 'Sofia', 'state' => '', 'postcode' => '1000', 'country' => 'BG', 'fullphonenumber' => '+359.888123456', 'authInfoPw' => 'ContactSecret!123'],
        'extra_key' => ['nin', '', '', '', ''], 'extra_value' => ['123456', '', '', '', '']];
}
function finish(string $job, array $result, string $status = 'succeeded', int $code = 1000): void { global $pdo; Ledger::completeJob($pdo, $job, $status, $result, $code); }
try {
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $root = Util::uuid(); $a = Util::uuid(); $b = Util::uuid(); $password = 'valid contact test password'; $hash = password_hash($password, PASSWORD_ARGON2ID);
    foreach ([$root => 'admin', $a => 'reseller-a', $b => 'reseller-b'] as $id => $name) {
        $pdo->prepare("INSERT INTO resellers (id, username, password_hash, currency, role) VALUES (?, ?, ?, 'EUR', ?)")->execute([$id, $name, $hash, (int) ($id !== $root)]);
        $pdo->prepare("INSERT INTO wallets (reseller_id, currency) VALUES (?, 'EUR')")->execute([$id]);
    }
    $r1 = Util::uuid(); $r2 = Util::uuid(); $lt = Util::uuid(); $vrsn = Util::uuid();
    foreach ([$r1 => ['Registry One', 'generic'], $r2 => ['Registry Two', 'generic'], $lt => ['Lithuania', 'LT'], $vrsn => ['Contactless', 'VRSN']] as $id => [$name, $profile]) {
        [$nonce, $cipher] = Crypto::encrypt('{}', 'registry-config:' . $id);
        $pdo->prepare('INSERT INTO registries (id, name, profile, config_nonce, config_cipher) VALUES (?, ?, ?, ?, ?)')->execute([$id, $name, $profile, $nonce, $cipher]);
        foreach ([$a, $b] as $owner) { $pdo->prepare('INSERT INTO reseller_registries (reseller_id, registry_id) VALUES (?, ?)')->execute([$owner, $id]); }
    }
    $actor = ['id' => $a, 'role' => 1]; $admin = ['id' => $root, 'role' => 0];
    $panel = new PanelController($pdo, Twig::create(dirname(__DIR__) . '/panel/views', ['cache' => false, 'autoescape' => 'html']), new PanelApi(new Client()));
    PanelSession::login(Auth::login($pdo, 'reseller-a', $password, '127.0.0.1'));
    $list = html('/contacts');
    check(str_contains($list, 'data-bs-target="#add-contact"') && str_contains($list, 'modal-dialog-scrollable'), 'Header add-contact modal');
    check(substr_count($list, 'name="extra_key[') === 5 && substr_count($list, 'name="extra_value[') === 5, 'Exactly five two-input extra rows');
    check(str_contains($list, 'name="fields[euType]"') && str_contains($list, 'name="fields[ident_pdf]"'), 'All pinned profile-required contact options exposed');
    check(str_contains($list, 'href="/domains">Domains') && !str_contains($list, '>Objects</a>'), 'Objects menu renamed Domains');
    $hidden = Util::uuid(); [$nonce, $cipher] = Crypto::encrypt('{}', 'registry-config:' . $hidden);
    $pdo->prepare("INSERT INTO registries (id, name, profile, config_nonce, config_cipher) VALUES (?, 'Hidden registry name', 'generic', ?, ?)")->execute([$hidden, $nonce, $cipher]);
    $forged = request('/contacts', 'POST', input([$hidden]));
    check($forged->getStatusCode() === 404 && !str_contains((string) $forged->getBody(), 'Hidden registry name'), 'Forged registry selection does not expose unavailable registry metadata');
    check(value('SELECT COUNT(*) FROM panel_contacts') === '0', 'Rejected unauthorized create leaves no empty local contact');
    $in = input([$r1, $r2]); $in['reseller_id'] = $b; $id = $in['contact_id'];
    check(request('/contacts', 'POST', $in, false)->getStatusCode() === 403, 'Create requires CSRF');
    $response = request('/contacts', 'POST', $in);
    check($response->getStatusCode() === 303, 'Contact create accepted: ' . (string) $response->getBody());
    $row = Contacts::get($pdo, $id, $actor);
    check(count($row['registries']) === 2, 'One contact with two registry mappings');
    check(value('SELECT reseller_id FROM panel_contacts WHERE id = ?', [$id]) === $a, 'Contact belongs to authenticated reseller');
    check(request('/contacts/' . $id . '?format=json')->getStatusCode() === 200 && str_contains(html('/contacts/' . $id), '/contacts.js'), 'Queued contact operations expose polling without new submissions');
    check(!str_contains(value('SELECT data_cipher FROM panel_contacts WHERE id = ?', [$id]), 'ContactSecret') && !str_contains(value('SELECT registries FROM panel_contacts WHERE id = ?', [$id]), 'private@example.test'), 'Contact PII and auth code encrypted; mapping JSON has no contact payload');
    foreach ($row['registries'] as $reg => $link) {
        $job = value('SELECT request_cipher FROM epp_jobs WHERE id = ?', [$link['job_uuid']]); check($job !== false, 'Registry job recorded');
        $stmt = $pdo->prepare('SELECT * FROM epp_jobs WHERE id = ?'); $stmt->execute([$link['job_uuid']]); $native = Ownership::params($stmt->fetch());
        check($native['nin'] === '123456', 'Extra nin parameter passed to native EPP request');
        finish($link['job_uuid'], ['id' => $native['id'], 'code' => 1000, 'msg' => 'Contact created']);
    }
    $row = Contacts::get($pdo, $id, $actor);
    check($row['registries'][$r1]['contact_id'] === $in['fields']['id'], 'Registry contact ID recorded after success');
    check($row['registries'][$r2]['registry_name'] === 'Registry Two', 'Registry name retained in JSON');
    check(value('SELECT COUNT(*) FROM ledger_entries') === '0', 'Contact operations remain free');
    check(request('/admin/contacts')->getStatusCode() === 403 && request('/admin/domains')->getStatusCode() === 403, 'Reseller cannot access admin contacts/domains');
    PanelSession::login(Auth::login($pdo, 'reseller-b', $password, '127.0.0.1'));
    check(request('/contacts/' . $id)->getStatusCode() === 404, 'Foreign contact detail is hidden');
    check(request('/contacts/' . $id, 'POST', ['action' => 'delete', 'key' => Util::uuid()])->getStatusCode() === 404, 'Foreign contact mutation blocked');
    check(!str_contains(html('/contacts'), $id), 'Reseller contact list does not leak another owner');
    check(request('/contacts/' . $id . '?format=json')->getStatusCode() === 404, 'Polling cannot leak foreign contact data');
    PanelSession::login(Auth::login($pdo, 'admin', $password, '127.0.0.1'));
    check(str_contains(html('/admin/contacts'), $id) && str_contains(html('/admin/contacts'), 'reseller-a'), 'Admin sees every contact and owner');
    check(request('/contacts/' . $id)->getStatusCode() === 404, 'Admin reseller workspace remains own-only');
    check(request('/admin/contacts/' . $id)->getStatusCode() === 200, 'Admin can manage another reseller contact');
    html('/admin/contacts');
    check(value('SELECT COUNT(*) FROM panel_contacts WHERE reseller_id = ?', [$a]) === '1', 'Mixed-case native IDs do not produce duplicate ownership imports');
    $sync = ['action' => 'sync', 'key' => Util::uuid(), 'registry_id' => $r1];
    check(request('/admin/contacts/' . $id, 'POST', $sync)->getStatusCode() === 303, 'Admin submits sync for contact owner');
    $row = Contacts::get($pdo, $id, $admin); $job = $row['registries'][$r1]['job_uuid'];
    check(value('SELECT reseller_id FROM epp_jobs WHERE id = ?', [$job]) === $a, 'Admin action uses contact owner permissions, not administrator impersonation');
    finish($job, ['id' => $in['fields']['id'], 'name' => 'Registry Person', 'org' => 'Registry Company', 'street1' => 'Registry address', 'voice' => '+359.111222333', 'email' => 'synced@example.test', 'country' => 'BG', 'nin' => '654321']);
    $row = Contacts::get($pdo, $id, $admin);
    check($row['data']['fields']['firstname'] === 'Registry' && $row['data']['fields']['lastname'] === 'Person' && $row['data']['fields']['email'] === 'synced@example.test', 'Sync prefills standard fields from contact.info');
    check($row['data']['extra']['nin'] === '654321', 'Sync updates matching custom parameter');
    check($row['registries'][$r2]['contact_id'] === $in['fields']['id'], 'Sync leaves other registry identity unchanged');
    $edit = html('/admin/contacts/' . $id);
    check(str_contains($edit, 'value="synced@example.test"') && str_contains($edit, 'Sync contact'), 'Edit shares prefilled form plus sync card');
    request('/admin/contacts/' . $id, 'POST', ['action' => 'sync', 'key' => Util::uuid(), 'registry_id' => $r1]);
    $mismatched = Contacts::get($pdo, $id, $admin);
    finish($mismatched['registries'][$r1]['job_uuid'], ['id' => 'ANOTHER-CONTACT', 'email' => 'wrong@example.test']);
    $row = Contacts::get($pdo, $id, $admin);
    check($row['data']['fields']['email'] === 'synced@example.test' && $row['registries'][$r1]['status'] === 'rejected', 'Mismatched contact-info identity cannot replace local contact data');
    $update = $in; $update['key'] = Util::uuid(); $update['version'] = $row['version']; $update['fields'] = $row['data']['fields']; $update['extra_value'][0] = '654321';
    check(request('/admin/contacts/' . $id, 'POST', $update)->getStatusCode() === 303, 'Admin updates both registry links');
    $row = Contacts::get($pdo, $id, $admin);
    foreach ($row['registries'] as $reg => $link) { check(value('SELECT command FROM epp_jobs WHERE id = ?', [$link['job_uuid']]) === 'contact.update', 'Update uses correct native operation'); finish($link['job_uuid'], ['code' => 1000, 'msg' => 'Updated']); }
    $row = Contacts::get($pdo, $id, $admin);
    check(request('/admin/contacts/' . $id, 'POST', $update)->getStatusCode() === 409, 'Stale form version cannot overwrite changed contact');
    check(request('/admin/contacts/' . $id, 'POST', ['action' => 'delete', 'key' => Util::uuid()])->getStatusCode() === 303, 'Delete submitted to registry rather than prechecking associations');
    $row = Contacts::get($pdo, $id, $admin);
    finish($row['registries'][$r1]['job_uuid'], ['code' => 2305, 'msg' => 'Object association prohibits operation <script>registry-message</script>'], 'rejected', 2305);
    finish($row['registries'][$r2]['job_uuid'], ['code' => 1000, 'msg' => 'Deleted']);
    $row = Contacts::get($pdo, $id, $admin);
    check(count($row['registries']) === 1 && isset($row['registries'][$r1]), 'Only confirmed deleted registry links removed');
    check(str_contains(html('/admin/contacts/' . $id), 'Object association prohibits operation') && str_contains(html('/admin/contacts/' . $id), 'EPP 2305'), 'Registry deletion error and EPP code displayed');
    check(str_contains(html('/admin/contacts/' . $id), '&lt;script&gt;registry-message&lt;/script&gt;'), 'Registry error messages are escaped in the panel');
    check(!str_contains(value('SELECT registries FROM panel_contacts WHERE id = ?', [$id]), 'Object association prohibits operation'), 'Raw registry messages stay encrypted rather than leaking into routing JSON');
    check(request('/admin/contacts/' . $id, 'POST', ['action' => 'authinfo', 'key' => Util::uuid(), 'registry_id' => $r1, 'authInfo' => 'NewAuthInfo!123'])->getStatusCode() === 303, 'Dedicated authorization update available');
    $authRow = Contacts::get($pdo, $id, $admin); $authJob = $authRow['registries'][$r1]['job_uuid'];
    check(value('SELECT command FROM epp_jobs WHERE id = ?', [$authJob]) === 'contact.update_authinfo', 'Authorization code uses the API authinfo command');
    finish($authJob, ['code' => 1000]);
    check(Contacts::get($pdo, $id, $admin)['data']['fields']['authInfoPw'] === 'NewAuthInfo!123', 'Authorization cache changes only after confirmed success');
    $draft = input([$vrsn]); $draft['reseller_id'] = $a;
    check(request('/admin/contacts', 'POST', $draft)->getStatusCode() === 303, 'Unsupported profile error retained in contact page');
    $draftRow = Contacts::get($pdo, $draft['contact_id'], $admin);
    check($draftRow['registries'][$vrsn]['status'] === 'rejected' && $draftRow['registries'][$vrsn]['job_uuid'] === null, 'Unsupported registry profile never reaches EPP dispatch');
    check(request('/admin/contacts/' . $draft['contact_id'], 'POST', ['action' => 'delete', 'key' => Util::uuid()])->getHeaderLine('Location') === '/admin/contacts', 'Rejected create draft can be removed locally');
    // Recover a crash after the ledger commits, before its UUID reaches the local contact.
    $crash = input([$r1]); $crash['reseller_id'] = $a; request('/admin/contacts', 'POST', $crash);
    $crashed = Contacts::get($pdo, $crash['contact_id'], $admin); $savedJob = $crashed['registries'][$r1]['job_uuid'];
    $crashed['registries'][$r1]['job_uuid'] = null; $crashed['registries'][$r1]['status'] = 'prepared';
    $pdo->prepare('UPDATE panel_contacts SET registries = ? WHERE id = ?')->execute([Util::json($crashed['registries']), $crash['contact_id']]);
    $jobCount = value('SELECT COUNT(*) FROM epp_jobs');
    $recovered = Contacts::get($pdo, $crash['contact_id'], $admin);
    check($recovered['registries'][$r1]['job_uuid'] === $savedJob && value('SELECT COUNT(*) FROM epp_jobs') === $jobCount, 'Lost job UUID recovered by immutable key without another submission');
    Ledger::completeJob($pdo, $savedJob, 'unknown', ['error' => 'transport uncertainty'], null);
    $blocked = input([$r1]);
    check(request('/admin/contacts/' . $crash['contact_id'], 'POST', $blocked)->getStatusCode() === 409, 'Unknown writes cannot be replaced or blindly replayed');
    Ledger::resolveUnknown($pdo, $savedJob, 'release'); Contacts::get($pdo, $crash['contact_id'], $admin);
    $crash['key'] = Util::uuid();
    check(request('/admin/contacts/' . $crash['contact_id'], 'POST', $crash)->getStatusCode() === 303, 'Verified rejection allows a new create attempt');
    $recovered = Contacts::get($pdo, $crash['contact_id'], $admin);
    finish($recovered['registries'][$r1]['job_uuid'], ['id' => $crash['fields']['id'], 'code' => 1000]);
    $_ENV['API_RATE_LIMIT_PER_MINUTE'] = '0';
    $limited = input([$r1]); $limited['reseller_id'] = $a; request('/admin/contacts', 'POST', $limited);
    $limitedRow = Contacts::get($pdo, $limited['contact_id'], $admin);
    check($limitedRow['registries'][$r1]['status'] === 'prepared' && $limitedRow['registries'][$r1]['job_uuid'] === null, 'Rate-limited submission retains resumable intent without enqueue');
    $_ENV['API_RATE_LIMIT_PER_MINUTE'] = '120';
    request('/admin/contacts/' . $limited['contact_id'], 'POST', ['action' => 'resume', 'key' => Util::uuid()]);
    $limitedRow = Contacts::get($pdo, $limited['contact_id'], $admin);
    check($limitedRow['registries'][$r1]['job_uuid'] !== null, 'Resume submits the frozen request when the limit permits');
    finish($limitedRow['registries'][$r1]['job_uuid'], ['id' => $limited['fields']['id'], 'code' => 1000]);
    // Generated registry contact IDs must replace the requested ID (LT has server-generated handles).
    $generated = input([$lt]); $generated['fields']['orgcode'] = ''; $generated['fields']['role'] = ''; $generated['reseller_id'] = $a;
    request('/admin/contacts', 'POST', $generated); $generatedRow = Contacts::get($pdo, $generated['contact_id'], $admin);
    finish($generatedRow['registries'][$lt]['job_uuid'], ['id' => 'LT-GENERATED', 'code' => 1000]);
    check(Contacts::get($pdo, $generated['contact_id'], $admin)['registries'][$lt]['contact_id'] === 'LT-GENERATED', 'Stores actual registry-generated contact ID');
    $multi = input([$r2, $lt]); $multi['fields']['orgcode'] = ''; $multi['fields']['role'] = ''; $multi['reseller_id'] = $a;
    request('/admin/contacts', 'POST', $multi); $multiRow = Contacts::get($pdo, $multi['contact_id'], $admin);
    foreach ($multiRow['registries'] as $reg => $link) { finish($link['job_uuid'], ['id' => $reg === $lt ? 'LT-MULTI-GENERATED' : $multi['fields']['id'], 'code' => 1000]); }
    $multiRow = Contacts::get($pdo, $multi['contact_id'], $admin);
    check($multiRow['registries'][$r2]['contact_id'] !== $multiRow['registries'][$lt]['contact_id'], 'One contact retains distinct native IDs at different registries');
    $multi['key'] = Util::uuid(); $multi['fields']['id'] = 'DO-NOT-RETARGET';
    check(request('/admin/contacts/' . $multi['contact_id'], 'POST', $multi)->getStatusCode() === 303, 'Multi-registry update submitted');
    $updatedMulti = Contacts::get($pdo, $multi['contact_id'], $admin);
    foreach ($updatedMulti['registries'] as $reg => $link) {
        $stmt = $pdo->prepare('SELECT * FROM epp_jobs WHERE id = ?'); $stmt->execute([$link['job_uuid']]); $params = Ownership::params($stmt->fetch());
        check($params['id'] === $multiRow['registries'][$reg]['contact_id'], 'Update uses each stored registry ID rather than the form ID');
        finish($link['job_uuid'], ['code' => 1000]);
    }
    // A worker can finish after the first pending refresh but before discovery reads ownership.
    foreach ([$r1, $lt] as $raceRegistry) {
        $race = input([$raceRegistry]); $race['reseller_id'] = $a;
        $race['fields']['orgcode'] = ''; $race['fields']['role'] = '';
        check(request('/admin/contacts', 'POST', $race)->getStatusCode() === 303, 'Race fixture create submitted');
        $raceRow = Contacts::get($pdo, $race['contact_id'], $admin);
        $raceJob = $raceRow['registries'][$raceRegistry]['job_uuid'];
        $actualId = $raceRegistry === $lt ? 'LT-RACE-GENERATED' : $race['fields']['id'];
        $beforeRace = value('SELECT COUNT(*) FROM panel_contacts');
        $pdo->beforeOwnershipDiscovery = static function () use ($raceJob, $actualId): void {
            finish($raceJob, ['id' => $actualId, 'code' => 1000]);
        };
        Contacts::listing($pdo, $admin, 1, true);
        check($pdo->beforeOwnershipDiscovery === null, 'Worker finishes at the discovery boundary');
        check(value('SELECT COUNT(*) FROM panel_contacts') === $beforeRace, 'Completed panel create is not imported as a duplicate');
        check(Contacts::get($pdo, $race['contact_id'], $admin)['registries'][$raceRegistry]['contact_id'] === $actualId, 'Original contact receives the completed registry ID');
        Contacts::listing($pdo, $admin, 1, true);
        check(value('SELECT COUNT(*) FROM panel_contacts') === $beforeRace, 'Repeated discovery preserves one local contact');
    }
    // An unrelated pending panel create must not hide existing API contacts in the same registry.
    $waiting = input([$r1]); $waiting['reseller_id'] = $b;
    check(request('/admin/contacts', 'POST', $waiting)->getStatusCode() === 303, 'Unrelated create remains pending');
    // Existing API/CLI objects appear without migration guesses or duplication on refresh.
    $pdo->prepare("INSERT INTO object_ownership (registry_id, object_type, object_name, reseller_id, active) VALUES (?, 'contact', 'LEGACY-B', ?, 1)")->execute([$r1, $b]);
    check(str_contains(html('/admin/contacts'), 'LEGACY-B'), 'Admin list imports existing API-owned contacts');
    html('/admin/contacts');
    check(value('SELECT COUNT(*) FROM panel_contacts WHERE reseller_id = ?', [$b]) === '2', 'Ownership import remains idempotent alongside a pending create');
    for ($i = 0; $i < 55; ++$i) { $pdo->prepare("INSERT INTO object_ownership (registry_id, object_type, object_name, reseller_id, active) VALUES (?, 'contact', ?, ?, 1)")->execute([$r1, sprintf('paginate-b-%02d', $i), $b]); }
    $paged = html('/admin/contacts'); preg_match('/<tbody>(.*?)<\/tbody>/s', $paged, $body);
    check(substr_count($body[1], '<tr>') === 50 && str_contains($paged, '?page=2'), 'Contact list paginates at 50 complete contacts');
    check(request('/admin/contacts?page=2')->getStatusCode() === 200, 'Second contact page available');
    $pdo->prepare("INSERT INTO object_ownership (registry_id, object_type, object_name, reseller_id, active) VALUES (?, 'domain', 'owned-a.com', ?, 1), (?, 'domain', 'owned-b.com', ?, 1)")->execute([$r1, $a, $r1, $b]);
    check(str_contains(html('/admin/domains'), 'owned-a.com') && str_contains(html('/admin/domains'), 'owned-b.com'), 'Admin domain list spans all owners');
    PanelSession::login(Auth::login($pdo, 'reseller-a', $password, '127.0.0.1'));
    check(str_contains(html('/domains'), 'owned-a.com') && !str_contains(html('/domains'), 'owned-b.com') && !str_contains(html('/domains'), 'LEGACY-B'), 'Reseller domain list is domain-only and owner-scoped');
    // Apply the new migration to a v5 database and repeat safely.
    $pdo->exec('DROP TABLE panel_contacts'); $pdo->exec("DELETE FROM schema_migrations WHERE version = '006_contacts'");
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/006_contacts.sql'));
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/006_contacts.sql'));
    check(value("SELECT COUNT(*) FROM schema_migrations WHERE version='006_contacts'") === '1', 'Idempotent v5-to-v6 migration');
    check(str_contains(html('/contacts'), 'lt-generated'), 'Existing contacts remain discoverable after migration with canonical ownership IDs');
    echo "PASS: {$checks} contact-management assertions\n";
} finally {
    $pdo = null; $dbAdmin->exec("DROP DATABASE `$db`"); session_destroy();
    foreach (glob($sessionRoot . '/var/panel-sessions/*') ?: [] as $file) { unlink($file); }
    @rmdir($sessionRoot . '/var/panel-sessions'); @rmdir($sessionRoot . '/var'); @rmdir($sessionRoot);
}
