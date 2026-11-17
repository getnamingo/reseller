<?php

declare(strict_types=1);

namespace Namingo\ResellerApi;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class Invoices
{
    public static function period(?string $month = null): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $month ??= $now->modify('first day of previous month')->format('Y-m');
        if (preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $month) !== 1 || $month < '2000-01' || $month >= $now->format('Y-m')) {
            throw new ApiException(422, 'invalid_invoice_month', 'Choose a completed calendar month as YYYY-MM.');
        }
        $start = new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'));
        return [$start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d')];
    }

    public static function issuer(): array
    {
        $issuer = [];
        foreach (['name','company_number','address','city','postcode','country','email','tax_currency'] as $field) {
            $value = trim(Config::required('INVOICE_SELLER_' . strtoupper($field)));
            if ($value === '' || preg_match('/[\x00-\x1F\x7F]/u', $value) || mb_strlen($value) > 1000) { throw new \RuntimeException('Invalid invoice issuer configuration.'); }
            $issuer[$field] = $value;
        }
        $issuer['vat_number'] = trim(Config::get('INVOICE_SELLER_VAT_NUMBER', ''));
        if (!filter_var($issuer['email'], FILTER_VALIDATE_EMAIL) || preg_match('/^[A-Z]{2}$/D', $issuer['country']) !== 1 || preg_match('/^[A-Z]{3}$/D', $issuer['tax_currency']) !== 1) {
            throw new \RuntimeException('Invalid invoice issuer email, country or tax currency.');
        }
        return $issuer;
    }

    public static function snapshot(array $customer, array $issuer, array $charges, string $start, string $end): array
    {
        BillingProfile::validate($customer);
        $rate = BigDecimal::of($customer['invoice_vat_rate']);
        if (in_array($customer['invoice_tax_mode'], ['standard','reverse_charge'], true) && $issuer['vat_number'] === '') {
            throw new ApiException(422, 'issuer_vat_missing', 'The issuer VAT number is required for this VAT treatment.');
        }
        $net = BigDecimal::of('0.0000'); $vat = BigDecimal::of('0.0000'); $gross = BigDecimal::of('0.0000'); $lines = [];
        foreach ($charges as $charge) {
            if ($charge['currency'] !== $customer['currency']) { throw new \RuntimeException('Invoice ledger currency mismatch.'); }
            $total = BigDecimal::of($charge['held_delta'])->negated()->toScale(4);
            if ($total->isNegative()) { throw new \RuntimeException('Invalid service charge.'); }
            $base = $total->multipliedBy(100)->dividedBy($rate->plus(100), 4, RoundingMode::HalfUp);
            $tax = $total->minus($base);
            $lines[] = ['ledger_id'=>$charge['id'], 'date'=>substr($charge['created_at'],0,10), 'description'=>$charge['description'],
                'quantity'=>1, 'unit_net'=>(string)$base, 'net'=>(string)$base, 'vat'=>(string)$tax, 'gross'=>(string)$total];
            $net = $net->plus($base); $vat = $vat->plus($tax); $gross = $gross->plus($total);
        }
        $taxCurrency = $issuer['tax_currency']; $fx = '1';
        if (!$vat->isZero() && $taxCurrency !== $customer['currency']) {
            $rates = json_decode(Config::get('INVOICE_TAX_FX_RATES', '{}'), true, 16, JSON_THROW_ON_ERROR);
            $fx = $rates[substr($start,0,7)][$customer['currency']] ?? null;
            if (!is_string($fx) || preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,10})?$/D', $fx) !== 1 || !BigDecimal::of($fx)->isPositive()) {
                throw new ApiException(422, 'invoice_fx_missing', 'Configure the month-specific VAT conversion rate for this invoice currency.');
            }
        }
        return ['seller'=>$issuer, 'customer'=>array_intersect_key($customer, array_flip(BillingProfile::CUSTOMER_FIELDS)),
            'period_start'=>$start, 'period_end'=>(new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d'),
            'currency'=>$customer['currency'], 'tax_mode'=>$customer['invoice_tax_mode'], 'vat_rate'=>(string)$rate->toScale(2),
            'tax_note'=>($customer['invoice_tax_mode']==='reverse_charge' ? 'Reverse charge. ' : '') . $customer['invoice_tax_note'],
            'lines'=>$lines, 'net'=>(string)$net, 'vat'=>(string)$vat, 'total'=>(string)$gross,
            'tax_currency'=>$taxCurrency, 'tax_fx_rate'=>$fx, 'tax_currency_vat'=>(string)$vat->multipliedBy($fx)->toScale(4,RoundingMode::HalfUp),
            'payment_note'=>'Paid from prepaid wallet. No additional payment is due.'];
    }

    /** Reserve the number and immutable encrypted snapshot atomically; PDF work is resumable. */
    public static function issue(PDO $pdo, string $owner, string $month, InvoiceStorage $storage): array
    {
        [$start,$end] = self::period($month);
        $lock = 'invoice:' . $owner . ':' . $month;
        $stmt=$pdo->prepare('SELECT GET_LOCK(?, 0)'); $stmt->execute([$lock]);
        if ((string)$stmt->fetchColumn() !== '1') { throw new ApiException(409,'invoice_busy','Invoice generation is already running.'); }
        try {
            $rows=PanelData::rows($pdo,'SELECT * FROM invoices WHERE reseller_id = ? AND period_start = ?',[$owner,$start]);
            if ($rows === []) {
                $pdo->beginTransaction();
                try {
                    $customer=PanelData::rows($pdo,'SELECT * FROM resellers WHERE id = ? FOR UPDATE',[$owner])[0] ?? null;
                    if ($customer===null || (int)$customer['role']!==1 || $customer['created_at'] >= $end) { throw new ApiException(422,'invoice_account_unavailable','Reseller did not exist during this invoice period.'); }
                    $charges=PanelData::rows($pdo,"SELECT l.*, j.request_nonce, j.request_cipher, j.id AS epp_id, j.command, r.name AS registry_name FROM ledger_entries l
                        LEFT JOIN epp_jobs j ON j.id=l.job_id LEFT JOIN registries r ON r.id=j.registry_id
                        WHERE l.reseller_id = ? AND l.kind = 'charge' AND l.created_at >= ? AND l.created_at < ? ORDER BY l.created_at,l.id",[$owner,$start,$end]);
                    foreach ($charges as &$charge) {
                        if ($charge['epp_id'] !== null) {
                            $params=Ownership::params(['id'=>$charge['epp_id'],'request_nonce'=>$charge['request_nonce'],'request_cipher'=>$charge['request_cipher']]);
                            $charge['description']=$charge['registry_name'] . ' / ' . $charge['command'] . (isset($params['domainname']) ? ' / ' . $params['domainname'] : '');
                        }
                    }
                    unset($charge);
                    $snapshot=self::snapshot($customer,self::issuer(),$charges,$start,$end);
                    $series=Config::get('INVOICE_NUMBER_PREFIX','INV') . '-' . gmdate('Y');
                    if (preg_match('/^[A-Z0-9-]{1,32}$/D',$series)!==1) { throw new \RuntimeException('Invalid invoice number prefix.'); }
                    $pdo->prepare('INSERT IGNORE INTO invoice_sequences (series) VALUES (?)')->execute([$series]);
                    $seq=PanelData::rows($pdo,'SELECT next_number FROM invoice_sequences WHERE series = ? FOR UPDATE',[$series])[0]['next_number'];
                    $pdo->prepare('UPDATE invoice_sequences SET next_number = next_number + 1 WHERE series = ?')->execute([$series]);
                    $id=Util::uuid(); $snapshot['number']=$series . '-' . str_pad($seq,6,'0',STR_PAD_LEFT); $snapshot['issued_at']=gmdate('Y-m-d');
                    [$nonce,$cipher]=Crypto::encrypt(Util::json($snapshot),'invoice-snapshot:'.$id);
                    $pdo->prepare('INSERT INTO invoices (id,reseller_id,invoice_number,period_start,period_end,issued_at,currency,total,snapshot_nonce,snapshot_cipher) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),?,?,?,?)')
                        ->execute([$id,$owner,$snapshot['number'],$start,$end,$snapshot['currency'],$snapshot['total'],$nonce,$cipher]);
                    Audit::record($pdo,null,'invoice.created','invoice',$id,null,['reseller_id'=>$owner,'month'=>$month]);
                    $pdo->commit();
                } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
                $rows=PanelData::rows($pdo,'SELECT * FROM invoices WHERE id = ?',[$id]);
            }
            $invoice=$rows[0];
            if ($invoice['status']==='prepared') {
                $pdf=InvoiceDocument::pdf(self::decode($invoice));
                $storage->write($invoice['id'],$pdf);
                $pdo->prepare("UPDATE invoices SET pdf_hash = ?, status = 'issued' WHERE id = ? AND status = 'prepared'")->execute([hash('sha256',$pdf),$invoice['id']]);
                $invoice=PanelData::rows($pdo,'SELECT * FROM invoices WHERE id = ?',[$invoice['id']])[0];
            }
            return $invoice;
        } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
    }

    public static function decode(array $invoice): array
    {
        return json_decode(Crypto::decrypt($invoice['snapshot_nonce'],$invoice['snapshot_cipher'],'invoice-snapshot:'.$invoice['id']),true,128,JSON_THROW_ON_ERROR);
    }
    public static function get(PDO $pdo, array $actor, string $id): array
    {
        $sql='SELECT i.*,u.username FROM invoices i JOIN resellers u ON u.id=i.reseller_id WHERE i.id = ?'; $args=[$id];
        if ((int)$actor['role']!==0) { $sql.=' AND i.reseller_id = ?'; $args[]=$actor['id']; }
        $row=PanelData::rows($pdo,$sql,$args)[0] ?? null;
        if ($row===null) { throw new ApiException(404,'invoice_not_found','Invoice was not found.'); }
        return $row;
    }
    public static function listing(PDO $pdo, array $actor, int $page): array
    {
        $where=(int)$actor['role']===0 ? '' : ' WHERE i.reseller_id = ?';
        $rows=PanelData::rows($pdo,'SELECT i.id,i.invoice_number,i.period_start,i.issued_at,i.currency,i.total,i.status,i.email_status,u.username
            FROM invoices i JOIN resellers u ON u.id=i.reseller_id'.$where.' ORDER BY i.issued_at DESC,i.invoice_number DESC LIMIT 51 OFFSET '.(($page-1)*50), $where==='' ? [] : [$actor['id']]);
        $next=count($rows)>50; if($next){array_pop($rows);} return ['rows'=>$rows,'next'=>$next];
    }
}
