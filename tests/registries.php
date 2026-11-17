<?php

declare(strict_types=1);
use GuzzleHttp\Client;
use Namingo\ResellerApi\{Admin, Auth, Crypto, PanelApi, PanelController, PanelSession, Util};
use Nyholm\Psr7\{Response, ServerRequest};
use Slim\Views\Twig;
require dirname(__DIR__) . '/vendor/autoload.php';
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true];
$dbAdmin = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$db = 'reseller_registries_' . bin2hex(random_bytes(6)); $dbAdmin->exec("CREATE DATABASE `$db`");
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
function table(string $html): string { preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $m); return $m[1] ?? ''; }
function action(string $name, array $input): string { global $pdo, $root; return Admin::perform($pdo, $root, $name, $input, '127.0.0.1'); }
function value(string $sql, array $params = []): mixed { global $pdo; $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
try {
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $root = Util::uuid(); $a = Util::uuid(); $b = Util::uuid(); $password = 'valid registry test password'; $hash = password_hash($password, PASSWORD_ARGON2ID);
    foreach ([$root => 'admin', $a => 'reseller-a', $b => 'reseller-b'] as $id => $name) {
        $pdo->prepare("INSERT INTO resellers (id, username, password_hash, currency, role) VALUES (?, ?, ?, 'EUR', ?)")->execute([$id, $name, $hash, (int) ($id !== $root)]);
        $pdo->prepare("INSERT INTO wallets (reseller_id, currency) VALUES (?, 'EUR')")->execute([$id]);
    }
    $panel = new PanelController($pdo, Twig::create(dirname(__DIR__) . '/panel/views', ['cache' => false, 'autoescape' => 'html']), new PanelApi(new Client()));
    PanelSession::login(Auth::login($pdo, 'admin', $password, '127.0.0.1'));
    $config = Util::json(['connection' => ['host' => 'registry.invalid'], 'login' => ['clID' => 'shared', 'pw' => 'hidden-upstream-password']]);
    $list = html('/admin/registries');
    check(str_contains($list, 'data-bs-target="#add-registry"') && str_contains($list, 'data-bs-target="#add-tld"'), 'Registry header has both add modals');
    preg_match('/id="add-registry"(.*?)<\/form>/s', $list, $modal);
    check(!str_contains($modal[1], 'name="registry_id"') && !str_contains($list, 'Existing registry UUID'), 'Add registry modal has no update UUID');
    check(!str_contains($list, 'Permission records') && !str_contains($list, 'href="/admin/tlds"'), 'Registry list has no shared permission table or standalone TLD navigation');
    check(request('/admin/registries', 'POST', ['action' => 'registry.save', 'name' => 'First registry', 'profile' => 'generic', 'config_json' => $config])->getStatusCode() === 303, 'Add registry modal creates registry');
    $one = value("SELECT id FROM registries WHERE name='First registry'");
    $two = action('registry.save', ['name' => 'Second registry', 'profile' => 'generic', 'config_json' => $config]);
    $base = '/admin/registries/' . $one; $other = '/admin/registries/' . $two;
    $list = html('/admin/registries');
    check(str_contains($list, $base . '/tlds') && str_contains($list, $base . '/permissions') && str_contains($list, '>Edit registry<'), 'Registry rows link to edit, TLDs and TLD permissions');
    check(request('/admin/registries', 'POST', ['action' => 'registry.save', 'registry_id' => $one, 'name' => 'Wrong', 'profile' => 'generic', 'config_json' => $config])->getStatusCode() === 422, 'Add-only endpoint rejects update UUID');
    check(request($base, 'POST', ['action' => 'registry.save', 'registry_id' => $two, 'name' => 'Updated registry', 'profile' => 'generic', 'config_json' => $config])->getStatusCode() === 303, 'Registry edit saves');
    check(value('SELECT name FROM registries WHERE id=?', [$one]) === 'Updated registry' && value('SELECT name FROM registries WHERE id=?', [$two]) === 'Second registry', 'Registry edit cannot target forged registry ID');
    $edit = html($base);
    check(str_contains($edit, 'name="name" type="text" value="Updated registry"') && str_contains($edit, 'name="epp_password" type="password" value="hidden-upstream-password"'), 'Administrator edit fields prefill saved settings');
    check(request($base, 'POST', ['action' => 'registry.status', 'enabled' => '0', 'registry_id' => $two])->getStatusCode() === 303, 'Registry state toggle posts');
    check(value('SELECT enabled FROM registries WHERE id=?', [$one]) === '0' && value('SELECT enabled FROM registries WHERE id=?', [$two]) === '1' && str_contains(html($base), 'Disabled — enable'), 'State toggle is scoped and reflects new color/state');
    request($base, 'POST', ['action' => 'registry.status', 'enabled' => '1']);
    request($base, 'POST', ['action' => 'registry.access', 'reseller_id' => $a, 'enabled' => '1']);
    request($other, 'POST', ['action' => 'registry.access', 'reseller_id' => $b, 'enabled' => '1']);
    check(str_contains(table(html($base)), 'reseller-a') && !str_contains(table(html($base)), 'reseller-b'), 'Registry permission table is scoped');
    request($base, 'POST', ['action' => 'registry.access', 'reseller_id' => $a, 'enabled' => '0']);
    check(value('SELECT enabled FROM reseller_registries WHERE registry_id=? AND reseller_id=?', [$one, $a]) === '0', 'Registry access revokes');
    request($base, 'POST', ['action' => 'registry.access', 'reseller_id' => $a, 'enabled' => '1']);
    check(request('/admin/registries', 'POST', ['action' => 'tld.save', 'registry_id' => $one, 'tld' => 'com', 'enabled' => '1'])->getStatusCode() === 303, 'Main add TLD modal works');
    request($other . '/tlds', 'POST', ['action' => 'tld.save', 'tld' => 'org', 'enabled' => '1']);
    check(str_contains(table(html($base . '/tlds')), '.com') && !str_contains(table(html($base . '/tlds')), '.org'), 'TLD list is scoped to registry');
    request($base . '/tlds', 'POST', ['action' => 'tld.save', 'registry_id' => $two, 'tld' => 'com', 'enabled' => '0']);
    check(value("SELECT enabled FROM registry_tlds WHERE registry_id=? AND tld='com'", [$one]) === '0' && value("SELECT enabled FROM registry_tlds WHERE registry_id=? AND tld='org'", [$two]) === '1', 'TLD toggle ignores forged registry ID');
    check(str_contains(table(html($base . '/tlds')), 'btn-secondary') && str_contains(table(html($base . '/tlds')), 'Disabled — enable'), 'Disabled TLD toggle changes color');
    request($base . '/tlds', 'POST', ['action' => 'tld.save', 'tld' => 'com', 'enabled' => '1']);
    check(str_contains(table(html($base . '/tlds')), 'btn-success'), 'Enabled TLD toggle is green');
    check(request($base . '/permissions', 'POST', ['action' => 'tld.access', 'reseller_id' => $a, 'tld' => 'com', 'enabled' => '1'])->getStatusCode() === 303, 'Permit TLD modal grants access');
    request($other . '/permissions', 'POST', ['action' => 'tld.access', 'reseller_id' => $b, 'tld' => 'org', 'enabled' => '1']);
    $permissions = html($base . '/permissions');
    check(str_contains($permissions, 'data-bs-target="#permit-tld"') && str_contains(table($permissions), 'reseller-a') && !str_contains(table($permissions), 'reseller-b'), 'TLD permission page has modal and scoped table');
    request($base . '/permissions', 'POST', ['action' => 'tld.access', 'registry_id' => $two, 'reseller_id' => $a, 'tld' => 'com', 'enabled' => '0']);
    check(value("SELECT enabled FROM reseller_tlds WHERE registry_id=? AND reseller_id=? AND tld='com'", [$one, $a]) === '0' && str_contains(table(html($base . '/permissions')), 'btn-secondary'), 'Permission toggle is scoped and changes color');
    request($base . '/permissions', 'POST', ['action' => 'tld.access', 'reseller_id' => $a, 'tld' => 'com', 'enabled' => '1']);
    check(str_contains(table(html($base . '/permissions')), 'btn-success'), 'Permission re-enable is green');
    request($base . '/permissions', 'POST', ['action' => 'tld.unassign', 'reseller_id' => $a, 'tld' => 'com']);
    check(value('SELECT COUNT(*) FROM reseller_tlds WHERE registry_id=?', [$one]) === '0', 'Permission removal retained');
    request($base . '/tlds', 'POST', ['action' => 'tld.save', 'tld' => 'unused', 'enabled' => '1']);
    check(request($base . '/tlds', 'POST', ['action' => 'tld.delete', 'tld' => 'unused'])->getStatusCode() === 422, 'TLD removal still requires confirmation');
    check(request($base . '/tlds', 'POST', ['action' => 'tld.delete', 'tld' => 'unused', 'confirmed' => '1'])->getStatusCode() === 303 && value("SELECT COUNT(*) FROM registry_tlds WHERE registry_id=? AND tld='unused'", [$one]) === '0', 'Unused TLD removal retained');
    check(request($base . '/tlds', 'POST', ['action' => 'registry.status', 'enabled' => '0'])->getStatusCode() === 422, 'Subpage action allowlist enforced');
    check(request($base, 'POST', ['action' => 'registry.status', 'enabled' => '0'], false)->getStatusCode() === 403, 'Registry changes require CSRF');
    check(request('/admin/registries/' . Util::uuid() . '/tlds')->getStatusCode() === 404, 'Unknown registry returns 404');
    check(request('/admin/tlds')->getHeaderLine('Location') === '/admin/registries', 'Old TLD URL redirects to new registry workflow');
    // More than 50 suffixes exercise scoped pagination.
    $insert = $pdo->prepare('INSERT INTO registry_tlds (registry_id, tld) VALUES (?, ?)');
    for ($i = 0; $i < 51; ++$i) { $insert->execute([$one, sprintf('zone%02d', $i)]); }
    check(substr_count(table(html($base . '/tlds')), '<tr>') === 50 && str_contains(html($base . '/tlds'), '?page=2'), 'TLD page paginates after 50 records');
    check(substr_count(table(html($base . '/tlds?page=2')), '<tr>') === 2, 'TLD pagination exposes remaining records');
    for ($i = 0; $i < 51; ++$i) { action('tld.access', ['registry_id' => $one, 'reseller_id' => $a, 'tld' => sprintf('zone%02d', $i), 'enabled' => '1']); }
    check(substr_count(table(html($base . '/permissions')), '<tr>') === 50 && substr_count(table(html($base . '/permissions?page=2')), '<tr>') === 1, 'TLD permission table paginates all registry records');
    $pageTwoToggle = request($base . '/permissions?page=2', 'POST', ['action' => 'tld.access', 'reseller_id' => $a, 'tld' => 'zone50', 'enabled' => '0']);
    check($pageTwoToggle->getHeaderLine('Location') === $base . '/permissions?page=2' && str_contains(table(html($base . '/permissions?page=2')), 'Disabled — enable'), 'Permission toggles retain current pagination');
    $insertReseller = $pdo->prepare("INSERT INTO resellers (id, username, password_hash, currency) VALUES (?, ?, ?, 'EUR')");
    for ($i = 0; $i < 51; ++$i) {
        $id = Util::uuid(); $insertReseller->execute([$id, sprintf('paging-account-%02d', $i), $hash]);
        action('registry.access', ['registry_id' => $one, 'reseller_id' => $id, 'enabled' => '1']);
    }
    check(substr_count(table(html($base)), '<tr>') === 50 && substr_count(table(html($base . '?page=2')), '<tr>') === 2, 'Registry access table paginates all permission records');
    for ($i = 0; $i < 51; ++$i) { action('registry.save', ['name' => sprintf('Paging registry %02d', $i), 'profile' => 'generic', 'config_json' => $config]); }
    check(substr_count(table(html('/admin/registries')), '<tr>') === 50 && substr_count(table(html('/admin/registries?page=2')), '<tr>') === 3, 'Main registry table remains paginated');
    // Both new profiles must be selectable, persist on create/update and resolve to the pinned client's implementation.
    foreach (['RO'=>'RoEpp','SI'=>'SiEpp'] as $profile=>$class) {
        check(Namingo\ResellerApi\CommandCatalog::normalizeProfile(strtolower($profile)) === $profile, $profile . ' normalizes for CLI/API configuration');
        check(str_contains(html('/admin/registries'), '>' . $profile . '</option>'), $profile . ' offered in add-registry form');
        check(request('/admin/registries','POST',['action'=>'registry.save','name'=>'Profile '.$profile,'profile'=>$profile,'config_json'=>$config])->getStatusCode()===303, $profile . ' registry can be created');
        $profileId=value('SELECT id FROM registries WHERE name=?',['Profile '.$profile]);
        check(value('SELECT profile FROM registries WHERE id=?',[$profileId])===$profile, $profile . ' persisted on create');
        check(str_contains(html('/admin/registries/'.$profileId),'<option selected>'.$profile.'</option>'), $profile . ' preselected in update form');
        $replacement=$profile==='RO'?'SI':'RO';
        check(request('/admin/registries/'.$profileId,'POST',['action'=>'registry.save','name'=>'Updated profile '.$profile,'profile'=>$replacement,'config_json'=>$config])->getStatusCode()===303
            && value('SELECT profile FROM registries WHERE id=?',[$profileId])===$replacement, $profile . ' registry profile can be updated');
        $client=Pinga\Tembo\EppRegistryFactory::create($profile);
        check(get_class($client)==='Pinga\\Tembo\\Registries\\'.$class, $profile . ' resolves to its registry-specific EPP client');
    }
    check(Composer\InstalledVersions::getPrettyVersion('pinga/tembo')==='v1.1.24', 'Installed EPP client is pinned to 1.1.24');
    $fields=array_map('strval',Namingo\ResellerApi\RegistryForm::fields());
    $fields=array_replace($fields,['registry_fields'=>'1','action'=>'registry.save','name'=>'Field-based registry','profile'=>'generic',
        'epp_host'=>'fields.registry.invalid','epp_username'=>'field-user','epp_password'=>' field-password ','bind_ip'=>'2001:db8::10']);
    check(request('/admin/registries','POST',$fields)->getStatusCode()===303,'Structured form creates registry');
    $fieldId=value("SELECT id FROM registries WHERE name='Field-based registry'");
    $raw=Namingo\ResellerApi\PanelData::rows($pdo,'SELECT * FROM registries WHERE id=?',[$fieldId])[0];
    $saved=json_decode(Crypto::decrypt($raw['config_nonce'],$raw['config_cipher'],'registry-config:'.$fieldId),true);
    check($saved['login']['clID']==='field-user' && $saved['login']['pw']===' field-password ' && $saved['connection']['bindip']==='[2001:db8::10]:0','Structured fields saved in unchanged encrypted JSON format');
    check(!str_contains($raw['config_cipher'],'field-password'),'Structured credentials encrypted');
    $response=request('/admin/registries/'.$fieldId);$edit=(string)$response->getBody();
    check(str_contains($edit,'value="fields.registry.invalid"') && str_contains($edit,'value="field-user"') && str_contains($edit,'value="2001:db8::10"'),'Saved structured values reload for edit');
    check(str_contains($response->getHeaderLine('Cache-Control'),'no-store'),'Credential edit response is not cached');
    check(!str_contains($edit,'name="config_json"') && !str_contains(html('/admin/registries'),'name="config_json"'),'Both forms omit raw JSON textarea');
    check(!str_contains(html('/admin/registries'),'data-registry-ssl-generator'),'SSL generator absent from create registry');
    check(str_contains($edit,'Generate SSL') && str_contains($edit,'/registry-ssl.js'),'SSL generator present only on update registry');
    $beforeCipher=$raw['config_cipher'];$beforeAudit=value('SELECT COUNT(*) FROM audit_events');
    $generate=['action'=>'registry.generate_ssl','registry_debug'=>'1','ca_pem'=>'','client_cert_pem'=>'','client_key_pem'=>'','client_key_passphrase'=>''];
    check(request('/admin/registries/'.$fieldId,'POST',$generate,false)->getStatusCode()===403,'SSL generation requires CSRF');
    check(request('/admin/registries/'.$fieldId,'POST',array_replace($generate,['registry_debug'=>'0']))->getStatusCode()===422,'SSL generation requires debug on');
    check(request('/admin/registries/'.$fieldId,'POST',array_replace($generate,['client_key_pem'=>'existing']))->getStatusCode()===409,'SSL generation refuses populated fields');
    check(request('/admin/registries/'.$fieldId.'/tlds','POST',$generate)->getStatusCode()===422,'Generation unavailable on TLD page');
    $generatedResponse=request('/admin/registries/'.$fieldId,'POST',$generate);
    $generated=json_decode((string)$generatedResponse->getBody(),true);
    check($generatedResponse->getStatusCode()===200 && openssl_x509_check_private_key($generated['client_cert_pem'],$generated['client_key_pem']),'Authenticated generation returns valid PEM pair');
    check(str_contains($generatedResponse->getHeaderLine('Cache-Control'),'no-store'),'Generated private key response not cached');
    check(value('SELECT config_cipher FROM registries WHERE id=?',[$fieldId])===$beforeCipher && value('SELECT COUNT(*) FROM audit_events')===$beforeAudit,'Generation alone writes no registry or audit data');
    $draftFields=array_replace($fields,['registry_debug'=>'1','client_cert_pem'=>$generated['client_cert_pem'],'client_key_pem'=>$generated['client_key_pem']]);
    check(request('/admin/registries/'.$fieldId,'POST',$draftFields)->getStatusCode()===303,'Explicit Save registry stores generated fields');
    $savedRow=Namingo\ResellerApi\PanelData::rows($pdo,'SELECT * FROM registries WHERE id=?',[$fieldId])[0];
    $savedSsl=json_decode(Crypto::decrypt($savedRow['config_nonce'],$savedRow['config_cipher'],'registry-config:'.$fieldId),true);
    check($savedSsl['connection']['client_cert_pem']===trim($generated['client_cert_pem']) && $savedSsl['connection']['client_key_pem']===trim($generated['client_key_pem']),'Generated pair stored only through normal encrypted save');
    $fields['epp_host']='updated.registry.invalid';$fields['registry_debug']='1';$fields['registry_id']=$two;
    check(request('/admin/registries/'.$fieldId,'POST',$fields)->getStatusCode()===303,'Structured edit saves against URL registry');
    $raw=Namingo\ResellerApi\PanelData::rows($pdo,'SELECT * FROM registries WHERE id=?',[$fieldId])[0];
    $saved=json_decode(Crypto::decrypt($raw['config_nonce'],$raw['config_cipher'],'registry-config:'.$fieldId),true);
    check($saved['connection']['host']==='updated.registry.invalid' && !$saved['connection']['verify_peer'] && $saved['connection']['allow_self_signed'],'Structured edit updates only selected registry configuration');
    check(request('/admin/registries','POST',$fields,false)->getStatusCode()===403,'Structured save requires CSRF');
    check(request('/admin/registries/'.$fieldId,'POST',array_replace($fields,['bind_ip'=>'bad-bind']))->getStatusCode()===422,'Invalid structured settings report validation error');
    PanelSession::login(Auth::login($pdo, 'reseller-a', $password, '127.0.0.1'));
    check(request('/admin/registries/'.$fieldId,'POST',$generate)->getStatusCode()===403,'Non-admin cannot generate SSL');
    foreach ([$base, $base . '/tlds', $base . '/permissions'] as $path) {
        check(request($path)->getStatusCode() === 403, 'Non-admin cannot open registry subpage');
        check(request($path, 'POST', ['action' => 'registry.status', 'enabled' => '0'])->getStatusCode() === 403, 'Non-admin cannot mutate registry subpage');
    }
    echo "PASS: $checks registry-management assertions\n";
} finally {
    $pdo = null; $dbAdmin->exec("DROP DATABASE `$db`"); session_destroy();
    foreach (glob($sessionRoot . '/var/panel-sessions/*') ?: [] as $file) { unlink($file); }
    @rmdir($sessionRoot . '/var/panel-sessions'); @rmdir($sessionRoot . '/var'); @rmdir($sessionRoot);
}
