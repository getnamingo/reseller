<?php

declare(strict_types=1);

namespace Namingo\ResellerApi;

use PDO;

final class BillingProfile
{
    public const COLUMNS = [
        'company_number' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'vat_number' => "VARCHAR(32) NOT NULL DEFAULT ''",
        'billing_email' => "VARCHAR(254) NOT NULL DEFAULT ''",
        'billing_city' => "VARCHAR(128) NOT NULL DEFAULT ''",
        'billing_postcode' => "VARCHAR(32) NOT NULL DEFAULT ''",
        'invoice_tax_mode' => "VARCHAR(24) NOT NULL DEFAULT 'unconfigured'",
        'invoice_vat_rate' => "DECIMAL(5,2) NOT NULL DEFAULT 0.00",
        'invoice_tax_note' => "VARCHAR(1000) NOT NULL DEFAULT ''",
    ];
    public const LIMITS = ['company_number'=>64, 'vat_number'=>32, 'billing_email'=>254, 'billing_city'=>128,
        'billing_postcode'=>32, 'invoice_tax_mode'=>24, 'invoice_vat_rate'=>6, 'invoice_tax_note'=>1000];
    public const CUSTOMER_FIELDS = ['company','company_number','vat_number','billing_email','address','billing_city','billing_postcode','country_code'];

    public static function validate(array $profile): void
    {
        foreach (['company','company_number','address','billing_city','billing_postcode','country_code','billing_email'] as $field) {
            if (!is_string($profile[$field] ?? null) || trim($profile[$field]) === '') {
                throw new ApiException(422, 'billing_incomplete', 'Complete the company billing profile before invoicing: ' . $field . '.');
            }
        }
        if (!filter_var($profile['billing_email'], FILTER_VALIDATE_EMAIL) || preg_match('/^[A-Z]{2}$/D', $profile['country_code']) !== 1) {
            throw new ApiException(422, 'billing_incomplete', 'Enter a billing email and two-letter country code.');
        }
        if (preg_match('/^(?:[0-9]|[1-9][0-9])(?:\.[0-9]{1,2})?$/D', (string)($profile['invoice_vat_rate'] ?? '')) !== 1) {
            throw new ApiException(422, 'billing_tax_invalid', 'Configure a valid VAT percentage.');
        }
        $mode = $profile['invoice_tax_mode'] ?? 'unconfigured';
        if (!in_array($mode, ['standard','reverse_charge','exempt'], true)) {
            throw new ApiException(422, 'billing_tax_unconfigured', 'An administrator must configure the invoice VAT treatment.');
        }
        if ($mode === 'standard' && (float)$profile['invoice_vat_rate'] === 0.0 && trim((string)($profile['invoice_tax_note'] ?? '')) === '') {
            throw new ApiException(422, 'billing_tax_invalid', 'A zero VAT rate requires a tax basis note.');
        }
        if ($mode === 'reverse_charge' && empty($profile['vat_number'])) {
            throw new ApiException(422, 'billing_vat_required', 'Reverse charge requires the customer VAT number.');
        }
        if ($mode !== 'standard' && (trim((string) ($profile['invoice_tax_note'] ?? '')) === '' || (float) ($profile['invoice_vat_rate'] ?? 0) !== 0.0)) {
            throw new ApiException(422, 'billing_tax_invalid', 'Reverse charge and exemption require a legal tax note and a zero rate.');
        }
    }

    public static function saveCustomer(PDO $pdo, string $owner, array $input): void
    {
        $changes = [];
        foreach (self::CUSTOMER_FIELDS as $field) {
            if (!is_string($input[$field] ?? null)) { throw new ApiException(422, 'invalid_billing', 'Complete the billing form.'); }
            $changes[$field] = $input[$field];
        }
        foreach (['company','company_number','address','billing_city','billing_postcode','country_code','billing_email'] as $field) {
            if (trim($changes[$field]) === '') { throw new ApiException(422, 'billing_incomplete', 'Complete every required company billing field.'); }
        }
        Admin::updateAccount($pdo, $owner, $changes);
    }
}
