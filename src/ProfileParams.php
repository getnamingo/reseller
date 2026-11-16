<?php

declare(strict_types=1);

namespace Namingo\ResellerApi;

/** Local parameter requirements of the pinned pinga/tembo 1.1.23 serializers. */
final class ProfileParams
{
    private const REQUIRED = [
        'hostCheck' => 'hostname',
        'hostInfo' => 'hostname',
        'hostCreate' => 'hostname',
        'hostUpdate' => 'hostname',
        'hostDelete' => 'hostname',
        'contactCheck' => 'contact',
        'contactInfo' => 'contact',
        'contactCreate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email authInfoPw',
        'contactUpdate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email',
        'contactUpdateAuthinfo' => 'contactid authInfo',
        'contactDelete' => 'contact',
        'domainCheck' => 'domains',
        'domainInfo' => 'domainname',
        'domainUpdateNS' => 'domainname',
        'domainUpdateContact' => 'domainname contacttype',
        'domainUpdateStatus' => 'domainname command',
        'domainUpdateAuthinfo' => 'domainname authInfo',
        'domainUpdateDNSSEC' => 'domainname command',
        'domainTransfer' => 'domainname op',
        'domainCreate' => 'domainname period authInfoPw',
        'domainCreateDNSSEC' => 'domainname period authInfoPw dnssec_records',
        'domainRenew' => 'domainname regperiod',
        'domainDelete' => 'domainname',
        'domainRestore' => 'domainname',
    ];

    private const OVERRIDES = [
        'EE' => [
            'contactCreate' => 'type id firstname lastname companyname address1 city state postcode country fullphonenumber email authInfoPw ident_type ident_cc ident_id ident_pdf',
            'contactUpdate' => 'type id firstname lastname companyname address1 city state postcode country fullphonenumber email ident_pdf',
            'contactDelete' => 'contact ident_type ident_cc ident_id ident_pdf',
            'domainUpdateNS' => 'domainname nss',
            'domainUpdateContact' => 'domainname contacttype ident_pdf',
            'domainTransfer' => 'domainname op ident_pdf',
            'domainCreate' => 'domainname period registrant contacts authInfoPw',
            'domainCreateDNSSEC' => 'domainname period registrant contacts dnssec_records authInfoPw',
            'domainDelete' => 'domainname ident_pdf',
            'domainRestore' => null,
        ],
        'EU' => [
            'hostCheck' => null,
            'hostInfo' => null,
            'hostCreate' => null,
            'hostUpdate' => null,
            'hostDelete' => null,
            'contactCheck' => null,
            'contactCreate' => 'id euType firstname lastname companyname address1 address2 city state postcode country fullphonenumber email authInfoPw',
            'contactUpdate' => 'id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email',
            'domainUpdateNS' => 'domainname nss',
            'domainCreate' => 'domainname period registrant contacts authInfoPw',
            'domainRestore' => null,
        ],
        'FI' => [
            'contactCreate' => 'type role isfinnish id firstname lastname companyname registernumber identity birthDate address1 address2 city state postcode country fullphonenumber email authInfoPw',
            'contactUpdate' => 'isfinnish type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email',
            'domainUpdateNS' => 'domainname nss',
            'domainCreate' => 'domainname period registrant authInfoPw',
            'domainCreateDNSSEC' => 'domainname period registrant authInfoPw dnssec_records',
            'domainRestore' => null,
        ],
        'FRED' => [
            'hostCreate' => 'hostname ipaddress hostname2 ipaddress2 nsid nstech',
            'hostUpdate' => 'newhostname oldhostname newipaddress nsid nstech_new nstech_old',
            'domainUpdateStatus' => null,
            'domainCreate' => 'domainname period nsid registrant admin authInfoPw',
            'domainUpdateDNSSEC' => null,
            'domainCreateDNSSEC' => null,
            'domainRestore' => null,
        ],
        'GE' => [
            'hostCheck' => null,
            'hostInfo' => null,
            'hostCreate' => null,
            'hostUpdate' => null,
            'hostDelete' => null,
            'contactCreate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email authInfoPw nin',
            'domainUpdateNS' => 'domainname nss',
            'domainUpdateContact' => null,
            'domainUpdateAuthinfo' => null,
            'domainRestore' => null,
        ],
        'GR' => [
            'contactCreate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email',
            'domainUpdateStatus' => null,
            'domainCreate' => 'domainname period',
            'domainCreateDNSSEC' => 'domainname period registrant contacts dnssec_records',
            'domainDelete' => 'domainname password',
            'domainRestore' => null,
        ],
        'HK' => [
            'contactCreate' => 'type id firstname lastname companyname address1 city state postcode country fullphonenumber fax email authInfoPw surname cttype type_e docType docNum docCC industry mobile',
            'contactUpdate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email docType docNum docCC industry mobile',
            'domainCreate' => 'domainname period registrant contacts',
            'domainCreateDNSSEC' => 'domainname period registrant contacts authInfoPw dnssec_records',
        ],
        'HR' => [
            'hostCheck' => null,
            'hostInfo' => null,
            'hostCreate' => null,
            'hostUpdate' => null,
            'hostDelete' => null,
            'contactCreate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email authInfoPw nin_type nin',
            'domainUpdateNS' => 'domainname nss',
            'domainCreate' => 'domainname period registrant contacts authInfoPw',
            'domainCreateDNSSEC' => 'domainname period registrant contacts authInfoPw dnssec_records',
            'domainRestore' => null,
        ],
        'IS' => [
            'contactCheck' => null,
            'contactCreate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email authInfoPw isType',
            'contactUpdate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email old_pw',
            'contactUpdateAuthinfo' => 'contactid authInfo old_pw',
            'contactDelete' => null,
            'domainTransfer' => null,
            'domainCreate' => 'domainname period authInfoPw domain_card_id domain_card_cvc',
            'domainCreateDNSSEC' => 'domainname period authInfoPw domain_card_id domain_card_cvc dnssec_records',
            'domainRenew' => 'domainname regperiod domain_card_id domain_card_cvc',
        ],
        'IT' => [
            'hostCheck' => null,
            'hostInfo' => null,
            'hostCreate' => null,
            'hostUpdate' => null,
            'hostDelete' => null,
            'contactCreate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber fax email authInfoPw consentForPublishing',
            'contactUpdate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber fax email consentForPublishing',
            'domainUpdateNS' => 'domainname nss',
            'domainCreate' => 'domainname period registrant contacts authInfoPw',
            'domainCreateDNSSEC' => 'domainname period registrant contacts dnssec_records authInfoPw',
            'domainRenew' => null,
        ],
        'LT' => [
            'contactCheck' => null,
            'contactCreate' => 'type firstname lastname companyname address1 city state postcode country fullphonenumber email orgcode role',
            'contactUpdate' => 'type id firstname lastname companyname address1 city state postcode country fullphonenumber email orgcode',
            'domainUpdateNS' => 'domainname nss',
            'domainUpdateStatus' => null,
            'domainUpdateAuthinfo' => null,
            'domainTransfer' => 'domainname',
            'domainCreate' => 'domainname period',
            'domainCreateDNSSEC' => 'domainname period dnssec_records',
            'domainRestore' => null,
        ],
        'LV' => [
            'contactCreate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email authInfoPw vatNr regNr',
            'domainUpdateNS' => 'domainname nss',
            'domainCreate' => 'domainname period registrant contacts authInfoPw',
            'domainCreateDNSSEC' => 'domainname period registrant contacts authInfoPw dnssec_records',
            'domainRenew' => null,
            'domainRestore' => null,
        ],
        'NO' => [
            'domainUpdateStatus' => null,
            'domainCreate' => 'domainname period registrant reg_pers reg_date tech authInfoPw',
            'domainCreateDNSSEC' => 'domainname period registrant reg_pers reg_date tech dnssec_records authInfoPw',
        ],
        'PL' => [
            'hostCreate' => 'hostname ipaddress',
            'domainCreate' => 'domainname period nss registrant contacts authInfoPw',
            'domainCreateDNSSEC' => 'domainname period nss registrant contacts dnssec_records authInfoPw',
        ],
        'PT' => [
            'contactCreate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email authInfoPw validated validatedDate',
            'contactUpdate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email validated validatedDate',
            'domainCreate' => 'domainname period registrant tech authInfoPw',
            'domainCreateDNSSEC' => 'domainname period registrant tech dnssec_records authInfoPw',
        ],
        'SE' => [
            'contactCreate' => 'id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email orgno vatno',
            'domainCreate' => 'domainname period registrant authInfoPw',
            'domainCreateDNSSEC' => 'domainname period registrant dnssec_records authInfoPw',
        ],
        'SI' => [
            'contactCreate' => 'type id firstname lastname companyname address1 address2 city state postcode country fullphonenumber email authInfoPw nin_type',
        ],
        'UA' => [
            'hostCreate' => 'hostname ipaddress',
            'domainCreate' => 'domainname period registrant contacts authInfoPw',
            'domainCreateDNSSEC' => 'domainname period registrant contacts dnssec_records authInfoPw',
        ],
        'VRSN' => [
            'contactCheck' => null,
            'contactInfo' => null,
            'contactCreate' => null,
            'contactUpdate' => null,
            'contactDelete' => null,
            'domainUpdateContact' => null,
        ],
    ];

    /** The panel uses the same pinned parameter names and requirements as API submission. */
    public static function contactFields(): array
    {
        $fields = explode(' ', self::REQUIRED['contactCreate'] . ' address3 fax');
        foreach (self::OVERRIDES as $methods) {
            foreach (['contactCreate', 'contactUpdate', 'contactDelete', 'contactUpdateAuthinfo'] as $method) {
                foreach (explode(' ', $methods[$method] ?? '') as $field) {
                    if ($field !== '' && !in_array($field, ['contact', 'contactid', 'authInfo'], true)) { $fields[] = $field; }
                }
            }
        }
        return array_values(array_unique($fields));
    }

    public static function contactRequired(string $profile, string $command): array
    {
        $method = CommandCatalog::method($command);
        $overrides = self::OVERRIDES[$profile] ?? [];
        $fields = array_key_exists($method, $overrides) ? $overrides[$method] : (self::REQUIRED[$method] ?? '');
        return $fields === null || $fields === '' ? [] : explode(' ', $fields);
    }

    public static function validate(string $profile, string $command, array $params): void
    {
        $method = CommandCatalog::method($command);
        if ($command === 'domain.restore_report') {
            if (!in_array($profile, ['generic', 'VRSN'], true)) { self::unsupported(); }
            return; // Rgp validates the native report adapter.
        }
        $overrides = self::OVERRIDES[$profile] ?? [];
        $fields = array_key_exists($method, $overrides) ? $overrides[$method] : (self::REQUIRED[$method] ?? '');
        if ($fields === null) { self::unsupported(); }
        self::require($params, $fields);
        foreach ($params as $key => $value) {
            if (in_array($key, ['nss', 'contacts', 'domains'], true) || $key === 'contact' && $command === 'contact.check'
                || $key === 'ns' && $profile === 'LT' && in_array($command, ['host.create', 'host.update'], true)) {
                if (!is_array($value)) { self::invalid($key, 'must be an array'); }
                foreach ($value as $item) {
                    if ($key === 'nss' && is_array($item)) {
                        self::require($item, 'hostName');
                        foreach ($item as $field => $part) { self::scalar($field, $part); }
                    } else { self::scalar($key, $item); }
                }
            } else { self::scalar((string) $key, $value); }
        }
        if ($command === 'domain.transfer') {
            self::require($params, 'op');
            $ops = $profile === 'LT' ? ['request'] : ['request', 'query', 'cancel', 'approve', 'reject'];
            if (!in_array($params['op'], $ops, true)) { self::invalid('op', 'is not supported by this profile'); }
            if ($params['op'] === 'request') {
                self::require($params, $profile === 'LT' ? '' : ($profile === 'FI' || $profile === 'IT' ? 'authInfoPw' : 'years authInfoPw'));
                self::require($params, match ($profile) { 'FR' => 'admin tech', 'GR' => 'registrant', 'NO' => 'trans_pers trans_date', default => '' });
            }
            if ($profile === 'IT') { self::require($params, 'authInfoPw'); }
        }
        if ($command === 'domain.update_contact') {
            self::require($params, 'new_contactid');
            if (($params['contacttype'] ?? '') !== 'registrant') { self::require($params, 'old_contactid'); }
        }
        if ($command === 'domain.update_status' && !in_array($params['command'], ['add', 'rem'], true)) { self::invalid('command', 'must be add or rem'); }
        if ($command === 'domain.update_dnssec' || $command === 'domain.create_dnssec') {
            if ($command === 'domain.update_dnssec') {
                if (!in_array($params['command'], ['add', 'rem', 'addrem'], true)) { self::invalid('command', 'must be add, rem or addrem'); }
                $records = $params['command'] === 'addrem' ? 2 : 1;
            } else {
                if (!in_array($params['dnssec_records'], [1, 2, '1', '2'], true)) { self::invalid('dnssec_records', 'must be 1 or 2'); }
                $records = (int) $params['dnssec_records'];
            }
            for ($i = 1; $i <= $records; ++$i) { self::require($params, "keyTag_{$i} alg_{$i} digestType_{$i} digest_{$i}"); }
        }
        if ($command === 'host.update' && !in_array($profile, ['FRED', 'LT'], true)
            && empty($params['currentipaddress']) && empty($params['newipaddress'])) { self::invalid('newipaddress', 'or currentipaddress is required'); }
        foreach (['authInfoPw', 'authInfo', 'op', 'command'] as $key) {
            if (array_key_exists($key, $params) && $params[$key] === '') { self::invalid($key, 'must not be empty'); }
        }
    }

    private static function require(array $params, string $fields): void
    {
        foreach (explode(' ', trim($fields)) as $key) {
            if ($key !== '' && !array_key_exists($key, $params)) {
                throw new ApiException(422, 'missing_parameter', "Missing required profile parameter: {$key}", ['parameter' => $key]);
            }
        }
    }

    private static function scalar(string $key, mixed $value): void
    {
        if (!is_string($value) && !is_int($value)) { self::invalid($key, 'must be a string or integer'); }
    }

    private static function invalid(string $key, string $reason): never
    {
        throw new ApiException(422, 'invalid_parameter', "Parameter {$key} {$reason}.", ['parameter' => $key]);
    }

    private static function unsupported(): never
    {
        throw new ApiException(422, 'unsupported_profile_command', 'This command is not implemented by the selected registry profile.');
    }
}
