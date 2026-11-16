<?php

declare(strict_types=1);

use Namingo\ResellerApi\{ApiException, DomainParams};

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/DomainParams.php';
$checks = 0;
function check(bool $ok, string $message): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($message); } }
function invalid(string $profile, string $action, array $input, string $code): void {
    try { DomainParams::plan($profile, $action, 'example.com', $input); }
    catch (ApiException $e) { check($e->errorCode === $code, 'Expected ' . $code . ', got ' . $e->errorCode); return; }
    throw new RuntimeException('Invalid plan was accepted.');
}
$input = ['years' => '2', 'registrant' => 'OWNER', 'admin' => 'ADMIN', 'tech' => 'TECH', 'billing' => 'BILL',
    'ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net', 'ipv4_1' => '192.0.2.1', 'ipv6_2' => '2001:db8::2'];
$generic = DomainParams::plan('generic', 'create', 'example.com', $input);
check(array_column($generic, 'command') === ['host.check','host.create','host.check','host.create','domain.create','domain.info'], 'Host checks/creates precede registration and final sync');
check($generic[1]['check'] === 0 && $generic[3]['check'] === 2, 'Each host create depends on its own availability check');
check($generic[3]['params']['ipaddress'] === '2001:db8::2', 'Host-object IPv6 uses the serializer ipaddress field');
$create = $generic[4]['params'];
check($create['period'] === 2 && $create['registrant'] === 'OWNER', 'Term and registrant mapped');
check($create['contacts'] === ['admin'=>'ADMIN','tech'=>'TECH','billing'=>'BILL'], 'Role contacts mapped');
check($create['nss'] === ['ns1.example.net','ns2.example.net'], 'Host-object creation uses string references');
check(strlen($create['authInfoPw']) >= 20, 'Authorization code generated');
$second = DomainParams::plan('generic', 'create', 'other.com', $input);
check($second[4]['params']['authInfoPw'] !== $create['authInfoPw'], 'Bulk members get distinct auth codes');
foreach (['EE','EU','FI','GE','HR','IT','LT','LV'] as $profile) {
    $plan = DomainParams::plan($profile, 'create', 'example.com', $input);
    check(array_column($plan, 'command') === ['domain.create','domain.info'], $profile . ' does not create host objects');
    check($plan[0]['params']['nss'][0]['hostName'] === 'ns1.example.net', $profile . ' uses host attributes');
    check($plan[0]['params']['nss'][1]['ipv6'] === '2001:db8::2', $profile . ' retains IPv6 glue');
}
$ds = ['keyTag_1'=>'1234','alg_1'=>'13','digestType_1'=>'2','digest_1'=>str_repeat('AB',32)];
$secure = DomainParams::plan('generic', 'create', 'example.com', $input + $ds);
check($secure[4]['command'] === 'domain.create_dnssec' && $secure[4]['params']['dnssec_records'] === 1, 'DS selects DNSSEC registration');
$update = DomainParams::plan('generic', 'nameservers', 'example.com', $input + ['replace_nameservers'=>'yes']);
check($update[4]['command'] === 'domain.update_ns' && $update[4]['params']['ns2'] === 'ns2.example.net', 'Object nameserver update uses ns1–ns5');
$attr = DomainParams::plan('EU', 'nameservers', 'example.eu', $input + ['replace_nameservers'=>'yes']);
check($attr[0]['params']['nss'][0]['hostName'] === 'ns1.example.net', 'Attribute nameserver update uses nss');
$registrant = DomainParams::plan('generic', 'contact', 'example.com', ['contacttype'=>'registrant','new_contactid'=>'NEW']);
check($registrant[0]['command'] === 'domain.update_contact' && $registrant[0]['params']['contacttype'] === 'registrant', 'Registrant change uses the exposed API command');
$remove = DomainParams::plan('generic', 'contact', 'example.com', ['contacttype'=>'billing','old_contactid'=>'OLD']);
check($remove[0]['params']['new_contactid'] === '', 'Contact removal retains explicit blank new ID for the native serializer');
$renew = DomainParams::plan('generic', 'renew', 'example.com', ['years'=>'3','native'=>'{"domainname":"other.com","regperiod":9}']);
check($renew[0]['params']['domainname'] === 'example.com' && $renew[0]['params']['regperiod'] === 3, 'Native parameters cannot retarget or change the selected renewal term');
$auth = DomainParams::plan('generic', 'authinfo', 'example.com', ['authInfo'=>'NewSecret!123']);
check($auth[0]['command'] === 'domain.update_authinfo' && $auth[0]['params']['authInfo'] === 'NewSecret!123', 'Authorization update mapped');
$dns = DomainParams::plan('generic', 'dnssec', 'example.com', $ds + ['dnssec_action'=>'rem']);
check($dns[0]['command'] === 'domain.update_dnssec' && $dns[0]['params']['command'] === 'rem', 'DNSSEC removal mapped');
$delete = DomainParams::plan('generic', 'delete', 'example.com', ['confirm'=>'yes']);
check(count($delete) === 1 && $delete[0]['command'] === 'domain.delete', 'No info request follows successful deletion');
$fred = DomainParams::plan('FRED', 'create', 'example.cz', $input);
check($fred[0]['command'] === 'host.create' && isset($fred[0]['params']['nsid']) && $fred[1]['params']['nsid'] === $fred[0]['params']['nsid'], 'FRED creates an NSSET before registering with it');
$existing = DomainParams::plan('FRED', 'create', 'example.cz', ['native'=>'{"nsid":"OWNED-NSSET"}']);
check($existing[0]['command'] === 'domain.create' && $existing[0]['params']['nsid'] === 'OWNED-NSSET', 'An existing NSSET is referenced without creating another');
invalid('generic','renew',['years'=>'0'],'invalid_period');
invalid('generic','delete',[],'confirmation_required');
invalid('generic','nameservers',$input,'confirmation_required');
invalid('generic','create',$input + ['ns3'=>'ns1.example.net'],'duplicate_host');
invalid('generic','create',['ns1'=>'ns.example.net','ipv4_1'=>'bad'],'invalid_host');
invalid('generic','create',['ns1'=>'ns.example.net','ipv4_1'=>'192.0.2.1','ipv6_1'=>'2001:db8::1'],'unsupported_host_addresses');
invalid('generic','create',['native'=>'[]'],'invalid_params');
invalid('generic','create',['native'=>'{"broken"'],'invalid_params');
invalid('generic','create',['keyTag_1'=>'1'],'invalid_dnssec');
invalid('generic','dnssec',$ds + ['dnssec_action'=>'addrem'],'invalid_dnssec');
invalid('FRED','nameservers',$input + ['replace_nameservers'=>'yes'],'unsupported_profile_command');
invalid('IS','create',$input,'missing_parameter');
$iceland = DomainParams::plan('IS', 'create', 'example.is', $input + ['native'=>'{"domain_card_id":"card","domain_card_cvc":"123"}']);
check($iceland[4]['params']['domain_card_id'] === 'card', 'Profile-specific native required fields are retained');
echo "PASS: {$checks} domain-parameter assertions\n";
