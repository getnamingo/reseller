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
use Namingo\ResellerApi\{Auth, Crypto, DomainParams, Domains, Ledger, Ownership, PanelApi, PanelController, PanelSession, Transfers, Util};
use Nyholm\Psr7\{Response, ServerRequest};
use Slim\Views\Twig;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/DomainParams.php';
require_once dirname(__DIR__) . '/src/Domains.php';
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_STRINGIFY_FETCHES=>true];
$dbAdmin = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$db = 'reseller_transfers_' . bin2hex(random_bytes(6)); $dbAdmin->exec("CREATE DATABASE `$db`");
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
try {
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
    $root=Util::uuid(); $a=Util::uuid(); $b=Util::uuid(); $password='valid domain test password'; $hash=password_hash($password,PASSWORD_ARGON2ID);
    foreach ([$root=>'admin',$a=>'reseller-a',$b=>'reseller-b'] as $id=>$username) {
        $pdo->prepare("INSERT INTO resellers (id,username,password_hash,currency,role) VALUES (?,?,?,'EUR',?)")->execute([$id,$username,$hash,(int)($id!==$root)]);
        $pdo->prepare("INSERT INTO wallets (reseller_id,currency,available_balance) VALUES (?,'EUR',1000)")->execute([$id]);
    }
    $registry=Util::uuid(); [$nonce,$cipher]=Crypto::encrypt('{"login":{"clID":"OUR-REGISTRAR"}}','registry-config:'.$registry);
    $pdo->prepare("INSERT INTO registries (id,name,profile,config_nonce,config_cipher) VALUES (?,'Domain registry','generic',?,?)")->execute([$registry,$nonce,$cipher]);
    $pdo->prepare("INSERT INTO registry_tlds (registry_id,tld) VALUES (?,'com')")->execute([$registry]);
    foreach ([$a,$b] as $id) {
        $pdo->prepare('INSERT INTO reseller_registries (reseller_id,registry_id) VALUES (?,?)')->execute([$id,$registry]);
        $pdo->prepare("INSERT INTO reseller_tlds (reseller_id,registry_id,tld) VALUES (?,?,'com')")->execute([$id,$registry]);
    }
    $pdo->prepare("INSERT INTO registry_prices (registry_id,command,tld,period,amount,currency) VALUES (?,'domain.transfer','com',1,10,'EUR')")->execute([$registry]);
    $actor=['id'=>$a,'role'=>1]; $admin=['id'=>$root,'role'=>0];
    $panel=new PanelController($pdo,Twig::create(dirname(__DIR__).'/panel/views',['cache'=>false,'autoescape'=>'html']),new PanelApi(new Client()));
    PanelSession::login(Auth::login($pdo,'reseller-a',$password,'127.0.0.1'));
    check(request('/admin/transfers')->getStatusCode()===403,'Reseller cannot open admin transfers');
    $html=(string)request('/transfers')->getBody();
    check(str_contains($html,'data-bs-target="#request-transfer"') && str_contains($html,'name="domains"'),'Matching bulk request modal');
    $input=['key'=>Util::uuid(),'action'=>'request','domains'=>"incoming.com,Secret,with,commas\r\nother.com,AnotherSecret\nmissing.com\nblocked.net,Secret",'reseller_id'=>$b];
    check(request('/transfers','POST',$input,false)->getStatusCode()===403,'CSRF required');
    check(request('/transfers','POST',$input)->getStatusCode()===303,'Bulk transfer saved');
    $ops=Domains::operations($pdo,$actor,$input['key']);
    check(count($ops)===4 && count(array_filter($ops,fn($o)=>$o['status']==='rejected'))===2,'Missing code and unpermitted TLD fail independently');
    check(value('SELECT COUNT(*) FROM domain_transfers WHERE reseller_id=?',[$b])==='0','Forged bulk owner ignored');
    $incoming=array_values(array_filter($ops,fn($o)=>$o['domainname']==='incoming.com'))[0];
    $job=$incoming['data']['steps'][0]['job_uuid'];
    check($incoming['data']['steps'][0]['params']['authInfoPw']==='Secret,with,commas','Authorization code preserves extra commas');
    check(request('/transfers','POST',$input)->getStatusCode()===303 && value('SELECT COUNT(*) FROM domain_transfers')==='2','Same key reuses original requests');
    Ledger::completeJob($pdo,$job,'pending',['code'=>1001,'name'=>'incoming.com','trStatus'=>'pending'],1001);
    $row=Transfers::get($pdo,$actor,$job);
    check(Transfers::actions($row)===['query','cancel'],'Incoming request permits refresh and cancellation');
    check(request('/transfers','POST',['key'=>Util::uuid(),'action'=>'approve','transfer_id'=>$job])->getStatusCode()===409,'Gaining account cannot approve incoming transfer');
    $cancel=['key'=>Util::uuid(),'action'=>'cancel','transfer_id'=>$job];
    $res=request('/transfers','POST',$cancel);
    check($res->getStatusCode()===303,'Cancellation accepted');
    $cancelOp=Domains::operations($pdo,$actor,substr($res->getHeaderLine('Location'),strlen('/transfers?batch=')))[0];
    $cancelJob=$cancelOp['data']['steps'][0]['job_uuid'];
    check($cancelJob!==null && Ledger::fetchJob($pdo,$cancelJob,$a)['parent_job_uuid']===$job,'Cancel uses original incoming reservation');
    check(Transfers::actions(Transfers::get($pdo,$actor,$job))===[],'Queued decision hides buttons');
    Ledger::completeJob($pdo,$cancelJob,'succeeded',['code'=>1000,'name'=>'incoming.com','trStatus'=>'clientCancelled'],1000);
    $parent=Namingo\ResellerApi\PanelData::rows($pdo,'SELECT * FROM epp_jobs WHERE id=?',[$cancelJob])[0];
    Namingo\ResellerApi\Lifecycle::observeFollowup($pdo,$parent,['code'=>1000,'name'=>'incoming.com','trStatus'=>'clientCancelled','reID'=>'OUR-REGISTRAR','reDate'=>gmdate('Y-m-d\TH:i:s\Z')],'OUR-REGISTRAR');
    check(Transfers::get($pdo,$actor,$job)['tr_status']==='clientCancelled','Cancellation status visible');
    check(Transfers::get($pdo,$actor,$job)['status']==='rejected','Cancelled transfer terminal');
    foreach (['outgoing.com'=>$a,'private.com'=>$b] as $name=>$owner) {
        $pdo->prepare("INSERT INTO object_ownership (registry_id,object_type,object_name,reseller_id,active) VALUES (?,'domain',?,?,1)")->execute([$registry,$name,$owner]);
    }
    Transfers::discover($pdo);
    $queries=Namingo\ResellerApi\PanelData::rows($pdo,"SELECT * FROM epp_jobs WHERE idempotency_key LIKE 'transfer-discovery-%'");
    check(count($queries)===2,'Discovery schedules owned idle domains');
    Transfers::discover($pdo);
    check(value("SELECT COUNT(*) FROM epp_jobs WHERE idempotency_key LIKE 'transfer-discovery-%'")==='2','Discovery interval prevents repeated queries');
    $automatic = Namingo\ResellerApi\SelfService::listing($pdo,$a,'jobs',['source'=>'automatic']);
    check(count($automatic['data'])===1 && $automatic['data'][0]['source']==='automatic','Automatic source filter remains reseller scoped');
    $autoId=$automatic['data'][0]['job_uuid'];
    $requested = Namingo\ResellerApi\SelfService::listing($pdo,$a,'jobs',['source'=>'requested']);
    check(!in_array($autoId,array_column($requested['data'],'job_uuid'),true),'Requested jobs exclude background checks before pagination');
    check(in_array($autoId,array_column(Namingo\ResellerApi\SelfService::listing($pdo,$a,'jobs')['data'],'job_uuid'),true),'API job history retains background jobs by default');
    check(Ledger::fetchJob($pdo,$autoId,$a)['automatic_transfer_check']===true,'Job detail identifies read-only discovery');
    $defaultJobs=(string)request('/jobs')->getBody();
    check(!str_contains($defaultJobs,$autoId) && str_contains($defaultJobs,'name="source"'),'Panel defaults to customer requests and offers source filter');
    check(!str_contains((string)request('/')->getBody(),$autoId),'Background checks do not crowd the dashboard');
    $autoDetail=Twig::create(dirname(__DIR__).'/panel/views',['cache'=>false,'autoescape'=>'html'])->getEnvironment()->render('job.twig',['job'=>Ledger::fetchJob($pdo,$autoId,$a)]);
    check(str_contains($autoDetail,'Automatic transfer status check') && str_contains($autoDetail,'not a transfer request'),'Job detail explains automatic query origin');
    $autoJobs=(string)request('/jobs?source=automatic')->getBody();
    check(str_contains($autoJobs,$autoId) && str_contains($autoJobs,'Automatic transfer status query'),'Automatic checks remain visible and labelled through source filter');
    $date='2026-11-17T08:00:00Z';
    foreach($queries as $q) {
        $name=Ownership::params($q)['domainname'];
        Ledger::completeJob($pdo,$q['id'],'succeeded',['code'=>1000,'name'=>$name,'trStatus'=>'pending','reID'=>'EXTERNAL','acID'=>'OUR-REGISTRAR','reDate'=>$date],1000);
    }
    $out=Namingo\ResellerApi\PanelData::rows($pdo,"SELECT id FROM domain_transfers WHERE domainname='outgoing.com'")[0]['id'];
    $private=Namingo\ResellerApi\PanelData::rows($pdo,"SELECT id FROM domain_transfers WHERE domainname='private.com'")[0]['id'];
    check(Transfers::get($pdo,$actor,$out)['direction']==='outgoing','External request discovered as outgoing');
    $negative=Ledger::createJob($pdo,$a,$registry,'domain.transfer','transfer-discovery-'.Util::uuid(),['domainname'=>'outgoing.com','op'=>'query']);
    Ledger::completeJob($pdo,$negative['job_id'],'rejected',['code'=>2301,'msg'=>'The domain is NOT pending transfer'],2301);
    check(Ledger::fetchJob($pdo,$negative['job_id'],$a)['status']==='rejected','Negative discovery preserves registry response instead of faking success');
    $errors=Namingo\ResellerApi\SelfService::listing($pdo,$a,'jobs',['source'=>'automatic','status'=>'rejected']);
    check(in_array($negative['job_id'],array_column($errors['data'],'job_uuid'),true),'Rejected background checks remain available with status filters');
    check(Transfers::get($pdo,$actor,$out)['status']==='pending','Rejected unrelated discovery never rejects a real pending transfer');

    check(Transfers::actions(Transfers::get($pdo,$actor,$out))===['query','approve','reject'],'Losing account has correct actions');
    check(request('/transfers','POST',['key'=>Util::uuid(),'action'=>'cancel','transfer_id'=>$out])->getStatusCode()===409,'Losing account cannot cancel outgoing transfer');
    check(request('/transfers','POST',['key'=>Util::uuid(),'action'=>'approve','transfer_id'=>$private])->getStatusCode()===404,'Cannot act on another reseller transfer');
    $html=(string)request('/transfers')->getBody();
    check(str_contains($html,'outgoing.com') && !str_contains($html,'private.com'),'List isolates accounts');
    check(!str_contains($html,'Secret,with,commas') && !str_contains($html,'AnotherSecret'),'Codes absent from HTML and operation results');
    $refresh=request('/transfers','POST',['key'=>Util::uuid(),'action'=>'query','transfer_id'=>$out]);
    check($refresh->getStatusCode()===303,'Outgoing query queued');
    $query=Transfers::get($pdo,$actor,$out)['latest_job_id'];
    Ledger::completeJob($pdo,$query,'succeeded',['code'=>1000,'name'=>'outgoing.com','trStatus'=>'pending','reID'=>'EXTERNAL','acID'=>'OUR-REGISTRAR','reDate'=>$date],1000);
    check(value("SELECT COUNT(*) FROM domain_transfers WHERE domainname='outgoing.com'")==='1','Refresh does not duplicate transfer');
    $approve=request('/transfers','POST',['key'=>Util::uuid(),'action'=>'approve','transfer_id'=>$out]);
    check($approve->getStatusCode()===303,'Losing account can approve');
    $approveJob=Transfers::get($pdo,$actor,$out)['latest_job_id'];
    Ledger::completeJob($pdo,$approveJob,'succeeded',['code'=>1000,'msg'=>'Completed'],1000);
    check(Transfers::get($pdo,$actor,$out)['tr_status']==='clientApproved','Successful approval terminal even without transfer response data');
    check(value("SELECT COUNT(*) FROM object_ownership WHERE object_name='outgoing.com'")==='0','Approved outgoing domain removed from ownership');
    check(Transfers::actions(Transfers::get($pdo,$actor,$out))===[],'Terminal transfer has no decisions');
    PanelSession::login(Auth::login($pdo,'admin',$password,'127.0.0.1'));
    $adminJobs=(string)request('/admin/jobs')->getBody();
    check(str_contains($adminJobs,$autoId) && str_contains($adminJobs,'Automatic transfer check'),'Admin history retains and labels automatic checks');
    $html=(string)request('/admin/transfers')->getBody();
    check(str_contains($html,'private.com') && str_contains($html,'outgoing.com') && str_contains($html,'Gaining reseller'),'Administrator sees all transfers and reseller selector');
    check(!str_contains((string)request('/transfers')->getBody(),'private.com'),'Admin workspace remains own-account-only');
    // API jobs also appear immediately; request-only LT profile does not require a code.
    $direct=Ledger::createJob($pdo,$b,$registry,'domain.transfer','direct-transfer-key',['domainname'=>'api.com','op'=>'request','authInfoPw'=>'APISecret','years'=>1]);
    check(Transfers::get($pdo,$admin,$direct['job_id'])['direction']==='incoming','API request indexed');
    $plan=Transfers::plan('LT','request','test.lt',['domains'=>'test.lt']);
    check(!isset($plan[0]['params']['authInfoPw']),'Code omitted for code-free profile');
    try { Transfers::entries(['domains'=>"duplicate.com,a\nDuplicate.com,b"]); check(false,'Duplicate must fail'); }
    catch(Namingo\ResellerApi\ApiException $e) { check($e->httpStatus===422,'Duplicate domains rejected'); }
    $reject=request('/admin/transfers','POST',['key'=>Util::uuid(),'action'=>'reject','transfer_id'=>$private]);
    check($reject->getStatusCode()===303,'Administrator can reject for losing reseller');
    $rejectJob=Transfers::get($pdo,$admin,$private)['latest_job_id'];
    check(Ledger::fetchJob($pdo,$rejectJob,$b)['command']==='domain.transfer','Admin decision runs under actual owner');
    Ledger::completeJob($pdo,$rejectJob,'succeeded',['code'=>1000,'msg'=>'Rejected'],1000);
    check(Transfers::get($pdo,$admin,$private)['tr_status']==='clientRejected','Outgoing rejection recorded');
    check(value("SELECT active FROM object_ownership WHERE object_name='private.com'")==='1','Reject preserves losing ownership');
    $newQuery=Ledger::createJob($pdo,$b,$registry,'domain.transfer','next-outgoing-query',['domainname'=>'private.com','op'=>'query']);
    Ledger::completeJob($pdo,$newQuery['job_id'],'succeeded',['code'=>1000,'name'=>'private.com','trStatus'=>'pending','reID'=>'EXTERNAL','acID'=>'OUR-REGISTRAR','reDate'=>'2026-11-18T08:00:00Z'],1000);
    check(value("SELECT COUNT(*) FROM domain_transfers WHERE domainname='private.com'")==='2','New registry request date creates separate history');
    $newOut=Namingo\ResellerApi\PanelData::rows($pdo,"SELECT id FROM domain_transfers WHERE domainname='private.com' AND status='pending'")[0]['id'];
    $auto=Ledger::createJob($pdo,$b,$registry,'domain.transfer','auto-approval-query',['domainname'=>'private.com','op'=>'query']);
    Ledger::completeJob($pdo,$auto['job_id'],'succeeded',['code'=>1000,'name'=>'private.com','trStatus'=>'serverApproved','reID'=>'EXTERNAL','acID'=>'OUR-REGISTRAR','reDate'=>'2026-11-18T08:00:00Z'],1000);
    check(Transfers::get($pdo,$admin,$newOut)['tr_status']==='serverApproved','Automatic registry approval recorded');
    check(value("SELECT COUNT(*) FROM object_ownership WHERE object_name='private.com'")==='0','Automatic transfer out releases ownership');
    // Re-imported local ownership must survive a historical index rebuild.
    $pdo->prepare("INSERT INTO object_ownership (registry_id,object_type,object_name,reseller_id,active) VALUES (?,'domain','private.com',?,1)")->execute([$registry,$b]);
    for($i=0;$i<51;++$i) {
        $id=Util::uuid(); $pdo->prepare("INSERT INTO domain_transfers (id,registry_id,reseller_id,domainname,direction,observation_key,latest_job_id,status) VALUES (?,?,?,?,'incoming',?,?,'rejected')")
            ->execute([$id,$registry,$b,'page-'.$i.'.com',hash('sha256',$id),$direct['job_id']]);
    }
    check(count(Transfers::listing($pdo,$admin,1)['rows'])===50 && Transfers::listing($pdo,$admin,1)['next'],'Transfers paginate');
    $pdo->exec('DROP TABLE domain_transfers');
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/008_transfers.sql'));
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/008_transfers.sql'));
    Transfers::backfill($pdo); Transfers::backfill($pdo);
    check(value("SELECT COUNT(*) FROM domain_transfers WHERE direction='incoming'")==='3','Upgrade backfills request history idempotently');
    check(value("SELECT active FROM object_ownership WHERE object_name='private.com'")==='1','History backfill never changes present-day ownership');
    echo "PASS: {$checks} transfer workflow assertions\n";
} finally {
    $pdo=null; $dbAdmin->exec("DROP DATABASE `$db`"); session_destroy();
    foreach(glob($sessionRoot.'/var/panel-sessions/*')?:[] as $file){unlink($file);}
    @rmdir($sessionRoot.'/var/panel-sessions'); @rmdir($sessionRoot.'/var'); @rmdir($sessionRoot);
}
