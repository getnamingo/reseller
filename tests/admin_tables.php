<?php

declare(strict_types=1);
use GuzzleHttp\Client;
use Namingo\ResellerApi\{Admin, Auth, CommandCatalog, Ledger, PanelApi, PanelController, PanelSession, Util};
use Nyholm\Psr7\{Response, ServerRequest};
use Slim\Views\Twig;
require dirname(__DIR__) . '/vendor/autoload.php';
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true];
$dbAdmin = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$db = 'reseller_admin_tables_' . bin2hex(random_bytes(6)); $dbAdmin->exec("CREATE DATABASE `$db`");
$pdo = new PDO($dsn . ';dbname=' . $db, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$_ENV['APP_MASTER_KEY'] = 'base64:' . base64_encode(random_bytes(32)); $_ENV['APP_ENV'] = 'local'; $_ENV['PANEL_ORIGIN'] = 'http://localhost';
$sessionRoot = sys_get_temp_dir() . '/' . $db; PanelSession::start($sessionRoot); $checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function request(string $path, string $method = 'GET', array $input = [], bool $csrf = true): Response {
    global $panel;
    if ($csrf && $method === 'POST') { $input['csrf'] = $_SESSION['csrf']; }
    parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
    return $panel->handle((new ServerRequest($method, 'http://localhost' . $path, [], '', '1.1', ['REMOTE_ADDR' => '127.0.0.1']))->withParsedBody($input)->withQueryParams($query), new Response());
}
function html(string $path): string { return (string) request($path)->getBody(); }
function actions(string $html): array {
    preg_match_all('/data-admin-action="([^"]+)"/', $html, $matches); $actions = [];
    foreach ($matches[1] as $raw) { $config = json_decode(html_entity_decode($raw, ENT_QUOTES), true, 32, JSON_THROW_ON_ERROR); $actions[$config['id']][] = $config; }
    return $actions;
}
function rows(string $html): int { preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $m); return substr_count($m[1] ?? '', '<tr>'); }
function value(string $sql, array $params = []): mixed { global $pdo; $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
function balance(string $column): string { global $reseller; return value("SELECT $column FROM wallets WHERE reseller_id=?", [$reseller]); }
function add(string $amount, string $change): string { return number_format((float) $amount + (float) $change, 4, '.', ''); }
function deposit(string $amount = '25', string $status = 'pending'): string {
    global $pdo, $reseller; $id = Util::uuid();
    $pdo->prepare("INSERT INTO deposit_requests (id, reseller_id, client_reference, amount, currency, status) VALUES (?, ?, ?, ?, 'EUR', ?)")->execute([$id, $reseller, 'reference-' . $id, $amount, $status]); return $id;
}
function job(string $name, string $status): string {
    global $pdo, $reseller, $registry;
    $job = Ledger::createJob($pdo, $reseller, $registry, 'domain.create', Util::uuid(), ['domainname' => $name . '.com', 'period' => 1, 'authInfoPw' => 'secret']); $id = $job['job_id'];
    if ($status === 'processing') { $pdo->prepare("UPDATE epp_jobs SET status='processing', started_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$id]); }
    elseif ($status !== 'queued') { Ledger::completeJob($pdo, $id, $status, [], null); }
    return $id;
}
try {
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $root = Util::uuid(); $reseller = Util::uuid(); $password = 'table interface test password'; $hash = password_hash($password, PASSWORD_ARGON2ID);
    foreach ([$root => 'admin', $reseller => 'reseller'] as $id => $name) {
        $pdo->prepare("INSERT INTO resellers (id, username, password_hash, currency, role) VALUES (?, ?, ?, 'EUR', ?)")->execute([$id, $name, $hash, (int) ($id !== $root)]);
        $pdo->prepare("INSERT INTO wallets (reseller_id, currency, available_balance) VALUES (?, 'EUR', 1000)")->execute([$id]);
    }
    $registry = Admin::perform($pdo, $root, 'registry.save', ['name' => 'Example registry', 'profile' => 'generic', 'config_json' => Util::json(['connection' => ['host' => 'registry.invalid'], 'login' => ['clID' => 'test', 'pw' => 'secret']])], '127.0.0.1');
    $pdo->prepare('INSERT INTO reseller_registries (reseller_id, registry_id) VALUES (?, ?)')->execute([$reseller, $registry]);
    $pdo->prepare("INSERT INTO registry_tlds (registry_id, tld) VALUES (?, 'com')")->execute([$registry]);
    $pdo->prepare("INSERT INTO reseller_tlds (reseller_id, registry_id, tld) VALUES (?, ?, 'com')")->execute([$reseller, $registry]);
    foreach ([CommandCatalog::GLOBAL_PRICE_UUID, $reseller] as $scope) {
        foreach (CommandCatalog::priceOperations() as $command => $_) {
            foreach ($command === 'domain.restore' ? [0] : range(1, 10) as $period) { Admin::savePrice($pdo, $registry, $scope, $command, 'com', $period, '10', 'EUR'); }
        }
    }
    $panel = new PanelController($pdo, Twig::create(dirname(__DIR__) . '/panel/views', ['cache' => false, 'autoescape' => 'html']), new PanelApi(new Client()));
    PanelSession::login(Auth::login($pdo, 'admin', $password, '127.0.0.1'));
    for ($i = 0; $i < 51; ++$i) {
        $zone = sprintf('zone%02d', $i);
        $pdo->prepare('INSERT INTO registry_tlds (registry_id, tld) VALUES (?, ?)')->execute([$registry, $zone]);
        Admin::savePrice($pdo, $registry, CommandCatalog::GLOBAL_PRICE_UUID, 'domain.create', $zone, 1, '10', 'EUR');
    }
    $prices = html('/admin/prices');
    check(str_contains($prices, 'data-bs-target="#add-price"') && str_contains($prices, 'modal-dialog-scrollable modal-xl'), 'Add price opens a large scrollable header modal');
    check(strpos($prices, 'TLD price groups') < strpos($prices, 'id="add-price"') && substr_count($prices, 'name="amounts[') === 62, 'Price list is primary; complete price editor is in modal');
    check(rows($prices) === 50 && rows(html('/admin/prices?page=2')) === 3, 'Price table paginates whole TLD groups');
    check(substr_count($prices, 'data-price-edit=') === 50 && substr_count($prices, 'value="price.group_delete"') === 50, 'Price row edit/delete retained');
    $pendingA = deposit('25'); $pendingR = deposit('30'); $approved = deposit('1', 'approved'); $rejected = deposit('1', 'rejected');
    $deposits = html('/admin/deposits'); $controls = actions($deposits);
    check(count($controls[$pendingA]) === 2 && $controls[$pendingA][0]['action'] === 'deposit.approve' && $controls[$pendingA][1]['action'] === 'deposit.reject', 'Pending deposit has row approve and reject controls');
    check(!isset($controls[$approved]) && !isset($controls[$rejected]), 'Processed deposits have no decision buttons');
    check(strpos($deposits, '<table') < strpos($deposits, 'id="admin-confirmation"') && !str_contains($deposits, 'Decide a pending deposit'), 'Deposits focus on table without top cards');
    check(str_contains($deposits, 'name="confirmed" value="1" required') && str_contains($deposits, '/admin-actions.js'), 'Row actions retain explicit confirmation');
    $input = ['action' => 'deposit.approve', 'id' => $pendingA, 'confirmed' => '1'];
    check(request('/admin/deposits', 'POST', $input, false)->getStatusCode() === 403, 'Deposit row actions require CSRF');
    check(request('/admin/deposits', 'POST', ['action' => 'deposit.approve', 'id' => $pendingA])->getStatusCode() === 422, 'Deposit approval requires verification');
    $before = balance('available_balance');
    check(request('/admin/deposits', 'POST', $input)->getStatusCode() === 303 && balance('available_balance') === add($before, '25.0000'), 'Deposit approval credits wallet exactly');
    check(request('/admin/deposits', 'POST', $input)->getStatusCode() === 422 && balance('available_balance') === add($before, '25.0000'), 'Repeat approval cannot double-credit wallet');
    $before = balance('available_balance');
    check(request('/admin/deposits', 'POST', ['action' => 'deposit.reject', 'id' => $pendingR, 'confirmed' => '1'])->getStatusCode() === 303 && balance('available_balance') === $before, 'Deposit rejection does not credit wallet');
    check(!isset(actions(html('/admin/deposits'))[$pendingA]) && !isset(actions(html('/admin/deposits'))[$pendingR]), 'Completed decisions disappear from row actions');
    $pending = job('pending', 'pending'); $unknown = job('unknown', 'unknown'); $processing = job('processing', 'processing'); $queued = job('queued', 'queued'); $done = job('done', 'succeeded'); $failed = job('failed', 'rejected');
    $jobs = html('/admin/jobs'); $controls = actions($jobs);
    check(count($controls[$pending]) === 2 && $controls[$pending][0]['resolution'] === 'charge' && $controls[$pending][1]['resolution'] === 'release', 'Pending job offers approve/charge and release');
    check(count($controls[$unknown]) === 2 && $controls[$processing][0]['action'] === 'job.mark_unknown', 'Unknown job resolves; processing job can be marked unknown');
    check(!isset($controls[$queued]) && !isset($controls[$done]) && !isset($controls[$failed]), 'Queued and final jobs have no unsafe controls');
    check(str_contains($jobs, '/admin/jobs/' . $pending) && str_contains($jobs, 'Example registry') && !str_contains($jobs, 'Resolve a pending or unknown job'), 'Job table keeps detail links and context without top cards');
    check(request('/admin/jobs', 'POST', ['action' => 'job.resolve', 'resolution' => 'charge', 'id' => $pending])->getStatusCode() === 422, 'Job actions require verification');
    $held = balance('held_balance'); $before = balance('available_balance');
    check(request('/admin/jobs', 'POST', ['action' => 'job.resolve', 'resolution' => 'charge', 'id' => $pending, 'confirmed' => '1'])->getStatusCode() === 303 && value('SELECT status FROM epp_jobs WHERE id=?', [$pending]) === 'succeeded', 'Job approve resolves as succeeded');
    check(balance('held_balance') === add($held, '-10.0000') && balance('available_balance') === $before, 'Approve charges reserved hold without charging available balance twice');
    $held = balance('held_balance'); $before = balance('available_balance');
    check(request('/admin/jobs', 'POST', ['action' => 'job.resolve', 'resolution' => 'release', 'id' => $unknown, 'confirmed' => '1'])->getStatusCode() === 303 && value('SELECT status FROM epp_jobs WHERE id=?', [$unknown]) === 'rejected', 'Release resolves as rejected');
    check(balance('held_balance') === add($held, '-10.0000') && balance('available_balance') === add($before, '10.0000'), 'Release returns the exact wallet hold');
    $held = balance('held_balance');
    check(request('/admin/jobs', 'POST', ['action' => 'job.mark_unknown', 'id' => $processing, 'confirmed' => '1'])->getStatusCode() === 303 && value('SELECT status FROM epp_jobs WHERE id=?', [$processing]) === 'unknown' && balance('held_balance') === $held, 'Unknown retains wallet hold');
    check(request('/admin/jobs', 'POST', ['action' => 'job.mark_unknown', 'id' => $queued, 'confirmed' => '1'])->getStatusCode() === 409, 'Backend rejects unknown action for queued jobs');
    check(request('/admin/jobs', 'POST', ['action' => 'job.resolve', 'resolution' => 'charge', 'id' => $done, 'confirmed' => '1'])->getStatusCode() === 422, 'Backend rejects resolution of completed jobs');
    $pageDeposit = deposit();
    for ($i = 0; $i < 51; ++$i) { deposit('1', 'rejected'); Ledger::createJob($pdo, $reseller, $registry, 'domain.check', Util::uuid(), ['domains' => ['paging.com']]); }
    check(rows(html('/admin/deposits')) === 50 && rows(html('/admin/deposits?page=2')) === 6, 'Deposits paginate all records');
    check(rows(html('/admin/jobs')) === 50 && rows(html('/admin/jobs?page=2')) === 7, 'Jobs paginate all records');
    check(request('/admin/deposits?page=2', 'POST', ['action' => 'deposit.reject', 'id' => $pageDeposit, 'confirmed' => '1'])->getHeaderLine('Location') === '/admin/deposits?page=2', 'Deposit action preserves page');
    check(request('/admin/jobs?page=2', 'POST', ['action' => 'job.resolve', 'resolution' => 'release', 'id' => $processing, 'confirmed' => '1'])->getHeaderLine('Location') === '/admin/jobs?page=2', 'Job action preserves page');
    PanelSession::login(Auth::login($pdo, 'reseller', $password, '127.0.0.1'));
    foreach (['prices', 'deposits', 'jobs'] as $section) { check(request('/admin/' . $section)->getStatusCode() === 403 && request('/admin/' . $section, 'POST', ['action' => 'deposit.approve', 'id' => $pageDeposit, 'confirmed' => '1'])->getStatusCode() === 403, 'Non-admin cannot read or act on ' . $section); }
    echo "PASS: $checks admin-table assertions\n";
} finally {
    $pdo = null; $dbAdmin->exec("DROP DATABASE `$db`"); session_destroy();
    foreach (glob($sessionRoot . '/var/panel-sessions/*') ?: [] as $file) { unlink($file); }
    @rmdir($sessionRoot . '/var/panel-sessions'); @rmdir($sessionRoot . '/var'); @rmdir($sessionRoot);
}
