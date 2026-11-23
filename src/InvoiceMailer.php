<?php

/*
 * Namingo Reseller
 *
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the PolyForm Noncommercial License 1.0.0.
 * Commercial use requires separate written authorization.
 * For licensing inquiries, contact help@namingo.org.
 *
 * See the LICENSE file distributed with this software
 * for the full license terms.
 *
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

declare(strict_types=1);

namespace Namingo\ResellerApi;

use PHPMailer\PHPMailer\PHPMailer;
use PDO;

final class InvoiceMailer
{
    public static function message(array $invoice, string $id, string $pdf): PHPMailer
    {
        $mail=new PHPMailer(true); $mail->isSMTP();
        $host=Config::required('SMTP_HOST'); $security=Config::get('SMTP_SECURITY','tls');
        if (!in_array($security,['tls','smtps','none'],true) || preg_match('/^[A-Za-z0-9.:-]+$/D',$host)!==1
            || $security==='none' && !in_array($host,['localhost','127.0.0.1','::1'],true)) {
            throw new \RuntimeException('Configure SMTP with TLS, or an unencrypted loopback relay.');
        }
        $mail->Host=$host; $mail->Port=Config::int('SMTP_PORT',587); $mail->Timeout=30; $mail->CharSet='UTF-8';
        $mail->SMTPSecure=$security==='none' ? '' : ($security==='smtps' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS);
        $mail->SMTPAutoTLS=$security!=='none'; $mail->SMTPDebug=0;
        $mail->Username=Config::get('SMTP_USERNAME',''); $mail->SMTPAuth=$mail->Username!=='';
        if ($mail->SMTPAuth) { $mail->Password=Config::required('SMTP_PASSWORD'); }
        $mail->setFrom($invoice['seller']['email'],$invoice['seller']['name']);
        $mail->addAddress($invoice['customer']['billing_email'],$invoice['customer']['company']);
        $mail->Subject='Invoice '.$invoice['number'].' — '.substr($invoice['period_start'],0,7);
        $domain=substr(strrchr($invoice['seller']['email'],'@'),1);
        $mail->MessageID='<invoice.'.$id.'@'.$domain.'>';
        $mail->isHTML(true); $mail->Body=InvoiceDocument::emailHtml($invoice);
        $mail->AltBody='Invoice '.$invoice['number']."\nService period: ".$invoice['period_start'].' to '.$invoice['period_end']."\nTotal: ".$invoice['total'].' '.$invoice['currency']."\n".$invoice['payment_note']."\nThe PDF invoice is attached.";
        $mail->addStringAttachment($pdf,$invoice['number'].'.pdf',PHPMailer::ENCODING_BASE64,'application/pdf');
        return $mail;
    }

    /** A crash/SMTP uncertainty leaves sending/unknown for explicit administrator review. */
    public static function deliver(PDO $pdo, string $id, InvoiceStorage $storage, bool $retry = false, ?callable $send = null): bool
    {
        $lock='invoice-email:'.$id; $stmt=$pdo->prepare('SELECT GET_LOCK(?, 0)');$stmt->execute([$lock]);
        if ((string)$stmt->fetchColumn()!=='1') { return false; }
        try {
            $invoice=PanelData::rows($pdo,"SELECT * FROM invoices WHERE id = ? AND status = 'issued'",[$id])[0] ?? null;
            if ($invoice===null || $invoice['email_status']==='sent' || !$retry && $invoice['email_status']!=='pending') { return false; }
            // Prepare and verify before claiming delivery: configuration/file failures remain safely retryable.
            $mail=self::message(Invoices::decode($invoice),$id,$storage->read($invoice));
            $pdo->prepare("UPDATE invoices SET email_status = 'sending', email_attempts = email_attempts + 1 WHERE id = ?")->execute([$id]);
            try {
                $ok=$send===null ? $mail->send() : $send($mail);
                if (!$ok) { throw new \RuntimeException('SMTP delivery outcome is uncertain.'); }
                $pdo->prepare("UPDATE invoices SET email_status = 'sent', emailed_at = UTC_TIMESTAMP(6) WHERE id = ?")->execute([$id]);
                Audit::record($pdo,null,'invoice.email_sent','invoice',$id,null,[]);
                return true;
            } catch (\Throwable $e) {
                $pdo->prepare("UPDATE invoices SET email_status = 'unknown' WHERE id = ?")->execute([$id]);
                throw new \RuntimeException('Invoice email delivery needs administrator review.',0,$e);
            }
        } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
    }
}
