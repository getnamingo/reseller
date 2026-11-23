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

namespace Namingo\ResellerApi;

/** Adapters for the pinned Tembo serializers, not a second EPP implementation. */
final class DomainParams
{
    public static function hostAttributes(string $profile): bool
    {
        return in_array($profile, ['EE', 'EU', 'FI', 'GE', 'HR', 'IT', 'LT', 'LV'], true);
    }

    public static function native(array $input): array
    {
        $raw = $input['native'] ?? '';
        if (!is_string($raw) || strlen($raw) > 32000) { throw new ApiException(422, 'invalid_params', 'Additional parameters must be a JSON object.'); }
        if (trim($raw) === '') { return []; }
        try { $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiException(422, 'invalid_params', 'Additional parameters must be valid JSON.'); }
        if (!$object instanceof \stdClass) { throw new ApiException(422, 'invalid_params', 'Additional parameters must be a JSON object.'); }
        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }

    public static function nameservers(array $input): array
    {
        $out = [];
        for ($i = 1; $i <= 5; ++$i) {
            $name = self::text($input, 'ns' . $i);
            $v4 = self::text($input, 'ipv4_' . $i); $v6 = self::text($input, 'ipv6_' . $i);
            if ($name === '') {
                if ($v4 !== '' || $v6 !== '') { throw new ApiException(422, 'invalid_host', 'Glue addresses require a nameserver name.'); }
                continue;
            }
            $name = Ownership::name($name, 'domain');
            foreach ([$v4 => FILTER_FLAG_IPV4, $v6 => FILTER_FLAG_IPV6] as $ip => $flag) {
                if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP, $flag)) { throw new ApiException(422, 'invalid_host', 'Enter valid IPv4/IPv6 glue addresses.'); }
            }
            if (isset($out[$name])) { throw new ApiException(422, 'duplicate_host', 'Nameservers must be distinct.'); }
            $out[$name] = array_filter(['hostName' => $name, 'ipv4' => $v4, 'ipv6' => $v6], fn ($v) => $v !== '');
        }
        return array_values($out);
    }

    public static function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value) || strlen($value) > ($key === 'domains' ? 15000 : 8000)) { throw new ApiException(422, 'invalid_parameter', 'Invalid field: ' . $key); }
        return trim($value);
    }

    public static function years(array $input): int
    {
        $years = filter_var($input['years'] ?? '1', FILTER_VALIDATE_INT);
        if ($years === false || $years < 1 || $years > 10) { throw new ApiException(422, 'invalid_period', 'Choose 1–10 years.'); }
        return $years;
    }

    public static function dnssec(array $input): array
    {
        $records = []; $index = 0;
        for ($i = 1; $i <= 2; ++$i) {
            $row = [];
            foreach (['keyTag', 'alg', 'digestType', 'digest'] as $key) { $row[$key] = self::text($input, $key . '_' . $i); }
            if (implode('', $row) === '') { continue; }
            foreach ($row as $key => $value) {
                if ($value === '') { throw new ApiException(422, 'invalid_dnssec', 'Complete every field in each supplied DS record.'); }
                if ($key !== 'digest' && (!ctype_digit($value) || (int) $value > ($key === 'keyTag' ? 65535 : 255))) {
                    throw new ApiException(422, 'invalid_dnssec', 'Invalid DS numeric value.');
                }
                if ($key === 'digest' && (!ctype_xdigit($value) || strlen($value) % 2 !== 0)) { throw new ApiException(422, 'invalid_dnssec', 'DS digest must contain hexadecimal byte pairs.'); }
                $records[$key . '_' . ($index + 1)] = $value;
            }
            ++$index;
        }
        return $index ? ['dnssec_records' => $index] + $records : [];
    }

    public const TRANSFER_LOCK = 'clientTransferProhibited';

    public static function authorizeStatusChange(int $role, mixed $status): void
    {
        if ($role !== 0 && $status !== self::TRANSFER_LOCK) {
            throw new ApiException(403, 'status_not_permitted', 'Resellers can only change the domain transfer lock.');
        }
    }

    /** A plan is frozen and encrypted before its first job is submitted. */
    public static function plan(string $profile, string $action, string $name, array $input): array
    {
        $native = self::native($input);
        $steps = []; $params = $native; $command = '';
        if ($action === 'create' || $action === 'nameservers') {
            $hosts = self::nameservers($input);
            if ($profile === 'FRED') {
                // FRED host.create creates an NSSET, rather than an individual host.
                if ($action === 'nameservers') { throw new ApiException(422, 'unsupported_profile_command', 'The pinned FRED domainUpdateNS serializer does not change NSSET associations. Manage the NSSET through the native API.'); }
                if (empty($params['nsid'])) {
                    if (count($hosts) !== 2) { throw new ApiException(422, 'invalid_nsset', 'FRED NSSET creation requires exactly two nameservers, or an existing owned nsid in additional parameters.'); }
                    foreach ($hosts as $host) {
                        if (isset($host['ipv4'], $host['ipv6'])) { throw new ApiException(422, 'unsupported_host_addresses', 'This NSSET serializer accepts one glue address per host.'); }
                    }
                    $params['nsid'] = 'NS-' . bin2hex(random_bytes(8));
                    $hostParams = ['nsid' => $params['nsid'], 'hostname' => $hosts[0]['hostName'], 'hostname2' => $hosts[1]['hostName'],
                        'ipaddress' => $hosts[0]['ipv4'] ?? $hosts[0]['ipv6'] ?? '', 'ipaddress2' => $hosts[1]['ipv4'] ?? $hosts[1]['ipv6'] ?? '',
                        'nstech' => $native['nstech'] ?? self::text($input, 'tech')];
                    $steps[] = self::step('host.create', $hostParams);
                }
            } else {
                $params['nss'] = self::hostAttributes($profile) ? $hosts : array_column($hosts, 'hostName');
                foreach ($hosts as $i => $host) {
                    $params['ns' . ($i + 1)] = $host['hostName'];
                    if (!self::hostAttributes($profile)) {
                        $steps[] = self::step('host.check', ['hostname' => $host['hostName']]);
                        if (isset($host['ipv4'], $host['ipv6'])) { throw new ApiException(422, 'unsupported_host_addresses', 'This host-object serializer accepts one glue address per create. Supply IPv4 or IPv6.'); }
                        $hostParams = ['hostname' => $host['hostName'], 'ipaddress' => $host['ipv4'] ?? $host['ipv6'] ?? ''];
                        $steps[] = self::step('host.create', $hostParams) + ['check' => count($steps) - 1];
                    }
                }
            }
            if ($action === 'nameservers') {
                if (($input['replace_nameservers'] ?? '') !== 'yes') { throw new ApiException(422, 'confirmation_required', 'Confirm replacement of the complete nameserver set.'); }
                $command = 'domain.update_ns';
            }
            else {
                $params['period'] = self::years($input);
                $params['authInfoPw'] = substr(Util::token(), 0, 20) . 'aA1!';
                $params['registrant'] = self::text($input, 'registrant');
                $params['contacts'] = [];
                foreach (['admin', 'tech', 'billing'] as $role) {
                    $value = self::text($input, $role);
                    $params[$role] = $value;
                    if ($value !== '') { $params['contacts'][$role] = $value; }
                }
                $ds = self::dnssec($input);
                $command = $ds ? 'domain.create_dnssec' : 'domain.create';
                $params = array_replace($params, $ds);
            }
        } elseif ($action === 'contact') {
            $command = 'domain.update_contact';
            $role = self::text($input, 'contacttype');
            if (!in_array($role, ['registrant', 'admin', 'tech', 'billing'], true)) { throw new ApiException(422, 'invalid_contact', 'Choose a contact role.'); }
            $params['contacttype'] = $role;
            $params['new_contactid'] = self::text($input, 'new_contactid');
            $params['old_contactid'] = self::text($input, 'old_contactid');
            if ($params['new_contactid'] === '' && $params['old_contactid'] === '') { throw new ApiException(422, 'invalid_contact', 'Enter a contact ID to add, replace or remove.'); }
        } elseif ($action === 'dnssec') {
            $command = 'domain.update_dnssec';
            $params = array_replace($params, self::dnssec($input));
            $params['command'] = self::text($input, 'dnssec_action');
            $expected = $params['command'] === 'addrem' ? 2 : 1;
            if (($params['dnssec_records'] ?? 0) !== $expected) { throw new ApiException(422, 'invalid_dnssec', 'Supply one complete DS record to add/remove, or two records to replace.'); }
        } elseif ($action === 'authinfo') {
            $command = 'domain.update_authinfo'; $params['authInfo'] = self::text($input, 'authInfo');
        } elseif ($action === 'renew') {
            $command = 'domain.renew'; $params['regperiod'] = self::years($input);
        } elseif ($action === 'delete') {
            if (($input['confirm'] ?? '') !== 'yes') { throw new ApiException(422, 'confirmation_required', 'Confirm deletion.'); }
            $command = 'domain.delete';
        } elseif ($action === 'sync') { $command = 'domain.info'; }
        elseif ($action === 'status') {
            $command = 'domain.update_status'; $params['command'] = self::text($input, 'status_action'); $params['status'] = self::text($input, 'status');
        } elseif ($action === 'restore') { $command = 'domain.restore'; }
        elseif ($action === 'restore_report') { $command = 'domain.restore_report'; }
        elseif ($action === 'transfer') { $command = 'domain.transfer'; }
        else { throw new ApiException(422, 'invalid_action', 'Choose a domain action.'); }
        $params['domainname'] = $name; // Native fields cannot retarget an existing-domain operation.
        $steps[] = self::step($command, $params);
        if (!in_array($action, ['delete', 'sync'], true)) { $steps[] = self::step('domain.info', ['domainname' => $name]); }
        foreach ($steps as $step) { CommandCatalog::validateParams($step['command'], $step['params'], $profile); }
        return $steps;
    }

    private static function step(string $command, array $params): array
    {
        return ['command' => $command, 'params' => $params, 'job_uuid' => null, 'status' => 'prepared'];
    }
}
