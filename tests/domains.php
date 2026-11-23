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

use GuzzleHttp\Client;
use Namingo\ResellerApi\{Auth, Crypto, DomainParams, Domains, Ledger, Ownership, PanelApi, PanelController, PanelSession, Util};
use Nyholm\Psr7\{Response, ServerRequest};
use Slim\Views\Twig;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/DomainParams.php';
require_once dirname(__DIR__) . '/src/Domains.php';
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_STRINGIFY_FETCHES=>true];
$dbAdmin = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$db = 'reseller_domains_' . bin2hex(random_bytes(6)); $dbAdmin->exec("CREATE DATABASE `$db`");
$pdo = new PDO($dsn . ';dbname=' . $db, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$_ENV['APP_MASTER_KEY'] = 'base64:' . base64_encode(random_bytes(32)); $_ENV['APP_ENV'] = 'local'; $_ENV['PANEL_ORIGIN'] = 'http://localhost';
$sessionRoot = sys_get_temp_dir() . '/' . $db; PanelSession::start($sessionRoot); $checks = 0;
function check(bool $ok, string $message): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($message); } }
function value(string $sql, array $args = []): mixed { global $pdo; $s=$pdo->prepare($sql); $s->execute($args); return $s->fetchColumn(); }
function request(string $path, string $method='GET', array $input=[], bool $csrf=true): Response {
    global $panel;
    if ($csrf && $method==='POST') { $input['csrf']=$_SESSION['csrf']; }
    parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
    return $panel->handle((new ServerRequest($method, 'http://localhost'.$path, [], '', '1.1', ['REMOTE_ADDR'=>'127.0.0.1']))->withParsedBody($input)->withQueryParams($query), new Response());
}
function drain(string $batch, array $actor, bool $hostExists = false, array $statuses = ['ok','clientTransferProhibited','<script>status</script>']): void {
    global $pdo;
    for ($round=0; $round<20; ++$round) {
        Domains::refresh($pdo,$actor,$batch);
        $ops=Domains::operations($pdo,$actor,$batch); $pending=false;
        foreach ($ops as $op) {
            foreach ($op['data']['steps'] as $step) {
                if ($step['job_uuid']===null || !in_array($step['status'],['queued','processing'],true)) { continue; }
                $pending=true; $name=$op['domainname'];
                $result = match ($step['command']) {
                    'host.check'=>['code'=>1000,'hosts'=>[['name'=>$step['params']['hostname'],'avail'=>$hostExists?0:1]]],
                    'domain.info'=>['code'=>1000,'name'=>$name,'crDate'=>'2026-01-01','exDate'=>'2028-01-01','status'=>$statuses,'registrant'=>'OWNER','ns'=>['ns1.example.net'],'contact'=>[['type'=>'tech','id'=>'TECH']],'authInfo'=>'PrivateDomainSecret!123'],
                    default=>['code'=>1000,'msg'=>'Command completed','name'=>$name],
                };
                Ledger::completeJob($pdo,$step['job_uuid'],'succeeded',$result,1000);
            }
        }
        if (!$pending) { return; }
    }
    throw new RuntimeException('Plan did not drain.');
}
try {
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
    $root=Util::uuid(); $a=Util::uuid(); $b=Util::uuid(); $password='valid domain test password'; $hash=password_hash($password,PASSWORD_ARGON2ID);
    foreach ([$root=>'admin',$a=>'reseller-a',$b=>'reseller-b'] as $id=>$username) {
        $pdo->prepare("INSERT INTO resellers (id,username,password_hash,currency,role) VALUES (?,?,?,'EUR',?)")->execute([$id,$username,$hash,(int)($id!==$root)]);
        $pdo->prepare("INSERT INTO wallets (reseller_id,currency,available_balance) VALUES (?,'EUR',1000)")->execute([$id]);
    }
    $registry=Util::uuid(); [$nonce,$cipher]=Crypto::encrypt('{}','registry-config:'.$registry);
    $pdo->prepare("INSERT INTO registries (id,name,profile,config_nonce,config_cipher) VALUES (?,'Domain registry','generic',?,?)")->execute([$registry,$nonce,$cipher]);
    $pdo->prepare("INSERT INTO registry_tlds (registry_id,tld) VALUES (?,'com')")->execute([$registry]);
    foreach ([$a,$b] as $id) {
        $pdo->prepare('INSERT INTO reseller_registries (reseller_id,registry_id) VALUES (?,?)')->execute([$id,$registry]);
        $pdo->prepare("INSERT INTO reseller_tlds (reseller_id,registry_id,tld) VALUES (?,?,'com')")->execute([$id,$registry]);
    }
    foreach (['domain.create','domain.renew'] as $cmd) {
        $pdo->prepare("INSERT INTO registry_prices (registry_id,command,tld,period,amount,currency) VALUES (?,?,'com',1,10,'EUR')")->execute([$registry,$cmd]);
    }
    $actor=['id'=>$a,'role'=>1]; $admin=['id'=>$root,'role'=>0];
    $panel=new PanelController($pdo,Twig::create(dirname(__DIR__).'/panel/views',['cache'=>false,'autoescape'=>'html']),new PanelApi(new Client()));
    PanelSession::login(Auth::login($pdo,'reseller-a',$password,'127.0.0.1'));
    $html=(string)request('/domains')->getBody();
    check(str_contains($html,'data-bs-target="#create-domain"') && str_contains($html,'name="domains"'),'Create/bulk modal exposed');
    $create=['key'=>Util::uuid(),'action'=>'create','domains'=>"one.com\ntwo.com\nnot-permitted.net",'ns1'=>'ns1.example.net','reseller_id'=>$b];
    check(request('/domains','POST',$create,false)->getStatusCode()===403,'CSRF required');
    check(request('/domains','POST',$create)->getStatusCode()===303,'Bulk create saved');
    $ops=Domains::operations($pdo,$actor,$create['key']);
    check(count($ops)===3 && count(array_filter($ops,fn($o)=>$o['status']==='rejected'))===1,'Unsupported TLD reported per member');
    check(value('SELECT COUNT(*) FROM panel_domain_operations WHERE reseller_id=?',[$b])==='0','Forged create owner ignored for reseller');
    drain($create['key'],$actor,true);
    check(value("SELECT COUNT(*) FROM epp_jobs WHERE command='host.create'")==='0','Existing public host objects reused without importing ownership');
    check(value("SELECT COUNT(*) FROM object_ownership WHERE object_type='domain' AND active=1 AND reseller_id=?",[$a])==='2','Both valid domains become owned');
    $userList=(string)request('/domains?batch='.$create['key'])->getBody();
    check(!str_contains($userList,'Operation results') && !str_contains($userList,'<th>Registry</th>'),'Reseller list omits operation results and registry column');
    check(str_contains($userList,'Some requests could not be submitted') && str_contains($userList,'not-permitted.net') && str_contains($userList,'domain_unavailable'), 'Unsupported bulk member remains visible even without an EPP job');
    $summary=Twig::create(dirname(__DIR__).'/panel/views',['cache'=>false,'autoescape'=>'html'])->getEnvironment()->render('partials/domain_operations.twig',[
        'path'=>'/domains','section'=>null,'operations'=>[
            ['name'=>'<bad>.com','status'=>'rejected','message'=>'invalid_parameters: <script>bad</script>','steps'=>[]],
            ['name'=>'late.com','status'=>'rejected','message'=>'','steps'=>[
                ['status'=>'succeeded','job_uuid'=>Util::uuid()],
                ['status'=>'rejected','job_uuid'=>null,'message'=>'insufficient_funds: Cannot submit the next step.']]],
            ['name'=>'done.com','status'=>'succeeded','message'=>'','steps'=>[]]
        ]]);
    check(str_contains($summary,'&lt;bad&gt;.com') && str_contains($summary,'&lt;script&gt;bad&lt;/script&gt;') && !str_contains($summary,'<script>bad</script>'),'Pre-job error names and messages are escaped');
    check(str_contains($summary,'insufficient_funds') && !str_contains($summary,'done.com') && !str_contains($summary,'Operation results'),'Failed unsubmitted steps remain visible without restoring the full result panel');
    check(str_contains($userList,'<th>Registered</th>') && str_contains($userList,'2026-01-01') && str_contains($userList,'2028-01-01'),'Reseller list shows cached registration and expiry dates');
    check(str_contains($userList,'State / registry status') && str_contains($userList,'clientTransferProhibited') && str_contains($userList,'title="Local state"'),'Local state and all registry statuses share a table cell');
    check(str_contains($userList,'&lt;script&gt;status&lt;/script&gt;') && !str_contains($userList,'PrivateDomainSecret'),'Listing escapes registry status and never renders authInfo');
    $listed=Domains::listing($pdo,$actor,1)['rows'];
    check(count($listed)===2 && $listed[0]['registered_at']==='2026-01-01' && $listed[0]['registry_statuses'][1]==='clientTransferProhibited','Listing enriches the page from scoped cached info');
    $withHost=['key'=>Util::uuid(),'action'=>'create','domains'=>'with-host.com','ns1'=>'ns-new.example.net','ipv4_1'=>'192.0.2.53'];
    Domains::submit($pdo,$actor,$withHost,'127.0.0.1');
    drain($withHost['key'],$actor);
    check(value("SELECT COUNT(*) FROM epp_jobs WHERE command='host.create'")==='1','Available host is created before domain registration');
    check(Domains::owned($pdo,$actor,$registry,'with-host.com')['active']==='1','Host dependency chain registers domain');
    $background=['key'=>Util::uuid(),'action'=>'create','domains'=>'background.com'];
    Domains::submit($pdo,$actor,$background,'127.0.0.1');
    $backgroundOp=Domains::operations($pdo,$actor,$background['key'])[0];
    Ledger::completeJob($pdo,$backgroundOp['data']['steps'][0]['job_uuid'],'succeeded',['name'=>'background.com','code'=>1000],1000);
    Domains::tick($pdo);
    $backgroundOp=Domains::operations($pdo,$actor,$background['key'])[0];
    check($backgroundOp['data']['steps'][1]['job_uuid']!==null,'Background tick advances saved plans without a page request');
    drain($background['key'],$actor);
    // Both bulk members observe availability before either host-create reservation exists.
    $shared=['key'=>Util::uuid(),'action'=>'create','domains'=>"shared-a.com\nshared-b.com",'ns1'=>'ns-shared.example.net'];
    $hostCount=(int)value("SELECT COUNT(*) FROM epp_jobs WHERE command='host.create'");
    Domains::submit($pdo,$actor,$shared,'127.0.0.1');
    foreach(Domains::operations($pdo,$actor,$shared['key']) as $op) {
        Ledger::completeJob($pdo,$op['data']['steps'][0]['job_uuid'],'succeeded',
            ['code'=>1000,'hosts'=>[['name'=>'ns-shared.example.net','avail'=>1]]],1000);
    }
    Domains::refresh($pdo,$actor,$shared['key']);
    $sharedOps=Domains::operations($pdo,$actor,$shared['key']);
    check((int)value("SELECT COUNT(*) FROM epp_jobs WHERE command='host.create'")===$hostCount+1,'Bulk members share one host-create reservation');
    check(count(array_filter($sharedOps,fn($op)=>$op['status']==='rejected'))===0,'Host reservation does not reject the waiting bulk member');
    foreach($sharedOps as $op) {
        $step=$op['data']['steps'][1];
        if($step['job_uuid']!==null) {
            $lost=$op['data']; $lost['steps'][1]['job_uuid']=null; $lost['steps'][1]['status']='prepared';
            [$nonce,$cipher]=Crypto::encrypt(Util::json($lost),'panel-domain:'.$op['id']);
            $pdo->prepare("UPDATE panel_domain_operations SET status='prepared',data_nonce=?,data_cipher=? WHERE id=?")->execute([$nonce,$cipher,$op['id']]);
            Domains::refresh($pdo,$actor,$shared['key']);
            $recovered=array_values(array_filter(Domains::operations($pdo,$actor,$shared['key']),fn($row)=>$row['id']===$op['id']))[0];
            check($recovered['data']['steps'][1]['job_uuid']===$step['job_uuid'],'Shared host owner recovers its own lost job UUID instead of waiting on itself');
        }
    }
    foreach($sharedOps as $op) {
        $step=$op['data']['steps'][1];
        if($step['job_uuid']!==null) { Ledger::completeJob($pdo,$step['job_uuid'],'succeeded',['code'=>1000],1000); }
    }
    drain($shared['key'],$actor);
    check(count(array_filter(Domains::operations($pdo,$actor,$shared['key']),fn($op)=>$op['status']==='succeeded'))===2,'Both domains register after the shared host completes');
    check((int)value("SELECT COUNT(*) FROM epp_jobs WHERE command='host.create'")===$hostCount+1,'Successful shared host is reused without another create');
    $count=value('SELECT COUNT(*) FROM epp_jobs');
    check(request('/domains','POST',$create)->getStatusCode()===303 && value('SELECT COUNT(*) FROM epp_jobs')===$count,'Repeated form resumes original jobs');
    $changed=$create; $changed['years']='2';
    check(request('/domains','POST',$changed)->getStatusCode()===409,'Changed payload cannot reuse key');
    $domain=Domains::owned($pdo,$actor,$registry,'one.com'); $detail='/domains/'.$registry.'/one.com';
    $html=(string)request($detail)->getBody();
    check(str_contains($html,'2028-01-01') && str_contains($html,'name="authInfo"') && str_contains($html,'name="dnssec_action"'),'Details and edit cards rendered from registry info');
    check(!str_contains($html,'PrivateDomainSecret!123'),'Cached authorization code not rendered in browser');
    $beforeRenew=value('SELECT COUNT(*) FROM epp_jobs');
    check(request($detail,'POST',['action'=>'renew','key'=>Util::uuid(),'years'=>'10'])->getStatusCode()===422,'Forged renewal beyond the remaining lifetime is rejected');
    check(value('SELECT COUNT(*) FROM epp_jobs')===$beforeRenew,'Over-limit panel renewal creates no job or wallet hold');
    check(str_contains($html,'Turn off transfer lock') && !str_contains($html,'<input class="form-control" name="status"'),'Resellers see the lock control and registry statuses without the general editor');
    $statusChange=['action'=>'status','status_action'=>'rem','status'=>'clientHold','key'=>Util::uuid()];
    check(request($detail,'POST',$statusChange)->getStatusCode()===403 && value('SELECT COUNT(*) FROM epp_jobs')===$beforeRenew,'Resellers cannot change other statuses through the panel');
    foreach (['rem'=>[], 'add'=>['clientTransferProhibited']] as $action=>$statuses) {
        $key=Util::uuid();
        $lockChange=array_replace($statusChange,['status'=>'clientTransferProhibited','status_action'=>$action,'key'=>$key]);
        check(request($detail,'POST',$lockChange)->getStatusCode()===303,'Reseller can submit either transfer lock action');
        $step=Domains::operations($pdo,$actor,$key)[0]['data']['steps'][0];
        check($step['command']==='domain.update_status' && $step['params']['command']===$action && $step['params']['status']==='clientTransferProhibited','Lock control uses the existing API command and exact status');
        drain($key,$actor,false,$statuses);
        check(str_contains((string)request($detail)->getBody(),$action==='rem'?'Turn on transfer lock':'Turn off transfer lock'),'Registry info refresh updates the lock control');
    }
    check(request('/admin/domains')->getStatusCode()===403,'Admin route requires role zero');
    PanelSession::login(Auth::login($pdo,'reseller-b',$password,'127.0.0.1'));
    check(request($detail)->getStatusCode()===404 && request($detail.'?format=json')->getStatusCode()===404,'Foreign detail/polling hidden');
    check(request($detail,'POST',['action'=>'delete','key'=>Util::uuid(),'confirm'=>'yes'])->getStatusCode()===404,'Foreign mutations hidden');
    check(!str_contains((string)request('/domains')->getBody(),'one.com'),'Foreign list hidden');
    check(!str_contains((string)request('/domains')->getBody(),'not-permitted.net'),'Another reseller cannot see pre-job failure summaries');
    PanelSession::login(Auth::login($pdo,'admin',$password,'127.0.0.1'));
    check(str_contains((string)request('/admin/domains')->getBody(),'one.com'),'Admin sees all domains');
    $adminList=(string)request('/admin/domains?batch='.$create['key'])->getBody();
    check(str_contains($adminList,'Operation results') && str_contains($adminList,'<th>Registry</th>') && str_contains($adminList,'<th>Reseller</th>'),'Admin list retains results, registry and reseller columns');
    check(str_contains($adminList,'2026-01-01') && str_contains($adminList,'2028-01-01') && str_contains($adminList,'clientTransferProhibited'),'Admin list shares cached dates and statuses');
    $pdo->prepare("INSERT INTO object_ownership (registry_id,object_type,object_name,reseller_id,active) VALUES (?,'domain','unsynced.com',?,1)")->execute([$registry,$a]);
    $unsynced=array_values(array_filter(Domains::listing($pdo,$actor,1)['rows'],fn($r)=>$r['object_name']==='unsynced.com'))[0];
    check($unsynced['registered_at']===null && $unsynced['expires_at']===null && $unsynced['registry_statuses']===[],'Unsynced domains have no invented dates or registry statuses');
    $pdo->prepare("DELETE FROM object_ownership WHERE registry_id=? AND object_name='unsynced.com'")->execute([$registry]);
    check(request($detail)->getStatusCode()===404,'Admin reseller workspace is own-only');
    check(request('/admin/domains/'.$registry.'/one.com')->getStatusCode()===200,'Admin opens another reseller domain');
    $adminDetail='/admin/domains/'.$registry.'/one.com';
    check(str_contains((string)request($adminDetail)->getBody(),'name="status" placeholder="clientTransferProhibited"'),'Admin keeps the general status editor');
    check(request($adminDetail,'POST',$statusChange)->getStatusCode()===303,'Admin can change another client status for the reseller');
    drain($statusChange['key'],$admin);
    $renew=['key'=>Util::uuid(),'action'=>'renew','years'=>'1'];
    Domains::submit($pdo,$admin,$renew,'127.0.0.1',$domain);
    $renewOp=Domains::operations($pdo,$admin,$renew['key'])[0]; $renewJob=$renewOp['data']['steps'][0]['job_uuid'];
    check(Ledger::fetchJob($pdo,$renewJob,$a)['command']==='domain.renew','Admin submits under the domain owner');
    // Simulate a crash between the independently committed job and saved job UUID.
    $data=$renewOp['data']; $data['steps'][0]['job_uuid']=null; $data['steps'][0]['status']='prepared';
    [$nonce,$cipher]=Crypto::encrypt(Util::json($data),'panel-domain:'.$renewOp['id']);
    $pdo->prepare("UPDATE panel_domain_operations SET status='prepared',data_nonce=?,data_cipher=? WHERE id=?")->execute([$nonce,$cipher,$renewOp['id']]);
    $count=value("SELECT COUNT(*) FROM epp_jobs WHERE command='domain.renew'");
    Domains::refresh($pdo,$admin,$renew['key']);
    check(Domains::operations($pdo,$admin,$renew['key'])[0]['data']['steps'][0]['job_uuid']===$renewJob && value("SELECT COUNT(*) FROM epp_jobs WHERE command='domain.renew'")===$count,'Crash recovery preserves paid request key');
    Ledger::completeJob($pdo,$renewJob,'unknown',['error'=>'Transport timeout'],null);
    Domains::refresh($pdo,$admin,$renew['key']);
    $blocked=['key'=>Util::uuid(),'action'=>'renew','years'=>'2'];
    Domains::submit($pdo,$admin,$blocked,'127.0.0.1',$domain);
    check(value("SELECT COUNT(*) FROM epp_jobs WHERE command='domain.renew'")===$count,'Unknown renewal blocks changed term/date purchases');
    Ledger::resolveUnknown($pdo,$renewJob,'charge'); drain($renew['key'],$admin);
    check(Domains::operations($pdo,$admin,$renew['key'])[0]['status']==='succeeded','Reconciled renewal resumes final info without another charge');
    $delete=['key'=>Util::uuid(),'action'=>'delete','confirm'=>'yes'];
    Domains::submit($pdo,$admin,$delete,'127.0.0.1',$domain);
    $delOp=Domains::operations($pdo,$admin,$delete['key'])[0];
    Ledger::completeJob($pdo,$delOp['data']['steps'][0]['job_uuid'],'rejected',['code'=>2305,'msg'=>'<script>in use</script>'],2305);
    Domains::refresh($pdo,$admin,$delete['key']);
    $html=(string)request('/admin/domains/'.$registry.'/one.com?batch='.$delete['key'])->getBody();
    check(str_contains($html,'2305') && str_contains($html,'&lt;script&gt;in use&lt;/script&gt;'),'Native EPP errors displayed and escaped');
    check(Domains::owned($pdo,$admin,$registry,'one.com')['active']==='1','Rejected delete preserves ownership');
    $retry=$delete; $retry['key']=Util::uuid(); Domains::submit($pdo,$admin,$retry,'127.0.0.1',$domain);
    $pendingDelete=Domains::operations($pdo,$admin,$retry['key'])[0]; $deleteJob=$pendingDelete['data']['steps'][0]['job_uuid'];
    Ledger::completeJob($pdo,$deleteJob,'pending',['code'=>1001,'msg'=>'Command completed successfully; action pending'],1001);
    $deleteCount=value("SELECT COUNT(*) FROM epp_jobs WHERE command='domain.delete'");
    Domains::refresh($pdo,$admin,$retry['key']);
    $pendingDelete=Domains::operations($pdo,$admin,$retry['key'])[0];
    check(count($pendingDelete['data']['steps'])===2 && $pendingDelete['data']['steps'][1]['command']==='domain.info','Accepted pending delete schedules a read-only CP refresh');
    $infoJob=$pendingDelete['data']['steps'][1]['job_uuid'];
    Ledger::completeJob($pdo,$infoJob,'succeeded',['code'=>1000,'name'=>'one.com','clID'=>'test-client','status'=>['pendingDelete'],'crDate'=>'2026-01-01','exDate'=>'2028-01-01'],1000);
    Domains::refresh($pdo,$admin,$retry['key']);
    $pendingDelete=Domains::operations($pdo,$admin,$retry['key'])[0];
    check($pendingDelete['status']==='pending' && Ledger::fetchJob($pdo,$deleteJob)['status']==='pending','Info success does not falsely complete the accepted delete');
    check(Domains::info([$pendingDelete])['status']===['pendingDelete'],'CP reads fresh pendingDelete instead of the old ok status');
    check(str_contains((string)request('/admin/domains/'.$registry.'/one.com')->getBody(),'pendingDelete'),'Domain details show pendingDelete without manual Sync');
    check(str_contains((string)request('/admin/domains')->getBody(),'pendingDelete'),'Domain table shows the refreshed registry status');
    Domains::refresh($pdo,$admin,$retry['key']);
    check(count(Domains::operations($pdo,$admin,$retry['key'])[0]['data']['steps'])===2 && value("SELECT COUNT(*) FROM epp_jobs WHERE command='domain.delete'")===$deleteCount,'Repeated refresh neither duplicates info steps nor replays deletion');
    $rawDelete=Namingo\ResellerApi\PanelData::rows($pdo,'SELECT * FROM epp_jobs WHERE id=?',[$deleteJob])[0];
    check(Namingo\ResellerApi\Lifecycle::settle($pdo,$rawDelete,['code'=>1000,'name'=>'one.com','clID'=>'test-client','status'=>['pendingDelete'],'rgp_status'=>['redemptionPeriod']],'test-client'),'Registry confirmation still controls delete completion');
    Domains::refresh($pdo,$admin,$retry['key']);

    check(value("SELECT domain_state FROM object_ownership WHERE registry_id=? AND object_name='one.com'",[$registry])==='deleted','Successful delete preserves the API redemption state');
    // Purge can race either execution or creation of the optional info refresh.
    foreach (['purge-before-read.com'=>true,'purge-before-auth.com'=>false] as $name=>$createRead) {
        $pdo->prepare("INSERT INTO object_ownership (registry_id,object_type,object_name,reseller_id,active) VALUES (?,'domain',?,?,1)")->execute([$registry,$name,$a]);
        $purgeDomain=Domains::owned($pdo,$admin,$registry,$name); $purgeKey=Util::uuid();
        Domains::submit($pdo,$admin,['key'=>$purgeKey,'action'=>'delete','confirm'=>'yes'],'127.0.0.1',$purgeDomain);
        $purgeOp=Domains::operations($pdo,$admin,$purgeKey)[0]; $purgeJob=$purgeOp['data']['steps'][0]['job_uuid'];
        Ledger::completeJob($pdo,$purgeJob,'pending',['code'=>1001],1001);
        if ($createRead) {
            Domains::refresh($pdo,$admin,$purgeKey);
            $purgeOp=Domains::operations($pdo,$admin,$purgeKey)[0];
        } else {
            // Persist the appended read just before its job is created, as after a crash.
            $purgeOp['data']['steps'][]=['command'=>'domain.info','params'=>['domainname'=>$name],'job_uuid'=>null,'status'=>'prepared'];
            [$nonce,$cipher]=Crypto::encrypt(Util::json($purgeOp['data']),'panel-domain:'.$purgeOp['id']);
            $pdo->prepare("UPDATE panel_domain_operations SET status='pending',data_nonce=?,data_cipher=? WHERE id=?")->execute([$nonce,$cipher,$purgeOp['id']]);
        }
        $rawPurge=Namingo\ResellerApi\PanelData::rows($pdo,'SELECT * FROM epp_jobs WHERE id=?',[$purgeJob])[0];
        check(Namingo\ResellerApi\Lifecycle::settle($pdo,$rawPurge,['code'=>2303],'test-client'),'Confirmed purge settles deletion before refresh');
        if ($createRead) { Ledger::completeJob($pdo,$purgeOp['data']['steps'][1]['job_uuid'],'rejected',['code'=>2303,'msg'=>'Object does not exist'],2303); }
        Domains::refresh($pdo,$admin,$purgeKey);
        $purgeOp=Domains::operations($pdo,$admin,$purgeKey)[0];
        check($purgeOp['status']==='succeeded' && $purgeOp['data']['steps'][1]['status']==='rejected','Optional info failure cannot reject successful deletion');
        check(Ledger::fetchJob($pdo,$purgeJob)['status']==='succeeded' && value("SELECT COUNT(*) FROM object_ownership WHERE registry_id=? AND object_name=?",[$registry,$name])==='0','Successful purge and released ownership remain intact');
        if (!$createRead) { check($purgeOp['data']['steps'][1]['job_uuid']===null,'Ownership denial before refresh job creation is also non-fatal'); }
        Domains::refresh($pdo,$admin,$purgeKey);
        check(Domains::operations($pdo,$admin,$purgeKey)[0]['status']==='succeeded','Later refresh preserves successful deletion');
    }
    // Pending restores keep their reservation while the API permits report and info follow-ups.
    $pdo->prepare("INSERT INTO registry_prices (registry_id,command,tld,period,amount,currency) VALUES (?,'domain.restore','',0,25,'EUR')")->execute([$registry]);
    $deletedDomain=Domains::owned($pdo,$admin,$registry,'one.com');
    $restore=['key'=>Util::uuid(),'action'=>'restore'];
    Domains::submit($pdo,$admin,$restore,'127.0.0.1',$deletedDomain);
    $restoreOp=Domains::operations($pdo,$admin,$restore['key'])[0]; $restoreJob=$restoreOp['data']['steps'][0]['job_uuid'];
    Ledger::completeJob($pdo,$restoreJob,'pending',['code'=>1001],1001);
    Domains::refresh($pdo,$admin,$restore['key']);
    $sync=['key'=>Util::uuid(),'action'=>'sync'];
    Domains::submit($pdo,$admin,$sync,'127.0.0.1',$deletedDomain);
    $syncOp=Domains::operations($pdo,$admin,$sync['key'])[0]; $syncJob=$syncOp['data']['steps'][0]['job_uuid'];
    check($syncJob!==null && Ledger::fetchJob($pdo,$syncJob,$a)['parent_job_uuid']===$restoreJob,'Sync follows a pending restore through the API rule');
    drain($sync['key'],$admin);
    $paidCount=value("SELECT COUNT(*) FROM epp_jobs WHERE command='domain.renew'");
    Domains::submit($pdo,$admin,['key'=>Util::uuid(),'action'=>'renew','years'=>'1'],'127.0.0.1',$deletedDomain);
    check(value("SELECT COUNT(*) FROM epp_jobs WHERE command='domain.renew'")===$paidCount,'Pending restore still blocks unrelated paid writes');
    $reportParams=['pre_data'=>'Before restoration','post_data'=>'After restoration','deleted_at'=>'2026-01-01T12:00:00Z',
        'restored_at'=>'2026-01-02T12:00:00Z','reason'=>'Registrant request',
        'statements'=>['Accurate first registrar statement','Accurate second registrar statement']];
    $report=['key'=>Util::uuid(),'action'=>'restore_report','native'=>Util::json($reportParams)];
    Domains::submit($pdo,$admin,$report,'127.0.0.1',$deletedDomain);
    $reportOp=Domains::operations($pdo,$admin,$report['key'])[0]; $reportJob=$reportOp['data']['steps'][0]['job_uuid'];
    check($reportJob!==null && Ledger::fetchJob($pdo,$reportJob,$a)['parent_job_uuid']===$restoreJob,'Restore report follows the outstanding restore plan');
    Ledger::completeJob($pdo,$reportJob,'succeeded',['code'=>1000],1000);
    drain($report['key'],$admin);
    Ledger::completeJob($pdo,$restoreJob,'succeeded',['code'=>1000],1000,true);
    drain($restore['key'],$admin);
    // A queued panel query must not prevent a second API-permitted transfer follow-up.
    $pdo->prepare("INSERT INTO registry_prices (registry_id,command,tld,period,amount,currency) VALUES (?,'domain.transfer','com',1,10,'EUR')")->execute([$registry]);
    $incoming=Ledger::createJob($pdo,$a,$registry,'domain.transfer','transfer-fixture-'.Util::uuid(),
        ['domainname'=>'incoming.com','op'=>'request','years'=>1,'authInfoPw'=>'TransferSecret!123']);
    Ledger::completeJob($pdo,$incoming['job_id'],'pending',['code'=>1001,'trStatus'=>'pending'],1001);
    $incomingDomain=Domains::owned($pdo,$admin,$registry,'incoming.com');
    foreach(['query','cancel'] as $op) {
        $follow=['key'=>Util::uuid(),'action'=>'transfer','native'=>Util::json(['op'=>$op])];
        Domains::submit($pdo,$admin,$follow,'127.0.0.1',$incomingDomain);
        $followOp=Domains::operations($pdo,$admin,$follow['key'])[0]; $followJob=$followOp['data']['steps'][0]['job_uuid'];
        check($followJob!==null && Ledger::fetchJob($pdo,$followJob,$a)['parent_job_uuid']===$incoming['job_id'],'Transfer '.$op.' keeps the API parent reservation despite an outstanding panel plan');
    }
    for($i=0;$i<55;++$i){$pdo->prepare("INSERT INTO object_ownership (registry_id,object_type,object_name,reseller_id,active) VALUES (?,'domain',?,?,1)")->execute([$registry,sprintf('page-%02d.com',$i),$b]);}
    $listing=Domains::listing($pdo,$admin,1); check(count($listing['rows'])===50 && $listing['next'],'Domain list pagination');
    check(count(Domains::listing($pdo,$actor,1)['rows'])===7,'Pagination keeps reseller scope');
    $pdo->exec('DROP TABLE panel_domain_operations'); $pdo->exec("DELETE FROM schema_migrations WHERE version='007_panel_domains'");
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/007_panel_domains.sql'));
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/007_panel_domains.sql'));
    check(value("SELECT COUNT(*) FROM schema_migrations WHERE version='007_panel_domains'")==='1','Upgrade migration repeats safely');
    echo "PASS: {$checks} domain workflow assertions\n";
} finally {
    $pdo=null; $dbAdmin->exec("DROP DATABASE `$db`"); session_destroy();
    foreach(glob($sessionRoot.'/var/panel-sessions/*')?:[] as $file){unlink($file);}
    @rmdir($sessionRoot.'/var/panel-sessions'); @rmdir($sessionRoot.'/var'); @rmdir($sessionRoot);
}
