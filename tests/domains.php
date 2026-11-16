<?php

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
function drain(string $batch, array $actor, bool $hostExists = false): void {
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
                    'domain.info'=>['code'=>1000,'name'=>$name,'crDate'=>'2026-01-01','exDate'=>'2028-01-01','registrant'=>'OWNER','ns'=>['ns1.example.net'],'contact'=>[['type'=>'tech','id'=>'TECH']],'authInfo'=>'PrivateDomainSecret!123'],
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
    $count=value('SELECT COUNT(*) FROM epp_jobs');
    check(request('/domains','POST',$create)->getStatusCode()===303 && value('SELECT COUNT(*) FROM epp_jobs')===$count,'Repeated form resumes original jobs');
    $changed=$create; $changed['years']='2';
    check(request('/domains','POST',$changed)->getStatusCode()===409,'Changed payload cannot reuse key');
    $domain=Domains::owned($pdo,$actor,$registry,'one.com'); $detail='/domains/'.$registry.'/one.com';
    $html=(string)request($detail)->getBody();
    check(str_contains($html,'2028-01-01') && str_contains($html,'name="authInfo"') && str_contains($html,'name="dnssec_action"'),'Details and edit cards rendered from registry info');
    check(!str_contains($html,'PrivateDomainSecret!123'),'Cached authorization code not rendered in browser');
    check(request('/admin/domains')->getStatusCode()===403,'Admin route requires role zero');
    PanelSession::login(Auth::login($pdo,'reseller-b',$password,'127.0.0.1'));
    check(request($detail)->getStatusCode()===404 && request($detail.'?format=json')->getStatusCode()===404,'Foreign detail/polling hidden');
    check(request($detail,'POST',['action'=>'delete','key'=>Util::uuid(),'confirm'=>'yes'])->getStatusCode()===404,'Foreign mutations hidden');
    check(!str_contains((string)request('/domains')->getBody(),'one.com'),'Foreign list hidden');
    PanelSession::login(Auth::login($pdo,'admin',$password,'127.0.0.1'));
    check(str_contains((string)request('/admin/domains')->getBody(),'one.com'),'Admin sees all domains');
    check(request($detail)->getStatusCode()===404,'Admin reseller workspace is own-only');
    check(request('/admin/domains/'.$registry.'/one.com')->getStatusCode()===200,'Admin opens another reseller domain');
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
    $retry=$delete; $retry['key']=Util::uuid(); Domains::submit($pdo,$admin,$retry,'127.0.0.1',$domain); drain($retry['key'],$admin);
    check(value("SELECT domain_state FROM object_ownership WHERE registry_id=? AND object_name='one.com'",[$registry])==='deleted','Successful delete preserves the API redemption state');
    for($i=0;$i<55;++$i){$pdo->prepare("INSERT INTO object_ownership (registry_id,object_type,object_name,reseller_id,active) VALUES (?,'domain',?,?,1)")->execute([$registry,sprintf('page-%02d.com',$i),$b]);}
    $listing=Domains::listing($pdo,$admin,1); check(count($listing['rows'])===50 && $listing['next'],'Domain list pagination');
    check(count(Domains::listing($pdo,$actor,1)['rows'])===4,'Pagination keeps reseller scope');
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
