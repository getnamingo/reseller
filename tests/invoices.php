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
use Namingo\ResellerApi\{Auth, Crypto, DomainParams, Domains, Ledger, Ownership, PanelApi, PanelController, PanelSession, Invoices, InvoiceStorage, InvoiceMailer, InvoiceDocument, BillingProfile, Admin, Util};
use Nyholm\Psr7\{Response, ServerRequest};
use Slim\Views\Twig;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/DomainParams.php';
require_once dirname(__DIR__) . '/src/Domains.php';
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_STRINGIFY_FETCHES=>true];
$dbAdmin = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$db = 'reseller_invoices_' . bin2hex(random_bytes(6)); $dbAdmin->exec("CREATE DATABASE `$db`");
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
    $pdo->exec("UPDATE resellers SET created_at='2020-01-01 00:00:00'");
    $dataRoot=$sessionRoot.'/data'; mkdir($dataRoot,0750,true); $_ENV['DATA_DIRECTORY']=$dataRoot;
    $_ENV['SMTP_HOST']='localhost'; $_ENV['SMTP_PORT']='2525'; $_ENV['SMTP_SECURITY']='none';
    foreach (['NAME'=>'Namingo Services ЕООД','COMPANY_NUMBER'=>'123456789','VAT_NUMBER'=>'BG123456789','ADDRESS'=>'10 Example Street',
        'CITY'=>'Sofia','POSTCODE'=>'1000','COUNTRY'=>'BG','EMAIL'=>'billing@example.com','TAX_CURRENCY'=>'EUR'] as $k=>$v) { $_ENV['INVOICE_SELLER_'.$k]=$v; }
    $details=['company'=>'Клиент & Company Ltd','company_number'=>'987654321','vat_number'=>'BG987654321','billing_email'=>'accounts@example.net',
        'address'=>'42 Test Street','billing_city'=>'Plovdiv','billing_postcode'=>'4000','country_code'=>'BG','invoice_tax_mode'=>'standard','invoice_vat_rate'=>'20.00','invoice_tax_note'=>''];
    Admin::updateAccount($pdo,$a,$details); Admin::updateAccount($pdo,$b,$details+[]);
    $month=(new DateTimeImmutable('first day of previous month',new DateTimeZone('UTC')))->format('Y-m');
    [$start,$end]=Invoices::period($month);
    $insert=$pdo->prepare('INSERT INTO ledger_entries (id,reseller_id,kind,available_delta,held_delta,available_after,held_after,currency,description,created_at) VALUES (?,?,?,0,?,100,0,?,?,?)');
    foreach ([['charge','-12.0000',$start.' 00:00:00'],['charge','-0.0001',$start.' 01:00:00'],['hold','50.0000',$start.' 02:00:00'],
        ['deposit','0.0000',$start.' 03:00:00'],['charge','-100.0000',$end.' 00:00:00'],['charge','-99.0000','2020-01-01 00:00:00']] as [$kind,$amount,$date]) {
        $insert->execute([Util::uuid(),$a,$kind,$amount,'EUR','Monthly domain service',$date]);
    }
    $storage=new InvoiceStorage(true); $actor=['id'=>$a,'role'=>1]; $admin=['id'=>$root,'role'=>0];
    $invoice=Invoices::issue($pdo,$a,$month,$storage); $snapshot=Invoices::decode($invoice);
    check(count($snapshot['lines'])===2,'Only charge ledger entries within closed month invoiced');
    check($snapshot['net']==='10.0001' && $snapshot['vat']==='2.0000' && $snapshot['total']==='12.0001','Exact four-decimal VAT-inclusive ledger arithmetic');
    check($invoice['status']==='issued' && str_starts_with($storage->read($invoice),'%PDF-'),'Real PDF generated with requested library');
    $large=$snapshot;$large['lines']=[];
    for($i=0;$i<180;++$i){$line=$snapshot['lines'][0];$line['description']='Registry / domain.renew / domain-'.str_pad((string)$i,3,'0',STR_PAD_LEFT).'.example.com';$large['lines'][]=$line;}
    $large['net']='1800.0000';$large['vat']='360.0000';$large['total']='2160.0000';
    $largePdf=InvoiceDocument::pdf($large);
    check(preg_match_all('~/Type\s*/Page\b~',$largePdf)>=3,'Large invoice paginates real PDF tables');
    $same=Invoices::issue($pdo,$a,$month,$storage);
    check($same['id']===$invoice['id'] && value('SELECT COUNT(*) FROM invoices')==='1','Repeated generation reuses invoice and number');
    $cipher=file_get_contents($dataRoot.'/invoices/'.$invoice['id'].'.enc');
    check(str_starts_with($cipher,'NINV1') && !str_contains($cipher,'%PDF-') && !str_contains($cipher,'Test Street'),'Invoice file encrypted before reaching storage');
    check(!str_contains($invoice['snapshot_cipher'],'Test Street'),'Billing snapshot encrypted');
    check((fileperms($dataRoot.'/invoices/'.$invoice['id'].'.enc') & 0027)===0,'Stored invoice not publicly readable or group-writable');
    $tampered=$cipher; $tampered[40]=chr(ord($tampered[40])^1); file_put_contents($dataRoot.'/invoices/'.$invoice['id'].'.enc',$tampered);
    try { $storage->read($invoice);check(false,'Tampering must fail'); } catch(RuntimeException){check(true,'Ciphertext tampering rejected');}
    file_put_contents($dataRoot.'/invoices/'.$invoice['id'].'.enc',$cipher);
    chmod($dataRoot.'/invoices',0777);clearstatcache();
    try{new InvoiceStorage();check(false,'Unsafe storage must fail');}catch(RuntimeException){check(true,'World-writable storage refused');}
    chmod($dataRoot.'/invoices',0750);clearstatcache();
    Admin::updateAccount($pdo,$a,['company'=>'Changed company','billing_email'=>'new@example.net']);
    check(Invoices::decode(Invoices::issue($pdo,$a,$month,$storage))['customer']['company']==='Клиент & Company Ltd','Issued customer details immutable');
    $count=0; $message='';
    $send=static function($mail) use(&$count,&$message):bool {++$count;$mail->preSend();$message=$mail->getSentMIMEMessage();return true;};
    check(InvoiceMailer::deliver($pdo,$invoice['id'],$storage,false,$send),'PHPMailer delivery callback accepted');
    check(!InvoiceMailer::deliver($pdo,$invoice['id'],$storage,false,$send) && $count===1,'Reruns never resend sent invoices');
    check(str_contains($message,'accounts@example.net') && !str_contains($message,'new@example.net') && str_contains($message,'application/pdf'),'Email uses snapshot recipient and PDF attachment');
    check(str_contains($message,'Message-ID: <invoice.'.$invoice['id'].'@example.com>'),'Stable message ID');
    Admin::updateAccount($pdo,$b,['invoice_tax_note'=>"Tax basis\nAdditional context"]);
    check(value('SELECT invoice_tax_note FROM resellers WHERE id=?',[$b])==="Tax basis\nAdditional context",'Multiline tax notes retained safely');
    $zero=Invoices::issue($pdo,$b,$month,$storage);
    check(Invoices::decode($zero)['total']==='0.0000','Reseller with no activity receives zero monthly invoice');
    check($zero['invoice_number']!==$invoice['invoice_number'],'Invoice numbers unique across accounts');
    try{InvoiceMailer::deliver($pdo,$zero['id'],$storage,false,static function(){throw new RuntimeException('Mock SMTP interruption');});check(false,'Failure expected');}
    catch(RuntimeException){check(value('SELECT email_status FROM invoices WHERE id=?',[$zero['id']])==='unknown','Uncertain delivery flagged');}
    check(!InvoiceMailer::deliver($pdo,$zero['id'],$storage,false,$send),'Uncertain send never automatically retried');
    check(InvoiceMailer::deliver($pdo,$zero['id'],$storage,true,$send),'Explicit reviewed retry allowed');
    $pdo->prepare("UPDATE invoices SET status='prepared',pdf_hash=NULL WHERE id=?")->execute([$zero['id']]);
    $resume=Invoices::issue($pdo,$b,$month,$storage);
    check($resume['id']===$zero['id'] && $resume['invoice_number']===$zero['invoice_number'] && $resume['status']==='issued','Interrupted PDF preparation resumes original invoice');
    $panel=new PanelController($pdo,Twig::create(dirname(__DIR__).'/panel/views',['cache'=>false,'autoescape'=>'html']),new PanelApi(new Client()));
    PanelSession::login(Auth::login($pdo,'reseller-a',$password,'127.0.0.1'));
    $html=(string)request('/invoices')->getBody();
    check(str_contains($html,$invoice['invoice_number']) && !str_contains($html,$zero['invoice_number']),'Invoice listing isolates accounts');
    check(request('/invoices/'.$zero['id'])->getStatusCode()===404 && request('/invoices/'.$zero['id'].'/pdf')->getStatusCode()===404,'Foreign HTML and PDF access rejected');
    check(request('/admin/invoices')->getStatusCode()===403,'Reseller denied admin invoice listing');
    $response=request('/invoices/'.$invoice['id'].'/pdf?download=1');
    check($response->getStatusCode()===200 && str_starts_with((string)$response->getBody(),'%PDF-') && str_contains($response->getHeaderLine('Content-Disposition'),'attachment'),'Authenticated PDF download');
    check(str_contains($response->getHeaderLine('Cache-Control'),'no-store'),'Private invoice response not cached');
    check(str_contains((string)request('/invoices/'.$invoice['id'])->getBody(),'Клиент &amp; Company'),'Invoice view escaped and uses original details');
    check(request('/invoices/'.$invoice['id'],'POST',['action'=>'retry-email','confirmed'=>'1'])->getStatusCode()===403,'Reseller cannot resend emails');
    $billingHtml=(string)request('/account/billing')->getBody();
    check(str_contains($billingHtml,'<select class="form-select" name="country_code"') && str_contains($billingHtml,'<option value="BG" selected>Bulgaria (BG)</option>'),'Billing dropdown selects saved ISO country');
    $self=$details;unset($self['invoice_tax_mode'],$self['invoice_vat_rate'],$self['invoice_tax_note']);
    $self['country_code']='DE';
    $self['invoice_vat_rate']='0.00';$self['invoice_tax_mode']='exempt';
    check(request('/account/billing','POST',$self,false)->getStatusCode()===403,'Billing profile CSRF required');
    check(request('/account/billing','POST',$self)->getStatusCode()===303,'Reseller billing details editable');
    check(value('SELECT country_code FROM resellers WHERE id=?',[$a])==='DE' && str_contains((string)request('/account/billing')->getBody(),'<option value="DE" selected>Germany (DE)</option>'),'Billing saves code and reselects country after edit');
    check(value('SELECT invoice_vat_rate FROM resellers WHERE id=?',[$a])==='20.00','Reseller cannot change tax treatment via forged fields');
    check(value('SELECT invoice_tax_mode FROM resellers WHERE id=?',[$a])==='unconfigured','Legal identity change requires administrator VAT review');
    PanelSession::login(Auth::login($pdo,'admin',$password,'127.0.0.1'));
    $accountHtml=(string)request('/admin/resellers/'.$a)->getBody();
    check(str_contains($accountHtml,'<select class="form-select" name="country_code"') && str_contains($accountHtml,'<option value="DE" selected>Germany (DE)</option>'),'Manage reseller selects current account country');
    $html=(string)request('/admin/invoices')->getBody();
    check(str_contains($html,$invoice['invoice_number']) && str_contains($html,$zero['invoice_number']),'Administrator sees all invoices');
    check(!str_contains((string)request('/invoices')->getBody(),$invoice['invoice_number']),'Administrator own workspace stays own-account scoped');
    check(request('/admin/invoices/'.$invoice['id'],'POST',['action'=>'retry-email'])->getStatusCode()===422,'Admin email resend requires reviewed confirmation');
    check(str_contains((string)request('/admin/resellers/'.$a)->getBody(),'name="vat_number"'),'Admin profile exposes invoicing details');
    $customer=Namingo\ResellerApi\PanelData::rows($pdo,'SELECT * FROM resellers WHERE id=?',[$a])[0];
    $customer['invoice_tax_mode']='reverse_charge';$customer['invoice_vat_rate']='0.00';$customer['invoice_tax_note']='Article 196, VAT Directive';
    $rc=Invoices::snapshot($customer,Invoices::issuer(),[],$start,$end);
    check(str_contains($rc['tax_note'],'Reverse charge'),'Reverse-charge wording retained');
    $customer['vat_number']='';
    try { Invoices::snapshot($customer,Invoices::issuer(),[],$start,$end);check(false,'VAT number required'); }
    catch(Namingo\ResellerApi\ApiException){check(true,'Reverse charge without customer VAT ID blocked');}
    $customer['invoice_tax_mode']='unconfigured';
    try { Invoices::snapshot($customer,Invoices::issuer(),[],$start,$end);check(false,'Unconfigured tax blocked'); }
    catch(Namingo\ResellerApi\ApiException){check(true,'Unconfigured tax blocks invoice');}
    $customer['invoice_tax_mode']='standard';$customer['invoice_vat_rate']='20.00';$customer['currency']='USD';
    $charges=[['id'=>Util::uuid(),'currency'=>'USD','held_delta'=>'-12.0000','created_at'=>$start,'description'=>'Service']];
    try { Invoices::snapshot($customer,Invoices::issuer(),$charges,$start,$end);check(false,'Missing FX blocked'); }
    catch(Namingo\ResellerApi\ApiException){check(true,'VAT reporting currency needs explicit month FX');}
    $_ENV['INVOICE_TAX_FX_RATES']=json_encode([$month=>['USD'=>'0.91']]);
    $fx=Invoices::snapshot($customer,Invoices::issuer(),$charges,$start,$end);
    check($fx['tax_currency_vat']==='1.8200' && $fx['total']==='12.0000','Frozen VAT currency conversion preserves charged total');
    try{Invoices::period(gmdate('Y-m'));check(false,'Current month blocked');}catch(Namingo\ResellerApi\ApiException){check(true,'Incomplete month refused');}
    // Upgrade adds billing columns to legacy accounts without losing identity or issuing invoices.
    foreach(array_keys(BillingProfile::COLUMNS) as $column){$pdo->exec('ALTER TABLE resellers DROP COLUMN '.$column);}
    require dirname(__DIR__).'/database/009_invoicing.php';require dirname(__DIR__).'/database/009_invoicing.php';
    check(value('SELECT username FROM resellers WHERE id=?',[$a])==='reseller-a' && value('SELECT COUNT(*) FROM invoices')==='2','Migration preserves account and invoice data and can repeat');
    echo "PASS: {$checks} invoice workflow assertions\n";
} finally {
    $pdo=null;$dbAdmin->exec("DROP DATABASE `$db`");session_destroy();
    foreach(glob($dataRoot.'/invoices/*')?:[] as $file){unlink($file);}
    @rmdir($dataRoot.'/invoices');@rmdir($dataRoot);
    foreach(glob($sessionRoot.'/var/panel-sessions/*')?:[] as $file){unlink($file);}
    @rmdir($sessionRoot.'/var/panel-sessions');@rmdir($sessionRoot.'/var');@rmdir($sessionRoot);
}
