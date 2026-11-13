<?php

declare(strict_types=1);

use Namingo\ResellerApi\{ApiException,CommandCatalog,Crypto,Db,EppWorker,Ledger,Lifecycle,Ownership,Queue,Rgp,Util};
require dirname(__DIR__) . '/vendor/autoload.php';
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true];
$user = getenv('TEST_MYSQL_USER') ?: 'root'; $password = getenv('TEST_MYSQL_PASSWORD') ?: '';
$admin = new PDO($dsn, $user, $password, $options); $db = 'reseller_lifecycle_' . bin2hex(random_bytes(6));
$admin->exec("CREATE DATABASE `$db`"); $pdo = new PDO($dsn . ';dbname=' . $db, $user, $password, $options);
$other = new PDO($dsn . ';dbname=' . $db, $user, $password, $options);
$_ENV['APP_MASTER_KEY'] = 'base64:' . base64_encode(random_bytes(32));
$_ENV['API_EPP_LOCK_SECONDS'] = '0'; $_ENV['API_QUEUE_MAX_INFLIGHT'] = '8';
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function denied(string $code, callable $call): void {
    try { $call(); } catch (ApiException $e) { check($e->errorCode === $code, 'Expected ' . $code . ', got ' . $e->errorCode); return; }
    throw new RuntimeException('Expected ' . $code);
}
function job(string $command, array $params, ?string $owner = null): array {
    global $pdo, $registry, $a; return Ledger::createJob($pdo, $owner ?? $a, $registry, $command, Util::uuid(), $params);
}
function raw(array $job): array { global $pdo; $s=$pdo->prepare('SELECT * FROM epp_jobs WHERE id = ?');$s->execute([$job['job_id']]);return $s->fetch(); }
function own(string $name): array|false { global $pdo, $registry; $s=$pdo->prepare("SELECT * FROM object_ownership WHERE registry_id = ? AND object_type = 'domain' AND object_name = ?");$s->execute([$registry,$name]);return $s->fetch(); }
function transfer(string $name): array {
    global $pdo; $j=job('domain.transfer',['domainname'=>$name,'op'=>'request','years'=>1,'authInfoPw'=>'not-rendered']);
    Ledger::completeJob($pdo,$j['job_id'],'pending',['code'=>1001,'trStatus'=>'pending','reDate'=>gmdate('Y-m-d\TH:i:s\Z')],1001);return $j;
}
function transferInfo(string $name, string $status): array {
    return ['code'=>1000,'name'=>$name,'trStatus'=>$status,'reID'=>'shared','acID'=>'loser','reDate'=>gmdate('Y-m-d\TH:i:s\Z')];
}
try {
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $a=Util::uuid();$b=Util::uuid();$registry=Util::uuid();
    foreach ([$a,$b] as $i=>$id) {
        $pdo->prepare("INSERT INTO resellers(id,username,password_hash,currency) VALUES(?,?,'unused','EUR')")->execute([$id,'user'.$i]);
        $pdo->prepare("INSERT INTO wallets(reseller_id,currency,available_balance) VALUES(?,'EUR',1000)")->execute([$id]);
    }
    $config=['connection'=>['host'=>'registry.invalid'],'login'=>['clID'=>'shared','pw'=>'secret']];
    [$nonce,$cipher]=Crypto::encrypt(Util::json($config),'registry-config:'.$registry);
    $pdo->prepare("INSERT INTO registries(id,name,profile,config_nonce,config_cipher) VALUES(?,'shared','generic',?,?)")->execute([$registry,$nonce,$cipher]);
    $pdo->prepare("INSERT INTO registry_tlds(registry_id,tld) VALUES(?,'com')")->execute([$registry]);
    foreach ([$a,$b] as $id) {
        $pdo->prepare('INSERT INTO reseller_registries(reseller_id,registry_id) VALUES(?,?)')->execute([$id,$registry]);
        $pdo->prepare("INSERT INTO reseller_tlds(reseller_id,registry_id,tld) VALUES(?,?,'com')")->execute([$id,$registry]);
    }
    foreach (['domain.delete'=>0,'domain.restore'=>25,'domain.restore_report'=>0,'domain.info'=>0,'domain.transfer'=>0,'domain.check'=>0] as $command=>$price) {
        $pdo->prepare("INSERT INTO registry_prices(registry_id,command,amount,currency) VALUES(?,?,?,'EUR')")->execute([$registry,$command,$price]);
    }
    foreach (['domain.create'=>10,'domain.transfer'=>12] as $command=>$price) {
        $pdo->prepare("INSERT INTO registry_prices(registry_id,command,tld,period,amount,currency) VALUES(?,?,'com',1,?,'EUR')")->execute([$registry,$command,$price]);
    }
    $pdo->prepare("INSERT INTO object_ownership(registry_id,reseller_id,object_type,object_name,active) VALUES(?,?,'domain','restore.com',1)")->execute([$registry,$a]);
    $delete=job('domain.delete',['domainname'=>'restore.com']); Ledger::completeJob($pdo,$delete['job_id'],'succeeded',['code'=>1000],1000);
    check(own('restore.com')['domain_state']==='deleted' && own('restore.com')['reseller_id']===$a,'Delete retains redemption ownership');
    denied('object_not_owned',fn()=>job('domain.create',['domainname'=>'restore.com','period'=>1],$b));
    denied('domain_deleted',fn()=>job('domain.renew',['domainname'=>'restore.com','regperiod'=>1]));
    $restore=job('domain.restore',['domainname'=>'restore.com']);
    Ledger::completeJob($pdo,$restore['job_id'],'pending',['code'=>1001],1001);
    check(raw($restore)['finished_at']===null && own('restore.com')['job_id']===$restore['job_id'],'Accepted restore stays pending and reserved');
    $pending=['code'=>1000,'name'=>'restore.com','clID'=>'shared','status'=>['pendingDelete'],'rgp_status'=>['pendingRestore']];
    check(Lifecycle::decision(raw($restore),$pending,'shared')===null,'Restore report stage is not completion');
    $report=['domainname'=>'restore.com','pre_data'=>'Before & original','post_data'=>'After & restored','deleted_at'=>'2026-01-01T12:00:00Z','restored_at'=>'2026-01-02T12:00:00Z','reason'=>'Registrant request','statements'=>['Accurate first registrar statement','Accurate second registrar statement']];
    $xml=Rgp::reportXml($report);$doc=new SimpleXMLElement($xml);$doc->registerXPathNamespace('r','urn:ietf:params:xml:ns:rgp-1.0');
    check((string)$doc->xpath('//r:preData')[0]===$report['pre_data'] && str_contains($xml,'2026-01-01T12:00:00Z') && !str_contains($xml,'2019-10-10'),'Report sends actual data, dates and escaped text');
    check(count($doc->xpath('//r:statement'))===2,'Report includes both supplied statements');
    denied('invalid_restore_report',fn()=>Rgp::validate(array_replace($report,['deleted_at'=>'2026-02-30T12:00:00Z'])));
    denied('invalid_restore_report',fn()=>Rgp::validate(array_replace($report,['statements'=>['one']])));
    $reportJob=job('domain.restore_report',$report);check(raw($reportJob)['parent_job_id']===$restore['job_id'],'Report follows original restore reservation');
    denied('object_not_owned',fn()=>job('domain.restore_report',$report,$b));
    Ledger::completeJob($pdo,$reportJob['job_id'],'pending',['code'=>1000],1000);
    $active=['code'=>1000,'name'=>'restore.com','clID'=>'shared','status'=>['ok'],'rgp_status'=>[]];
    check(Lifecycle::settle($pdo,raw($reportJob),$active,'shared'),'Completed report observed');
    Lifecycle::observeFollowup($pdo,raw($reportJob),$active,'shared');
    $original=raw($restore);check(Lifecycle::settle($pdo,$original,$active,'shared'),'Restore completion observed');
    Lifecycle::settle($pdo,$original,$active,'shared');
    check(own('restore.com')['domain_state']==='active' && own('restore.com')['job_id']===null,'Confirmed restore reactivates ownership');
    check((int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE job_id = '".$restore['job_id']."' AND kind='charge'")->fetchColumn()===1,'Restore charged exactly once');
    $delete=job('domain.delete',['domainname'=>'restore.com']);Ledger::completeJob($pdo,$delete['job_id'],'pending',['code'=>1001],1001);
    check(Lifecycle::settle($pdo,raw($delete),array_replace($pending,['rgp_status'=>['redemptionPeriod']]),'shared'),'Pending deletion enters confirmed redemption');
    Lifecycle::observeDeleted($pdo,$registry,'restore.com',$a,['code'=>2400]);check(own('restore.com')!==false,'Transient query errors never release ownership');
    Lifecycle::observeDeleted($pdo,$registry,'restore.com',$a,['code'=>2303]);check(own('restore.com')===false,'Confirmed purge releases ownership');
    $new=job('domain.create',['domainname'=>'restore.com','period'=>1],$b);Ledger::completeJob($pdo,$new['job_id'],'rejected',[],2302);
    $parent=transfer('cancel.com');$query=job('domain.transfer',['domainname'=>'cancel.com','op'=>'query']);
    check(raw($query)['parent_job_id']===$parent['job_id'] && own('cancel.com')['job_id']===$parent['job_id'],'Query preserves original reservation');
    denied('object_not_owned',fn()=>job('domain.transfer',['domainname'=>'cancel.com','op'=>'query'],$b));
    $cancel=job('domain.transfer',['domainname'=>'cancel.com','op'=>'cancel']);$cancelled=transferInfo('cancel.com','clientCancelled');
    $cancelled['reDate']=json_decode(Crypto::decrypt(raw($parent)['response_nonce'],raw($parent)['response_cipher'],'job-response:'.$parent['job_id']),true)['reDate'];
    Ledger::completeJob($pdo,$cancel['job_id'],'succeeded',$cancelled,1000);Lifecycle::observeFollowup($pdo,raw($cancel),$cancelled,'shared');
    check(raw($parent)['status']==='rejected' && own('cancel.com')===false,'Cancellation releases original hold and pending ownership');
    // A 1001 cancellation follows the gaining client and the original request date.
    $pdo->exec("UPDATE registry_prices SET amount=3 WHERE command='domain.transfer' AND period=0");
    foreach ([false, true] as $parentFirst) {
        $name=$parentFirst?'cancel-parent-first.com':'cancel-child-first.com';
        $parent=transfer(ucfirst($name));$date=gmdate('Y-m-d\TH:i:s\Z',time()-86400*2);
        Ledger::completeJob($pdo,$parent['job_id'],'pending',['code'=>1001,'reDate'=>$date],1001);
        $cancel=job('domain.transfer',['domainname'=>$name,'op'=>'cancel']);
        Ledger::completeJob($pdo,$cancel['job_id'],'pending',['code'=>1001],1001);
        check(raw($cancel)['parent_job_id']===$parent['job_id'],'Mixed-case cancellation links to original request');
        $cancelled=array_replace(transferInfo($name,'clientCancelled'),['reDate'=>$date]);
        check(Lifecycle::decision(raw($cancel),array_replace($cancelled,['reID'=>'other','acID'=>'shared']),'shared',raw($parent))===null,'Cancellation rejects losing-client-only correlation');
        check(!Lifecycle::settle($pdo,raw($cancel),array_replace($cancelled,['reDate'=>gmdate('Y-m-d\TH:i:s\Z')]),'shared'),'Cancellation rejects another transfer request date');
        check(!Lifecycle::settle($pdo,raw($cancel),array_replace($cancelled,['trStatus'=>'pending']),'shared'),'Cancellation waits for clientCancelled');
        if ($parentFirst) { Lifecycle::settle($pdo,raw($parent),$cancelled,'shared'); }
        $pendingCancel=raw($cancel);
        check(Lifecycle::settle($pdo,$pendingCancel,$cancelled,'shared'),'Accepted incoming cancellation confirms against gaining client');
        Lifecycle::observeFollowup($pdo,$pendingCancel,$cancelled,'shared');
        Lifecycle::settle($pdo,$pendingCancel,$cancelled,'shared');
        check(raw($cancel)['status']==='succeeded' && raw($parent)['status']==='rejected' && own($name)===false,'Both settlement orders finish cancellation and original request');
        check((int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE job_id='".$cancel['job_id']."' AND kind='charge'")->fetchColumn()===1,'Cancellation fee charges exactly once');
        check((int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE job_id='".$parent['job_id']."' AND kind='release'")->fetchColumn()===1,'Original transfer hold releases exactly once');
    }
    $pdo->exec("UPDATE registry_prices SET amount=0 WHERE command='domain.transfer' AND period=0");
    $parent=transfer('approve.com');$query=job('domain.transfer',['domainname'=>'approve.com','op'=>'query']);$approved=transferInfo('approve.com','serverApproved');
    $approved['reDate']=json_decode(Crypto::decrypt(raw($parent)['response_nonce'],raw($parent)['response_cipher'],'job-response:'.$parent['job_id']),true)['reDate'];
    check(Lifecycle::decision(raw($parent),array_replace($approved,['reID'=>'another-client']),'shared')===null,'Wrong gaining client cannot settle transfer');
    check(Lifecycle::decision(raw($parent),array_replace($approved,['reDate'=>'2001-01-01T00:00:00Z']),'shared')===null,'Old transfer cannot settle a new request');
    check(Lifecycle::settle($pdo,raw($parent),$approved,'shared'),'Approved transfer activates original owner');
    Ownership::authorize($pdo,$registry,$a,'domain.transfer',['domainname'=>'approve.com','op'=>'query'],'generic',$query['job_id']);
    check(own('approve.com')['active']==='1' && own('approve.com')['job_id']===null,'Queued query remains authorized after parent settles');
    $outgoing=job('domain.transfer',['domainname'=>'approve.com','op'=>'approve']);
    Ledger::completeJob($pdo,$outgoing['job_id'],'pending',['code'=>1001,'reDate'=>'2026-01-01T12:00:00Z'],1001);
    $outgoingQuery=job('domain.transfer',['domainname'=>'approve.com','op'=>'query']);
    check(raw($outgoingQuery)['parent_job_id']===$outgoing['job_id'],'Outgoing pending transfer remains queryable');
    $outgoingInfo=array_replace(transferInfo('approve.com','serverApproved'),['reID'=>'new-owner','acID'=>'shared','reDate'=>'2026-01-01T12:00:00Z']);
    check(Lifecycle::settle($pdo,raw($outgoing),$outgoingInfo,'shared') && own('approve.com')===false,'Confirmed outgoing approval releases local ownership');
    $uncertain=job('domain.transfer',['domainname'=>'uncertain.com','op'=>'request','years'=>1,'authInfoPw'=>'secret']);Ledger::completeJob($pdo,$uncertain['job_id'],'unknown',['error'=>'timeout'],null);
    $query=job('domain.transfer',['domainname'=>'uncertain.com','op'=>'query']);
    check(Lifecycle::decision(raw($uncertain),transferInfo('uncertain.com','serverApproved'),'shared')===null,'Uncertain write is never silently finalized');
    denied('object_busy',fn()=>job('domain.transfer',['domainname'=>'uncertain.com','op'=>'cancel']));
    denied('not_reconcilable',fn()=>Lifecycle::schedule($pdo,$uncertain['job_id'],$a));
    denied('job_not_found',fn()=>Lifecycle::schedule($pdo,$uncertain['job_id'],$b));
    check(Lifecycle::status('domain.transfer',['op'=>'query'],['code'=>1001,'trStatus'=>'pending'])==='succeeded','Query is read-only');
    check(Lifecycle::status('domain.transfer',['op'=>'request'],['code'=>1001])==='pending','Accepted transfer differs from unknown');
    check(Lifecycle::status('domain.create',[],['error'=>'timeout'])==='unknown','Uncertain create remains unknown');
    check(Lifecycle::status('domain.restore',[],['code'=>1000])==='pending','Restore request waits for report/completion evidence');
    // Suppress unrelated due work to isolate queue lease/retry tests.
    $pdo->exec("UPDATE epp_jobs SET next_attempt_at=UTC_TIMESTAMP(6)+INTERVAL 1 DAY,reconcile_at=UTC_TIMESTAMP(6)+INTERVAL 1 DAY,dispatch_until=NULL WHERE status IN ('queued','pending')");
    $queued=job('domain.create',['domainname'=>'queue.com','period'=>1,'authInfoPw'=>'secret']);$tasks=[];
    check(Queue::tick($pdo,function($task)use(&$tasks){$tasks[]=$task;return 1;})===1 && $tasks[0]['id']===$queued['job_id'],'Dispatcher picks committed queued jobs without a client retry');
    check(Queue::tick($pdo,fn()=>1)===0,'Lease prevents duplicate dispatch');
    $pdo->prepare('UPDATE epp_jobs SET dispatch_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE id=?')->execute([$queued['job_id']]);
    check(Queue::tick($pdo,fn()=>false)===0 && raw($queued)['dispatch_until']===null,'Failed send releases dispatch lease');
    check(Queue::tick($pdo,fn()=>1)===1,'Lost delivery is retried safely');
    (new ReflectionProperty(Db::class,'pdo'))->setValue(null,$pdo);
    $lock=$other->prepare('SELECT GET_LOCK(?,0)');$lock->execute([Queue::lockName($registry)]);
    check(EppWorker::process($queued['job_id'])['status']==='queued','Busy registry requeues without EPP write');
    check(raw($queued)['next_attempt_at']>Util::utcNow() && own('queue.com')['job_id']===$queued['job_id'],'Busy retry is delayed and reservation retained');
    $pdo->prepare("UPDATE epp_jobs SET status='processing',started_at=UTC_TIMESTAMP(6)-INTERVAL 1 HOUR WHERE id=?")->execute([$queued['job_id']]);
    Queue::recover($pdo);check(raw($queued)['status']==='processing','Recovery never interrupts a held registry lock');
    $other->prepare('SELECT RELEASE_LOCK(?)')->execute([Queue::lockName($registry)]);
    Queue::recover($pdo);check(raw($queued)['status']==='unknown' && own('queue.com')['job_id']===$queued['job_id'],'Dead processing write becomes unknown, preserving ownership');
    check(EppWorker::process($queued['job_id'])['status']==='unknown','Unknown write cannot be executed again');
    $held=$pdo->query("SELECT held_balance FROM wallets WHERE reseller_id='$a'")->fetchColumn();check(Util::compareMoney($held,'0.0000')>0,'Uncertain writes retain wallet holds');
    $scheduled=Lifecycle::schedule($pdo,$query['job_id'],$a);check($scheduled['status']==='queued','Own queued query can be scheduled');
    $pdo->exec("UPDATE epp_jobs SET next_attempt_at=UTC_TIMESTAMP(6)+INTERVAL 1 DAY,reconcile_at=UTC_TIMESTAMP(6)+INTERVAL 1 DAY,dispatch_until=NULL WHERE status IN ('queued','pending')");
    $auto=transfer('auto.com');$pdo->prepare('UPDATE epp_jobs SET reconcile_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$auto['job_id']]);
    $_ENV['API_QUEUE_MAX_INFLIGHT']='1';$tasks=[];
    check(Queue::tick($pdo,function($task)use(&$tasks){$tasks[]=$task;return 1;})===1 && $tasks[0]['kind']==='reconcile','Accepted pending work automatically schedules read-only reconciliation');
    $another=job('domain.check',['domains'=>['new.com']]);
    check(Queue::tick($pdo,fn()=>1)===0,'Dispatcher respects bounded in-flight budget');
    $pdo->exec("UPDATE epp_jobs SET next_attempt_at=UTC_TIMESTAMP(6)+INTERVAL 1 DAY,reconcile_at=UTC_TIMESTAMP(6)+INTERVAL 1 DAY,dispatch_until=NULL WHERE status IN ('queued','pending')");
    $pdo->prepare("INSERT INTO object_ownership(registry_id,reseller_id,object_type,object_name,active,domain_state,next_check_at) VALUES(?,?,'domain','cleanup.com',1,'deleted',UTC_TIMESTAMP(6))")->execute([$registry,$a]);
    $tasks=[];check(Queue::tick($pdo,function($task)use(&$tasks){$tasks[]=$task;return 1;})===1 && $tasks[0]['kind']==='deleted','Retained deleted domains automatically schedule purge checks');
    $_ENV['API_QUEUE_MAX_INFLIGHT']='8';
    // Native RGP info includes extension states omitted by the pinned domainInfo parser.
    $fake=new class {public function writeRequest($xml){return new SimpleXMLElement('<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>OK</msg></result><resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>restore.com</domain:name><domain:clID>shared</domain:clID><domain:status s="pendingDelete"/></domain:infData></resData><extension><rgp:infData xmlns:rgp="urn:ietf:params:xml:ns:rgp-1.0"><rgp:rgpStatus s="pendingRestore"/></rgp:infData></extension></response></epp>');}};
    check(Rgp::info($fake,'generic',['domainname'=>'restore.com'])['rgp_status']===['pendingRestore'],'RGP observation retains pending restore state');
    // Simulate a v3 upgrade: existing unknown 1001 responses become accepted pending work.
    $legacy=job('domain.transfer',['domainname'=>'legacy.com','op'=>'request','years'=>1,'authInfoPw'=>'secret']);Ledger::completeJob($pdo,$legacy['job_id'],'unknown',['code'=>1001],1001);
    $pdo->exec("UPDATE epp_jobs SET status='unknown' WHERE status='pending'");
    $pdo->exec("ALTER TABLE epp_jobs DROP INDEX idx_job_dispatch,DROP INDEX idx_job_reconcile,DROP INDEX idx_job_parent,DROP COLUMN parent_job_id,DROP COLUMN next_attempt_at,DROP COLUMN dispatch_until,DROP COLUMN reconcile_at,DROP COLUMN last_error,MODIFY COLUMN status ENUM('queued','processing','succeeded','rejected','unknown') NOT NULL DEFAULT 'queued'");
    $pdo->exec('ALTER TABLE object_ownership DROP INDEX idx_ownership_check,DROP COLUMN domain_state,DROP COLUMN next_check_at,DROP COLUMN check_until');
    $pdo->exec("DELETE FROM schema_migrations WHERE version='004_lifecycle_queue'");
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/004_lifecycle_queue.sql'));
    check(raw($legacy)['status']==='pending' && raw($legacy)['reconcile_at']!==null,'Upgrade recovers accepted legacy pending operations');
    check(raw($uncertain)['status']==='unknown','Upgrade never promotes transport uncertainty');
    echo "PASS: {$checks} lifecycle/queue assertions\n";
} finally {
    Db::reset();$pdo=null;$other=null;$admin->exec("DROP DATABASE `$db`");
}
