<?php

/*
 * Namingo Reseller
 *
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the PolyForm Noncommercial License 1.0.0.
 * Commercial use requires separate written authorization.
 * For licensing inquiries, contact hello@terbora.com.
 *
 * See the LICENSE file distributed with this software
 * for the full license terms.
 *
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

declare(strict_types=1);

use Namingo\ResellerApi\{ApiException,CommandCatalog,Crypto,Db,EppWorker,Ledger,Util};
use Pinga\Tembo\Registries\GenericEpp;
require dirname(__DIR__) . '/vendor/autoload.php';
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function invalid(string $command, array $params, string $profile, string $code = 'missing_parameter', ?string $field = null): void {
    try { CommandCatalog::validateParams($command, $params, $profile); }
    catch (ApiException $e) {
        check($e->httpStatus === 422 && $e->errorCode === $code && ($field === null || str_contains($e->getMessage(), $field)), "Expected $profile $command $code $field; got {$e->getMessage()}"); return;
    }
    throw new RuntimeException("Accepted invalid $profile $command");
}
$create = ['domainname'=>'example.com','period'=>1,'authInfoPw'=>'secret'];
invalid('domain.create',array_diff_key($create,['authInfoPw'=>true]),'generic','missing_parameter','authInfoPw');
foreach ([null, [], false, ''] as $value) { invalid('domain.create',array_replace($create,['authInfoPw'=>$value]),'generic','invalid_parameter'); }
foreach (['generic','VRSN','MX'] as $profile) { CommandCatalog::validateParams('domain.create',$create,$profile); ++$checks; }
CommandCatalog::validateParams('domain.create',['domainname'=>'example.com','period'=>1],'GR'); ++$checks;
invalid('domain.create',$create,'FRED','missing_parameter','nsid');
invalid('domain.create',$create,'IS','missing_parameter','domain_card_id');
invalid('domain.create',$create,'FI','missing_parameter','registrant');
invalid('domain.renew',['domainname'=>'example.com','regperiod'=>1],'IS','missing_parameter','domain_card_id');
$contact=['type'=>'loc','id'=>'contact1','firstname'=>'First','lastname'=>'Last','companyname'=>'','address1'=>'Street','address2'=>'','city'=>'City','state'=>'','postcode'=>'1000','country'=>'BG','fullphonenumber'=>'+359.123456789','email'=>'test@example.com','authInfoPw'=>'secret'];
CommandCatalog::validateParams('contact.create',$contact,'generic'); ++$checks;
foreach (['EE'=>'ident_type','GE'=>'nin','HR'=>'nin_type','IS'=>'isType','LV'=>'vatNr','PT'=>'validated'] as $profile=>$field) {
    invalid('contact.create',$contact,$profile,'missing_parameter',$field);
}
invalid('contact.create',array_diff_key($contact,['email'=>true]),'generic','missing_parameter','email');
invalid('contact.update',['id'=>'contact1'],'generic','missing_parameter','type');
invalid('contact.create',$contact,'VRSN','unsupported_profile_command');
$transfer=['domainname'=>'example.com','op'=>'request','years'=>1,'authInfoPw'=>'secret'];
foreach (['FR'=>'admin','GR'=>'registrant','NO'=>'trans_pers','EE'=>'ident_pdf'] as $profile=>$field) { invalid('domain.transfer',$transfer,$profile,'missing_parameter',$field); }
invalid('domain.transfer',['domainname'=>'example.com','op'=>'invalid'],'generic','invalid_parameter');
invalid('domain.transfer',['domainname'=>'example.com','op'=>'query'],'IT','missing_parameter','authInfoPw');
CommandCatalog::validateParams('domain.transfer',['domainname'=>'example.com','op'=>'query'],'generic'); ++$checks;
invalid('domain.transfer',['domainname'=>'example.com','op'=>'cancel'],'LT','invalid_parameter');
invalid('domain.transfer',$transfer,'IS','unsupported_profile_command');
invalid('host.create',['hostname'=>'ns.example.com'],'FRED','missing_parameter','ipaddress');
invalid('host.update',['hostname'=>'ns.example.com'],'generic','invalid_parameter');
invalid('host.update',['hostname'=>'ns.example.com'],'FRED','missing_parameter','newhostname');
invalid('host.create',['hostname'=>'ns.example.com'],'EU','unsupported_profile_command');
CommandCatalog::validateParams('host.create',['hostname'=>'nsgroup1','ns'=>['ns1.example.com','ns2.example.com']],'LT'); ++$checks;
invalid('host.update',['hostname'=>'nsgroup1','ns'=>[['invalid']]],'LT','invalid_parameter');
invalid('domain.create_dnssec',$create+['dnssec_records'=>1],'generic','missing_parameter','keyTag_1');
$dnssec=$create+['dnssec_records'=>1,'keyTag_1'=>1,'alg_1'=>13,'digestType_1'=>2,'digest_1'=>'0123'];
CommandCatalog::validateParams('domain.create_dnssec',$dnssec,'generic'); ++$checks;
invalid('domain.create_dnssec',array_replace($dnssec,['dnssec_records'=>2]),'generic','missing_parameter','keyTag_2');
invalid('domain.create_dnssec',array_replace($dnssec,['dnssec_records'=>3]),'generic','invalid_parameter');
invalid('domain.update_dnssec',['domainname'=>'example.com','command'=>'bad'],'generic','invalid_parameter');
invalid('domain.update_contact',['domainname'=>'example.com','contacttype'=>'admin','new_contactid'=>'contact2'],'generic','missing_parameter','old_contactid');
invalid('domain.create',$create+['nss'=>[['ipv4'=>'192.0.2.1']]],'generic','missing_parameter','hostName');
invalid('domain.create',$create+['contacts'=>'contact1'],'generic','invalid_parameter');
invalid('domain.create',$create+['contacts'=>['tech'=>['contact1']]],'generic','invalid_parameter');
invalid('domain.create',$create+['nss'=>[['hostName'=>['ns.example.com']]]],'generic','invalid_parameter');
// Reproduce the client-side failure without permitting a network write.
$spy=new class extends GenericEpp {
    public int $writes=0;
    public function writeRequest($xml) { ++$this->writes; throw new Error('Unexpected network write'); }
};
(new ReflectionProperty($spy,'isLoggedIn'))->setValue($spy,true);
set_error_handler(static function(int $severity,string $message): never { throw new ErrorException($message,0,$severity); });
try { $result=$spy->domainCreate(['domainname'=>'example.com','period'=>1]); }
finally { restore_error_handler(); }
check(isset($result['error']) && str_contains($result['error'],'authInfoPw') && $spy->writes===0,'Missing authInfoPw fails locally with zero writes');

$dsn=getenv('TEST_MYSQL_DSN')?:'mysql:host=127.0.0.1;port=3306';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>true];
$user=getenv('TEST_MYSQL_USER')?:'root';$password=getenv('TEST_MYSQL_PASSWORD')?:'';
$admin=new PDO($dsn,$user,$password,$options);$db='reseller_validation_'.bin2hex(random_bytes(6));$admin->exec("CREATE DATABASE `$db`");$pdo=new PDO($dsn.';dbname='.$db,$user,$password,$options);
$_ENV['APP_MASTER_KEY']='base64:'.base64_encode(random_bytes(32));
try {
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
    $reseller=Util::uuid();$registry=Util::uuid();
    $pdo->prepare("INSERT INTO resellers(id,username,password_hash,currency) VALUES(?,'validation','unused','EUR')")->execute([$reseller]);
    $pdo->prepare("INSERT INTO wallets(reseller_id,currency,available_balance) VALUES(?,'EUR',100)")->execute([$reseller]);
    [$nonce,$cipher]=Crypto::encrypt(Util::json(['connection'=>['host'=>'registry.invalid'],'login'=>['clID'=>'shared','pw'=>'upstream-secret']]),'registry-config:'.$registry);
    $pdo->prepare("INSERT INTO registries(id,name,profile,config_nonce,config_cipher) VALUES(?,'validation','generic',?,?)")->execute([$registry,$nonce,$cipher]);
    $pdo->prepare('INSERT INTO reseller_registries(reseller_id,registry_id) VALUES(?,?)')->execute([$reseller,$registry]);
    $pdo->prepare("INSERT INTO registry_tlds(registry_id,tld) VALUES(?,'com')")->execute([$registry]);
    $pdo->prepare("INSERT INTO reseller_tlds(reseller_id,registry_id,tld) VALUES(?,?,'com')")->execute([$reseller,$registry]);
    $pdo->prepare("INSERT INTO registry_prices(registry_id,command,tld,period,amount,currency) VALUES(?,'domain.create','com',1,10,'EUR')")->execute([$registry]);
    foreach ([['domainname'=>'example.com','period'=>1],array_replace($create,['authInfoPw'=>null]),$create+['nss'=>[['ipv4'=>'192.0.2.1']]]] as $params) {
        try { Ledger::createJob($pdo,$reseller,$registry,'domain.create',Util::uuid(),$params);throw new RuntimeException('Invalid registration queued'); }
        catch (ApiException $e) { check($e->httpStatus===422,'Submission rejects invalid parameters with 422'); }
        check((int)$pdo->query('SELECT COUNT(*) FROM epp_jobs')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM object_ownership')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn()===0,'Rejected submission creates no job, ownership or ledger entry');
        check($pdo->query('SELECT available_balance,held_balance FROM wallets')->fetch()===['available_balance'=>'100.0000','held_balance'=>'0.0000'],'Rejected submission never retains funds');
    }
    // A job created by an older version must also fail before connection, releasing its hold.
    $job=Ledger::createJob($pdo,$reseller,$registry,'domain.create',Util::uuid(),$create);
    [$nonce,$cipher]=Crypto::encrypt(Util::json(['params'=>['domainname'=>'example.com','period'=>1]]),'job-request:'.$job['job_id']);
    $pdo->prepare('UPDATE epp_jobs SET request_nonce=?,request_cipher=? WHERE id=?')->execute([$nonce,$cipher,$job['job_id']]);
    (new ReflectionProperty(Db::class,'pdo'))->setValue(null,$pdo);
    $processed=EppWorker::process($job['job_id']);$finished=Ledger::fetchJob($pdo,$job['job_id']);
    check($processed['status']==='rejected' && $finished['result']['error']==='missing_parameter','Worker rejects legacy missing fields before any connection');
    check((int)$pdo->query('SELECT COUNT(*) FROM object_ownership')->fetchColumn()===0,'Legacy invalid job releases ownership reservation');
    check($pdo->query('SELECT available_balance,held_balance FROM wallets')->fetch()===['available_balance'=>'100.0000','held_balance'=>'0.0000'],'Legacy invalid job releases wallet hold');
    // Italian follow-ups require authInfoPw, supplied by the server-linked request.
    $pdo->prepare("UPDATE registries SET profile='IT' WHERE id=?")->execute([$registry]);
    $pdo->prepare("INSERT INTO registry_prices(registry_id,command,tld,period,amount,currency) VALUES(?,'domain.transfer','com',1,12,'EUR'),(?,'domain.transfer','',0,0,'EUR')")->execute([$registry,$registry]);
    $parent=Ledger::createJob($pdo,$reseller,$registry,'domain.transfer',Util::uuid(),$transfer);
    Ledger::completeJob($pdo,$parent['job_id'],'pending',['code'=>1001],1001);
    $child=Ledger::createJob($pdo,$reseller,$registry,'domain.transfer',Util::uuid(),['domainname'=>'example.com','op'=>'query']);
    check(Ledger::fetchJob($pdo,$child['job_id'])['parent_job_uuid']===$parent['job_id'],'Profile-required follow-up fields are inherited from authorized parent');
    check(!array_key_exists('authInfoPw', Namingo\ResellerApi\Ownership::params($pdo->query("SELECT * FROM epp_jobs WHERE id='".$child['job_id']."'")->fetch())), 'Inherited authInfo is not copied into the submitted child parameters');
    echo "PASS: {$checks} command-validation assertions\n";
} finally { Db::reset();$pdo=null;$admin->exec("DROP DATABASE `$db`"); }
