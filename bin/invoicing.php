#!/usr/bin/env php
<?php

declare(strict_types=1);

use Namingo\ResellerApi\{Config,Db,Invoices,InvoiceStorage,InvoiceMailer,PanelData,ApiException};

require dirname(__DIR__).'/vendor/autoload.php';
Config::load(dirname(__DIR__));
umask(0027);
try {
    if (PHP_SAPI!=='cli') { throw new RuntimeException('Run this command from CLI.'); }
    $options=getopt('',['month:','no-email','help']);
    if (isset($options['help'])) {
        echo "Usage: php bin/invoicing.php [--month=YYYY-MM] [--no-email]\nGenerates the previous completed UTC calendar month by default. Safe to rerun.\n"; exit(0);
    }
    if (Config::get('INVOICING_ENABLED','0')!=='1') { echo "Invoicing is disabled; complete issuer, VAT and SMTP setup before enabling it.\n"; exit(0); }
    [$start,$end]=Invoices::period($options['month'] ?? null); $month=substr($start,0,7);
    $pdo=Db::pdo(); $storage=new InvoiceStorage(true); $failed=0; $cursor='';
    do {
        $accounts=PanelData::rows($pdo,'SELECT id FROM resellers WHERE role = 1 AND created_at < ? AND id > ? ORDER BY id LIMIT 100',[$end,$cursor]);
        foreach ($accounts as $account) {
            $cursor=$account['id'];
            try {
                $invoice=Invoices::issue($pdo,$cursor,$month,$storage);
                if (!isset($options['no-email'])) {
                    InvoiceMailer::deliver($pdo,$invoice['id'],$storage);
                    $delivery=PanelData::rows($pdo,'SELECT email_status FROM invoices WHERE id = ?',[$invoice['id']])[0]['email_status'];
                    if (in_array($delivery,['sending','unknown'],true)) { throw new RuntimeException('Email delivery needs review.'); }
                }
                echo $invoice['invoice_number']." ready\n";
            } catch (Throwable $e) {
                ++$failed;
                fwrite(STDERR,'Invoice account '.$cursor.' failed: '.($e instanceof ApiException ? $e->errorCode : get_class($e)).". Review billing configuration and invoice status.\n");
            }
        }
    } while(count($accounts)===100);
    // Retry definitely unsent messages from older issued invoices as well; uncertain sends require review.
    if (!isset($options['no-email'])) {
        foreach (PanelData::rows($pdo,"SELECT id FROM invoices WHERE status = 'issued' AND email_status = 'pending' ORDER BY issued_at LIMIT 100") as $row) {
            try { InvoiceMailer::deliver($pdo,$row['id'],$storage); }
            catch(Throwable) { ++$failed; fwrite(STDERR,'Invoice email '.$row['id']." needs review.\n"); }
        }
    }
    exit($failed===0 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR,'Invoicing failed: '.($e instanceof ApiException ? $e->errorCode : get_class($e)).". Check configuration and private storage.\n"); exit(1);
}
