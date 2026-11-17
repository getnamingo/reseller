<?php

declare(strict_types=1);

use Namingo\ResellerApi\{ApiException,RegistryForm,Util};
require dirname(__DIR__).'/vendor/autoload.php';
$checks=0;
function check(bool $ok,string $message):void { global $checks;++$checks;if(!$ok){throw new RuntimeException($message);} }
function invalid(array $input,string $message):void {
    try { RegistryForm::config('generic',$input); }
    catch(ApiException $e){check($e->httpStatus===422,$message);return;}
    throw new RuntimeException($message);
}
$fields=array_map('strval',RegistryForm::fields());
check($fields['epp_port']==='700' && $fields['epp_timeout']==='30' && $fields['epp_tls']==='1.3' && $fields['epp_prefix']==='api' && $fields['registry_debug']==='0','New registry defaults');
$example=json_decode(file_get_contents(dirname(__DIR__).'/deploy/registry.example.json'),true);
check(preg_split('/,\s*/',$fields['login_objects'])===$example['loginObjects'] && preg_split('/,\s*/',$fields['login_extensions'])===$example['loginExtensions'],'Login URI defaults match example JSON');
$fields=array_replace($fields,['epp_host'=>'registry.invalid','epp_username'=>'registry-user','epp_password'=>' password with spaces ']);
$config=RegistryForm::config('generic',$fields);
check($config['login']['clID']==='registry-user' && $config['login']['pw']===' password with spaces ','Login field mapping preserves passwords exactly');
check($config['connection']['port']===700 && $config['connection']['timeout']===30,'Port and timeout stored as integers');
check($config['connection']['verify_peer'] && $config['connection']['verify_peer_name'] && !$config['connection']['allow_self_signed'],'Debug off verifies certificates');
check($config['connection']['ca_pem']===null && $config['connection']['client_cert_pem']===null && $config['connection']['client_key_pem']===null && $config['connection']['client_key_passphrase']==='','Empty certificate fields stored as null and passphrase as empty string');
check($config['connection']['bind']===false && $config['connection']['bindip']==='','Empty bind disabled');
$debug=RegistryForm::config('generic',array_replace($fields,['registry_debug'=>'1','epp_tls'=>'1.2']));
check(!$debug['connection']['verify_peer'] && !$debug['connection']['verify_peer_name'] && $debug['connection']['allow_self_signed'] && $debug['connection']['tls']==='1.2','Debug on and alternate TLS mapping');
foreach(['192.0.2.1'=>'192.0.2.1:0','192.0.2.1:700'=>'192.0.2.1:0','2001:db8::1'=>'[2001:db8::1]:0','[2001:db8::1]:700'=>'[2001:db8::1]:0','[::1]'=>'[::1]:0','2001:db8::1:0'=>'[2001:db8::1:0]:0'] as $ip=>$expected){
    $bound=RegistryForm::config('generic',array_replace($fields,['bind_ip'=>$ip]));
    check($bound['connection']['bind'] && $bound['connection']['bindip']===$expected,'Bind normalization '.$ip);
    check(RegistryForm::fields($bound)['bind_ip']===trim(substr($expected,0,-2),'[]'),'Stored bind reloads as IP');
}
$uris=RegistryForm::config('generic',array_replace($fields,['login_objects'=>"urn:first, urn:second\r\nurn:third,,",'login_extensions'=>'']));
check($uris['loginObjects']===['urn:first','urn:second','urn:third'] && $uris['loginExtensions']===[],'Comma/newline URI lists and explicit empty extensions');
$existing=$config;$existing['customOption']='preserve';$existing['connection']['customTransport']='preserve';$existing['login']['customLogin']='preserve';
$roundtrip=RegistryForm::config('generic',array_map('strval',RegistryForm::fields($existing)),$existing);
check($roundtrip===$existing,'Edit round trip preserves existing configuration and additional fields');
$omitted=$existing;unset($omitted['loginObjects'],$omitted['loginExtensions']);
$unchanged=RegistryForm::config('generic',array_map('strval',RegistryForm::fields($omitted)),$omitted);
check(!array_key_exists('loginObjects',$unchanged) && !array_key_exists('loginExtensions',$unchanged),'Absent client profile overrides remain absent');
$nullOverrides=array_replace($existing,['loginObjects'=>null,'loginExtensions'=>null]);
$nullRoundTrip=RegistryForm::config('generic',array_map('strval',RegistryForm::fields($nullOverrides)),$nullOverrides);
check($nullRoundTrip===$nullOverrides,'Null login URI overrides remain null on unchanged form save');
check(!isset($nullRoundTrip['loginObjects']) && !isset($nullRoundTrip['loginExtensions']),'Null URI overrides still leave worker profile defaults intact');
$replacedNull=RegistryForm::config('generic',array_replace($fields,['login_objects'=>'urn:new-object','login_extensions'=>'urn:new-extension']),$nullOverrides);
check($replacedNull['loginObjects']===['urn:new-object'] && $replacedNull['loginExtensions']===['urn:new-extension'],'Entered URIs replace null overrides');
$emptyOverrides=array_replace($existing,['loginObjects'=>[],'loginExtensions'=>[]]);
$emptyRoundTrip=RegistryForm::config('generic',array_map('strval',RegistryForm::fields($emptyOverrides)),$emptyOverrides);
check($emptyRoundTrip['loginObjects']===[] && $emptyRoundTrip['loginExtensions']===[],'Explicit empty URI overrides remain empty arrays');

invalid(array_replace($fields,['bind_ip'=>'invalid.example:0']),'Invalid bind rejected');
invalid(array_replace($fields,['epp_port'=>'65536']),'Invalid port rejected');
invalid(array_replace($fields,['registry_debug'=>'yes']),'Invalid debug option rejected');
invalid(array_replace($fields,['epp_tls'=>'1.1']),'Unsupported TLS rejected');
invalid(array_replace($fields,['login_objects'=>'urn:bad space']),'Invalid URI rejected');
$key=openssl_pkey_new(['private_key_bits'=>2048]);$csr=openssl_csr_new(['commonName'=>'registry.invalid'],$key);$certificate=openssl_csr_sign($csr,null,$key,1);
openssl_x509_export($certificate,$pem);openssl_pkey_export($key,$keyPem,' key passphrase ');
$withPem=RegistryForm::config('generic',array_replace($fields,['ca_pem'=>str_replace("\n","\r\n",$pem),'client_cert_pem'=>$pem,'client_key_pem'=>$keyPem,'client_key_passphrase'=>' key passphrase ']));
$json=Util::json($withPem);
check(str_contains($json,'\\n') && !str_contains($json,"\r") && !str_contains($json,"\n"),'PEMs serialize into JSON escaped newlines');
check(str_contains(RegistryForm::fields($withPem)['client_cert_pem'],"\n") && $withPem['connection']['client_key_passphrase']===' key passphrase ','PEMs reload as full multiline text and passphrase preserved');
$cleared=RegistryForm::config('generic',$fields,$withPem);
check($cleared['connection']['client_cert_pem']===null && $cleared['connection']['client_key_pem']===null && $cleared['connection']['ca_pem']===null,'Clearing certificate fields removes stored PEMs');
invalid(array_replace($fields,['client_cert_pem'=>$pem]),'Client certificate without key rejected');
$draftInput=['registry_debug'=>'1','ca_pem'=>'','client_cert_pem'=>'','client_key_pem'=>'','client_key_passphrase'=>''];
$draft=RegistryForm::generateSsl($draftInput);
$draftKey=openssl_pkey_get_private($draft['client_key_pem']);$draftCert=openssl_x509_read($draft['client_cert_pem']);
$details=openssl_pkey_get_details($draftKey);$certificateInfo=openssl_x509_parse($draftCert);
check($details['type']===OPENSSL_KEYTYPE_RSA && $details['bits']===2048,'Generator creates RSA 2048-bit key');
check(openssl_x509_check_private_key($draftCert,$draftKey),'Generated certificate matches private key');
check(openssl_x509_verify($draftCert,$details['key'])===1 && $certificateInfo['issuer']===$certificateInfo['subject'],'Generated certificate is self-signed');
check($certificateInfo['validTo_time_t']-$certificateInfo['validFrom_time_t']===365*86400,'Generated certificate valid for 365 days');
check(str_contains(strtolower($certificateInfo['signatureTypeSN']),'sha256'),'Generated certificate signed with SHA-256');
check(!str_contains($draft['client_key_pem'],'ENCRYPTED'),'Default generated key needs no passphrase');
$encrypted=RegistryForm::generateSsl(array_replace($draftInput,['client_key_passphrase'=>' exact passphrase ']));
check(openssl_pkey_get_private($encrypted['client_key_pem'],' exact passphrase ')!==false,'Generator respects current key passphrase');
foreach (['registry_debug'=>'0','ca_pem'=>'existing CA','client_cert_pem'=>'existing certificate','client_key_pem'=>'existing key'] as $field=>$value) {
    try { RegistryForm::generateSsl(array_replace($draftInput,[$field=>$value]));check(false,'Ineligible generation must fail'); }
    catch(ApiException $e){check(in_array($e->httpStatus,[409,422],true),'Generator requires Debug and empty SSL: '.$field);}
}
echo "PASS: {$checks} registry-form assertions\n";
