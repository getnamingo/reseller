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

use Namingo\ResellerApi\{ApiException,Auth,Crypto,Util};
require dirname(__DIR__).'/vendor/autoload.php';
$checks=0;
function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function denied(string $code,callable $call):void{try{$call();}catch(ApiException $e){check($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);}
foreach([
    ['198.51.100.1','203.0.113.1','', '198.51.100.1'],
    ['127.0.0.1','203.0.113.1','', '127.0.0.1'],
    ['198.51.100.1','203.0.113.1','127.0.0.1','198.51.100.1'],
    ['127.0.0.1','203.0.113.1','127.0.0.1','203.0.113.1'],
    ['::1','2001:0db8:0000::1','127.0.0.1, ::1','2001:db8::1'],
    ['127.0.0.1',' 203.0.113.1 ','127.0.0.1','203.0.113.1'],
    ['2001:0db8:0000::2',null,'','2001:db8::2'],
    ['127.0.0.2','203.0.113.1','127.0.0.1','127.0.0.2'],
    ['invalid-peer','203.0.113.1','127.0.0.1','unknown'],
] as [$peer,$header,$trusted,$expected])check(Util::clientIp($peer,$header,$trusted)===$expected,'Resolve trusted peer/address or retain socket identity');
foreach([null,[],42,'','unknown','203.0.113.1, 198.51.100.1','[2001:db8::1]:443','203.0.113.1:443',"203.0.113.1\r\nX-Fake: yes"] as $header){check(Util::clientIp('127.0.0.1',$header,'127.0.0.1')==='127.0.0.1','Malformed, missing or multi-hop headers retain peer identity');}
foreach(['*','127.0.0.0/8','localhost','0.0.0.0/0'] as $trusted){try{Util::clientIp('127.0.0.1',null,$trusted);throw new LogicException('Invalid proxy config accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'API_TRUSTED_PROXIES'),'Invalid proxy configuration fails startup');}}
$dsn=getenv('TEST_MYSQL_DSN')?:'mysql:host=127.0.0.1;port=3306';$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>true];
$admin=new PDO($dsn,getenv('TEST_MYSQL_USER')?:'root',getenv('TEST_MYSQL_PASSWORD')?:'',$options);$db='reseller_proxy_'.bin2hex(random_bytes(6));$admin->exec("CREATE DATABASE `$db`");$pdo=new PDO($dsn.';dbname='.$db,getenv('TEST_MYSQL_USER')?:'root',getenv('TEST_MYSQL_PASSWORD')?:'',$options);
$_ENV['APP_MASTER_KEY']='base64:'.base64_encode(random_bytes(32));$_ENV['API_LOGIN_LIMIT_PER_MINUTE']='2';
try{
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
    $id=Util::uuid();$password='correct horse battery staple';
    $pdo->prepare("INSERT INTO resellers(id,username,password_hash,currency) VALUES(?,'proxy-user',?,'EUR')")->execute([$id,password_hash($password,PASSWORD_ARGON2ID)]);
    $pdo->prepare("INSERT INTO wallets(reseller_id,currency) VALUES(?,'EUR')")->execute([$id]);
    $a=Util::clientIp('127.0.0.1','198.51.100.1','127.0.0.1');$b=Util::clientIp('127.0.0.1','198.51.100.2','127.0.0.1');
    for($i=0;$i<2;++$i)denied('invalid_credentials',fn()=>Auth::login($pdo,'not-an-account','incorrect',$a));
    denied('rate_limited',fn()=>Auth::login($pdo,'proxy-user',$password,$a));
    check(Auth::login($pdo,'proxy-user',$password,$b)['reseller_uuid']===$id,'Exhausting one proxied IP does not block another client');
    $audit=$pdo->query("SELECT ip_hash FROM audit_events WHERE action='auth.login_succeeded' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    check(hash_equals(Crypto::hmac($b),$audit) && !hash_equals(Crypto::hmac('127.0.0.1'),$audit),'Audit records retain resolved client identity');
    // Untrusted callers cannot rotate forwarded values to evade the persistent limiter.
    for($i=0;$i<2;++$i){$ip=Util::clientIp('203.0.113.50','198.51.100.'.($i+10),'127.0.0.1');denied('invalid_credentials',fn()=>Auth::login($pdo,'not-an-account','incorrect',$ip));}
    $ip=Util::clientIp('203.0.113.50','198.51.100.99','127.0.0.1');
    denied('rate_limited',fn()=>Auth::login($pdo,'proxy-user',$password,$ip));
    echo "PASS: {$checks} proxy identity assertions\n";
}finally{$pdo=null;$admin->exec("DROP DATABASE `$db`");}
