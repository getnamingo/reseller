<?php

declare(strict_types=1);

use Namingo\ResellerApi\{Access,Admin,ApiException,Auth,CommandCatalog,Crypto,Ledger,Queue,Util};
require dirname(__DIR__) . '/vendor/autoload.php';
$dsn=getenv('TEST_MYSQL_DSN')?:'mysql:host=127.0.0.1;port=3306';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>true];
$user=getenv('TEST_MYSQL_USER')?:'root';$password=getenv('TEST_MYSQL_PASSWORD')?:'';
$admin=new PDO($dsn,$user,$password,$options);$db='reseller_admin_editing_'.bin2hex(random_bytes(6));$admin->exec("CREATE DATABASE `$db`");
$pdo=new PDO($dsn.';dbname='.$db,$user,$password,$options);$other=new PDO($dsn.';dbname='.$db,$user,$password,$options);
$_ENV['APP_MASTER_KEY']='base64:'.base64_encode(random_bytes(32));
$checks=0;
function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function denied(string $code,callable $call):void{try{$call();}catch(ApiException $e){check($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);}
function cli(array $args,string $stdin=''):array{
    global $dsn,$db,$user,$password;
    $env=getenv();$env['APP_MASTER_KEY']=$_ENV['APP_MASTER_KEY'];$env['APP_ENV']='local';$env['DB_NAME']=$db;$env['DB_USER']=$user;$env['DB_PASSWORD']=$password?:'unused';
    foreach(explode(';',substr($dsn,6)) as $part){[$key,$value]=array_pad(explode('=',$part,2),2,'');if(isset(['host'=>1,'port'=>1,'unix_socket'=>1][$key]))$env[['host'=>'DB_HOST','port'=>'DB_PORT','unix_socket'=>'DB_SOCKET'][$key]]=$value;}
    $process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),dirname(__DIR__).'/bin/manage.php',...$args],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname(__DIR__),$env);
    fwrite($pipes[0],$stdin);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return[proc_close($process),$out,$error];
}
try{
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
    $a=Util::uuid();$b=Util::uuid();$root=Util::uuid();$registry=Util::uuid();$pw='correct horse battery staple';$hash=password_hash($pw,PASSWORD_ARGON2ID);
    foreach([$a=>'original-user',$b=>'other-user',$root=>'root-user'] as $id=>$name){
        $pdo->prepare('INSERT INTO resellers(id,username,password_hash,currency,role) VALUES(?,?,?,\'EUR\',?)')->execute([$id,$name,$hash,(int)($id!==$root)]);
        $pdo->prepare("INSERT INTO wallets(reseller_id,currency,available_balance) VALUES(?,'EUR',100)")->execute([$id]);
    }
    // Upgrade a v4 account table and verify the real migration command is repeatable.
    $pdo->exec("ALTER TABLE resellers DROP COLUMN display_name,DROP COLUMN company,DROP COLUMN email,DROP COLUMN phone,DROP COLUMN address,DROP COLUMN country_code");
    $pdo->exec("DELETE FROM schema_migrations WHERE version='005_account_details'");
    foreach([1,2] as $_){$result=cli(['migrate']);check($result[0]===0,'Migration command succeeds: '.$result[2]);}
    check((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===5,'v4-to-v5 migration records version once');
    $token=Auth::login($pdo,'original-user',$pw,'127.0.0.1')['access_token'];
    $details=['display_name'=>'Илия','company'=>'Example Ltd','email'=>'contact@example.com','phone'=>'+359 123 456','address'=>"Street 1\nSofia 1000",'country_code'=>'bg'];
    Admin::perform($pdo,$root,'user.update',['reseller_id'=>$a]+$details,'127.0.0.1');
    check(Auth::authenticate($pdo,'Bearer '.$token)['id']===$a,'Contact-only edits preserve sessions');
    $row=$pdo->query("SELECT display_name,company,email,phone,address,country_code FROM resellers WHERE id='$a'")->fetch();
    check($row===array_replace($details,['country_code'=>'BG']),'General account details save and country code normalizes');
    Admin::updateAccount($pdo,$a,['email'=>'','phone'=>'']);
    check($pdo->query("SELECT email,phone FROM resellers WHERE id='$a'")->fetch()===['email'=>'','phone'=>''],'Optional contact fields can be cleared');
    denied('admin_required',fn()=>Admin::perform($pdo,$b,'user.update',['reseller_id'=>$a,'username'=>'hijacked'],'127.0.0.1'));
    denied('invalid_account',fn()=>Admin::updateAccount($pdo,$a,['email'=>'not-an-email']));
    denied('invalid_account',fn()=>Admin::updateAccount($pdo,$a,['country_code'=>'BGR']));
    denied('invalid_account',fn()=>Admin::updateAccount($pdo,$a,['display_name'=>str_repeat('x',256)]));
    denied('invalid_account',fn()=>Admin::updateAccount($pdo,$a,['phone'=>[]]));
    denied('invalid_account_fields',fn()=>Admin::updateAccount($pdo,$a,['currency'=>'USD']));
    denied('user_not_found',fn()=>Admin::updateAccount($pdo,Util::uuid(),['username'=>'missing-user']));
    denied('username_exists',fn()=>Admin::updateAccount($pdo,$a,['username'=>'other-user','company'=>'Must roll back']));
    check($pdo->query("SELECT company FROM resellers WHERE id='$a'")->fetchColumn()==='Example Ltd','Duplicate username rolls back all account changes');
    $result=cli(['user:update',$a,'-'],Util::json(['username'=>'renamed-user','company'=>'Updated Ltd']));
    check($result[0]===0,'CLI edits username and account details: '.$result[2]);
    denied('unauthorized',fn()=>Auth::authenticate($pdo,'Bearer '.$token));
    denied('invalid_credentials',fn()=>Auth::login($pdo,'original-user',$pw,'127.0.0.1'));
    $renamedLogin=Auth::login($pdo,'renamed-user',$pw,'127.0.0.1');
    check($renamedLogin['reseller_uuid']===$a,'Existing password works with new username');
    Admin::updateAccount($pdo,$a,['username'=>'renamed-user']);
    check(Auth::authenticate($pdo,'Bearer '.$renamedLogin['access_token'])['id']===$a,'No-op username save preserves valid sessions');
    check($pdo->query("SELECT password_hash FROM resellers WHERE id='$a'")->fetchColumn()===$hash,'Account editing preserves password hash');
    check($pdo->query("SELECT currency,available_balance,held_balance FROM wallets WHERE reseller_id='$a'")->fetch()===['currency'=>'EUR','available_balance'=>'100.0000','held_balance'=>'0.0000'],'Account editing preserves wallet identity and balances');
    $result=cli(['user:update',$a,'-'],Util::json(['currency'=>'USD']));check($result[0]===1,'CLI rejects currency change');
    [$nonce,$cipher]=Crypto::encrypt(Util::json(['connection'=>['host'=>'registry.invalid'],'login'=>['clID'=>'shared','pw'=>'secret']]),'registry-config:'.$registry);
    $pdo->prepare("INSERT INTO registries(id,name,profile,config_nonce,config_cipher) VALUES(?,'test','generic',?,?)")->execute([$registry,$nonce,$cipher]);
    foreach([$a,$b] as $id)$pdo->prepare('INSERT INTO reseller_registries(reseller_id,registry_id) VALUES(?,?)')->execute([$id,$registry]);
    foreach(['com','uk','co.uk','unused'] as $tld)Admin::perform($pdo,$root,'tld.save',['registry_id'=>$registry,'tld'=>$tld,'enabled'=>'1'],'127.0.0.1');
    foreach(['com','uk','co.uk','unused'] as $tld)foreach([$a,$b] as $id)Admin::perform($pdo,$root,'tld.access',['registry_id'=>$registry,'reseller_id'=>$id,'tld'=>$tld,'enabled'=>'1'],'127.0.0.1');
    foreach([CommandCatalog::GLOBAL_PRICE_UUID,$a] as $scope)$pdo->prepare("INSERT INTO registry_prices(registry_id,reseller_id,command,tld,period,amount,currency) VALUES(?,?,'domain.create','unused',1,10,'EUR')")->execute([$registry,$scope]);
    $result=cli(['price:set',$registry,'domain.check','0','EUR']);
    check($result[0]===1 && str_contains($result[2],'Prices may only be set'), 'CLI rejects unsupported price commands');
    $result=cli(['price:set',$registry,'domain.create','5','EUR','global','com','1']);
    check($result[0]===0, 'CLI saves a supported price through shared validation');
    $result=cli(['price:set',$registry,'domain.create','5','EUR','global','missing','1']);
    check($result[0]===1 && str_contains($result[2],'Add this TLD'), 'CLI rejects unknown TLD pricing');
    $result=cli(['price:set',$registry,'domain.restore','5','EUR','global','com','1']);
    check($result[0]===1, 'CLI rejects term pricing for restore');
    $pdo->prepare("INSERT INTO registry_prices(registry_id,command,amount,currency) VALUES(?,'domain.check',0,'EUR')")->execute([$registry]);
    Admin::perform($pdo,$root,'tld.unassign',['registry_id'=>$registry,'reseller_id'=>$a,'tld'=>'co.uk'],'127.0.0.1');
    denied('tld_not_permitted',fn()=>Access::domains($pdo,$registry,$a,'domain.check',['domains'=>['example.co.uk']]));
    Access::domains($pdo,$registry,$b,'domain.check',['domains'=>['example.co.uk']]);++$checks;
    $result=cli(['tld:unassign',$b,$registry,'co.uk']);check($result[0]===0,'CLI removes a reseller TLD assignment');
    denied('tld_not_permitted',fn()=>Access::domains($pdo,$registry,$b,'domain.check',['domains'=>['example.co.uk']]));
    $pdo->prepare("INSERT INTO object_ownership(registry_id,reseller_id,object_type,object_name,active,domain_state) VALUES(?,?,'domain','retained.co.uk',1,'deleted')")->execute([$registry,$a]);
    denied('tld_in_use',fn()=>Admin::deleteTld($pdo,$registry,'co.uk'));
    Admin::perform($pdo,$root,'tld.save',['registry_id'=>$registry,'tld'=>'co.uk','enabled'=>'0'],'127.0.0.1');
    denied('tld_not_permitted',fn()=>Access::domains($pdo,$registry,$a,'domain.check',['domains'=>['example.co.uk']]));
    check((int)$pdo->query("SELECT COUNT(*) FROM object_ownership WHERE object_name='retained.co.uk'")->fetchColumn()===1,'Disabling a TLD preserves retained domain ownership');
    $job=Ledger::createJob($pdo,$a,$registry,'domain.check',Util::uuid(),['domains'=>['example.com']]);
    foreach(['queued','processing','pending','unknown'] as $status){$pdo->prepare('UPDATE epp_jobs SET status=? WHERE id=?')->execute([$status,$job['job_id']]);denied('tld_in_use',fn()=>Admin::deleteTld($pdo,$registry,'unused'));}
    $pdo->prepare("UPDATE epp_jobs SET status='succeeded' WHERE id=?")->execute([$job['job_id']]);
    $other->prepare('SELECT GET_LOCK(?,0)')->execute([Queue::lockName($registry)]);
    denied('registry_busy',fn()=>Admin::deleteTld($pdo,$registry,'unused'));
    $other->prepare('SELECT RELEASE_LOCK(?)')->execute([Queue::lockName($registry)]);
    // A submission holding the zone row blocks deletion until its reservation commits.
    $other->beginTransaction();$other->prepare("SELECT 1 FROM registry_tlds WHERE registry_id=? AND tld='unused' FOR UPDATE")->execute([$registry]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
    try{Admin::deleteTld($pdo,$registry,'unused');throw new RuntimeException('Delete ignored zone lock');}catch(PDOException $e){check(($e->errorInfo[1]??null)===1205,'Deletion respects submission zone lock');}
    $other->rollBack();
    $result=cli(['tld:delete',$registry,'unused']);check($result[0]===0,'CLI removes unused TLD: '.$result[2]);
    foreach(['registry_tlds','reseller_tlds','registry_prices'] as $table)check((int)$pdo->query("SELECT COUNT(*) FROM $table WHERE registry_id='$registry' AND tld='unused'")->fetchColumn()===0,'TLD removal cleans '.$table);
    check((int)$pdo->query("SELECT COUNT(*) FROM registry_prices WHERE registry_id='$registry' AND tld=''")->fetchColumn()===1,'Registry-wide prices remain configured');
    denied('unknown_tld',fn()=>Admin::deleteTld($pdo,$registry,'unused'));
    Admin::perform($pdo,$root,'tld.save',['registry_id'=>$registry,'tld'=>'unused','enabled'=>'1'],'127.0.0.1');
    Admin::perform($pdo,$root,'tld.delete',['registry_id'=>$registry,'tld'=>'unused','confirmed'=>'1'],'127.0.0.1');
    check((int)$pdo->query("SELECT COUNT(*) FROM registry_tlds WHERE tld='unused'")->fetchColumn()===0,'Panel action removes unused TLD through shared deletion path');
    echo "PASS: {$checks} account/TLD assertions\n";
}finally{if($other->inTransaction())$other->rollBack();$pdo=null;$other=null;$admin->exec("DROP DATABASE `$db`");}
