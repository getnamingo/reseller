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

use PDO;

/** A local address book; registry operations still use the normal durable EPP ledger. */
final class Contacts
{
    public static function defaults(): array
    {
        return ['fields' => ['id' => 'C' . bin2hex(random_bytes(7)), 'type' => 'int', 'authInfoPw' => substr(Util::token(), 0, 12) . 'aA1!'], 'extra' => []];
    }

    private static function registry(PDO $pdo, string $id, array $actor): array
    {
        $sql = 'SELECT r.id, r.name, r.profile FROM registries r WHERE r.id = ?'; $args = [$id];
        if ((int) $actor['role'] !== 0) {
            $sql .= ' AND r.enabled = 1 AND EXISTS (SELECT 1 FROM reseller_registries a WHERE a.registry_id = r.id AND a.reseller_id = ? AND a.enabled = 1)';
            $args[] = $actor['id'];
        }
        $row = PanelData::rows($pdo, $sql, $args)[0] ?? null;
        if ($row === null) { throw new ApiException(404, 'registry_not_found', 'Registry is unavailable for this account.'); }
        return $row;
    }

    private static function lock(PDO $pdo, string $id, callable $callback): mixed
    {
        $name = 'contact:' . $id;
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $stmt->execute([$name]);
        if ((string) $stmt->fetchColumn() !== '1') { throw new ApiException(409, 'contact_busy', 'This contact is being changed. Reload and retry.'); }
        try { return $callback(); }
        finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
    }

    private static function row(PDO $pdo, string $id, array $actor): array
    {
        $sql = 'SELECT c.*, u.username FROM panel_contacts c JOIN resellers u ON u.id = c.reseller_id WHERE c.id = ?';
        $args = [$id];
        if ((int) $actor['role'] !== 0) { $sql .= ' AND c.reseller_id = ?'; $args[] = $actor['id']; }
        $stmt = $pdo->prepare($sql); $stmt->execute($args); $row = $stmt->fetch();
        if (!$row) { throw new ApiException(404, 'contact_not_found', 'Contact was not found.'); }
        $row['registries'] = json_decode($row['registries'], true, 64, JSON_THROW_ON_ERROR);
        $row['data'] = $row['data_nonce'] === null ? ['fields' => [], 'extra' => []]
            : json_decode(Crypto::decrypt($row['data_nonce'], $row['data_cipher'], 'panel-contact:' . $id), true, 64, JSON_THROW_ON_ERROR);
        $row['data']['fields']['id'] ??= array_values($row['registries'])[0]['contact_id'] ?? '';
        foreach ($row['registries'] as $registry => &$link) { $link['message'] = $row['data']['messages'][$registry] ?? ''; }
        unset($link);
        unset($row['data_nonce'], $row['data_cipher']);
        $row['version'] = hash('sha256', Util::json([$row['data'], $row['registries']]));
        return $row;
    }

    private static function save(PDO $pdo, array $row): void
    {
        // Registry error text can echo contact data; keep it encrypted, not in routing metadata.
        foreach ($row['registries'] as $registry => &$link) {
            $row['data']['messages'][$registry] = $link['message'] ?? '';
            unset($link['message']);
        }
        unset($link);
        [$nonce, $cipher] = Crypto::encrypt(Util::json($row['data']), 'panel-contact:' . $row['id']);
        $pdo->prepare('UPDATE panel_contacts SET data_nonce = ?, data_cipher = ?, registries = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')
            ->execute([$nonce, $cipher, Util::json($row['registries']), $row['id']]);
    }

    public static function get(PDO $pdo, string $id, array $actor): ?array
    {
        return self::lock($pdo, $id, function () use ($pdo, $id, $actor) {
            $row = self::row($pdo, $id, $actor);
            foreach ($row['registries'] as $registry => &$link) {
                if (!empty($link['applied']) || empty($link['key'])) { continue; }
                // Recover a crash after createJob committed but before its UUID was saved locally.
                if (empty($link['job_uuid'])) {
                    $stmt = $pdo->prepare('SELECT id FROM epp_jobs WHERE reseller_id = ? AND idempotency_key = ?');
                    $stmt->execute([$row['reseller_id'], $link['key']]); $link['job_uuid'] = $stmt->fetchColumn() ?: null;
                }
                if (empty($link['job_uuid'])) { continue; }
                $job = Ledger::fetchJob($pdo, $link['job_uuid'], $row['reseller_id']);
                if ($job['registry_uuid'] !== $registry || $job['command'] !== $link['command']) { throw new ApiException(409, 'contact_job_mismatch', 'Contact job does not match its registry.'); }
                $link['status'] = $job['status']; $link['epp_code'] = $job['epp_code'];
                $result = $job['result'] ?? [];
                $link['message'] = (string) ($result['msg'] ?? $result['message'] ?? $result['error'] ?? '');
                if (!in_array($job['status'], ['succeeded', 'rejected'], true)) { continue; }
                $link['applied'] = true;
                if ($job['status'] === 'rejected') { continue; }
                $request = $row['data']['requests'][$registry] ?? [];
                if ($link['command'] === 'contact.create') {
                    $contactId = $result['id'] ?? ($job['profile'] === 'LT' ? null : ($request['id'] ?? null));
                    if (!is_string($contactId) || $contactId === '') {
                        $link['status'] = 'unknown'; $link['applied'] = false;
                        $link['message'] = 'The registry contact ID is unavailable. Verify and import its ownership before retrying.';
                        continue;
                    }
                    $link['contact_id'] = $contactId;
                } elseif ($link['command'] === 'contact.info') {
                    if (isset($result['id'])) {
                        try { $matches = Ownership::name($result['id'], 'contact') === Ownership::name($link['contact_id'], 'contact'); }
                        catch (ApiException) { $matches = false; }
                        if (!$matches) {
                            $link['status'] = 'rejected'; $link['message'] = 'Registry contact-info ID does not match the requested contact.';
                            continue;
                        }
                    }
                    if (is_string($result['id'] ?? null) && $result['id'] !== '') { $link['contact_id'] = $result['id']; }
                    self::fromInfo($row['data'], $result);
                } elseif ($link['command'] === 'contact.update_authinfo') {
                    $row['data']['fields']['authInfoPw'] = $request['authInfo'];
                }
                if ($link['command'] === 'contact.delete') { $link['removed'] = true; }
            }
            unset($link);
            foreach ($row['registries'] as $registry => $link) {
                if (!empty($link['removed'])) { unset($row['registries'][$registry], $row['data']['requests'][$registry], $row['data']['messages'][$registry]); }
            }
            if ($row['registries'] === []) {
                $pdo->prepare('DELETE FROM panel_contacts WHERE id = ?')->execute([$id]);
                return null;
            }
            if (!hash_equals($row['version'], hash('sha256', Util::json([$row['data'], $row['registries']])))) { self::save($pdo, $row); }
            return self::row($pdo, $id, $actor);
        });
    }

    /** Existing API/CLI-owned contacts also appear, without guessing identities across registries. */
    public static function listing(PDO $pdo, array $actor, int $page, bool $all = false): array
    {
        $scope = $all && (int) $actor['role'] === 0 ? null : $actor['id'];
        $where = $scope === null ? '' : ' AND reseller_id = ?';
        foreach (PanelData::rows($pdo, "SELECT id FROM panel_contacts WHERE (JSON_SEARCH(registries, 'one', 'prepared') IS NOT NULL OR JSON_SEARCH(registries, 'one', 'queued') IS NOT NULL OR JSON_SEARCH(registries, 'one', 'processing') IS NOT NULL OR JSON_SEARCH(registries, 'one', 'unknown') IS NOT NULL OR JSON_SEARCH(registries, 'one', 'pending') IS NOT NULL)" . $where, $scope === null ? [] : [$scope]) as $pending) {
            self::get($pdo, $pending['id'], $actor);
        }
        $sql = "SELECT o.registry_id, o.object_name, o.reseller_id, r.name FROM object_ownership o JOIN registries r ON r.id = o.registry_id
            WHERE o.object_type = 'contact' AND o.active = 1" . ($scope === null ? '' : ' AND o.reseller_id = ?');
        $sql .= <<<'SQL'
            AND NOT EXISTS (SELECT 1 FROM panel_contacts c WHERE c.reseller_id = o.reseller_id
                AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(c.registries, CONCAT('$."', o.registry_id, '".contact_id')))) = LOWER(o.object_name))
SQL;
        foreach (PanelData::rows($pdo, $sql, $scope === null ? [] : [$scope]) as $owned) {
            // Serialize imports and reconcile creates after observing this active ownership record.
            self::lock($pdo, substr(hash('sha256', $owned['reseller_id'] . $owned['registry_id']), 0, 36), function () use ($pdo, $owned, $actor) {
                $registryPath = '$."' . $owned['registry_id'] . '"';
                foreach (PanelData::rows($pdo, "SELECT id FROM panel_contacts WHERE reseller_id = ?
                    AND JSON_UNQUOTE(JSON_EXTRACT(registries, ?)) = 'contact.create'
                    AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(registries, ?)), 'false') <> 'true'",
                    [$owned['reseller_id'], $registryPath . '.command', $registryPath . '.applied']) as $pending) {
                    self::get($pdo, $pending['id'], $actor);
                }
                $path = $registryPath . '.contact_id';
                if (PanelData::rows($pdo, 'SELECT id FROM panel_contacts WHERE reseller_id = ? AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(registries, ?))) = LOWER(?)', [$owned['reseller_id'], $path, $owned['object_name']])) { return; }
                $link = [$owned['registry_id'] => ['registry_name' => $owned['name'], 'contact_id' => $owned['object_name'], 'status' => 'succeeded', 'applied' => true]];
                $pdo->prepare('INSERT INTO panel_contacts (id, reseller_id, registries) VALUES (?, ?, ?)')->execute([Util::uuid(), $owned['reseller_id'], Util::json($link)]);
            });
        }
        $offset = ($page - 1) * 50;
        $rows = PanelData::rows($pdo, 'SELECT id FROM panel_contacts WHERE 1 = 1' . $where . " ORDER BY created_at DESC, id DESC LIMIT 51 OFFSET {$offset}", $scope === null ? [] : [$scope]);
        $next = count($rows) > 50; if ($next) { array_pop($rows); }
        return ['rows' => array_map(fn ($row) => self::row($pdo, $row['id'], $actor), $rows), 'next' => $next];
    }

    public static function parse(array $input): array
    {
        foreach (['fields', 'extra_key', 'extra_value'] as $name) {
            if (isset($input[$name]) && !is_array($input[$name])) { throw new ApiException(422, 'invalid_contact', 'Invalid contact form fields.'); }
        }
        $fields = [];
        foreach (ProfileParams::contactFields() as $field) {
            $value = $input['fields'][$field] ?? '';
            if (!is_string($value)) { throw new ApiException(422, 'invalid_contact', 'Contact fields must be text.'); }
            $fields[$field] = $value;
        }
        $extra = [];
        for ($i = 0; $i < 5; ++$i) {
            $key = $input['extra_key'][$i] ?? ''; $value = $input['extra_value'][$i] ?? '';
            if (!is_string($key) || !is_string($value)) { throw new ApiException(422, 'invalid_contact', 'Additional parameters must be text.'); }
            if ($key === '' && $value === '') { continue; }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $key) || in_array($key, ['id', 'contact', 'contactid'], true) || isset($extra[$key])) {
                throw new ApiException(422, 'invalid_contact_parameter', 'Use unique parameter names; registry contact IDs cannot be overridden.');
            }
            if (($fields[$key] ?? '') !== '' && $fields[$key] !== $value) { throw new ApiException(422, 'duplicate_contact_parameter', 'Parameter ' . $key . ' has two different values. Use one field.'); }
            $extra[$key] = $value;
        }
        return ['fields' => $fields, 'extra' => $extra];
    }

    public static function submit(PDO $pdo, array $actor, string $id, array $input, string $ip): void
    {
        if (!Util::isUuid($id) || !is_string($input['key'] ?? null) || !Util::isUuid($input['key'])) { throw new ApiException(422, 'invalid_contact', 'Reload the contact form and retry.'); }
        self::lock($pdo, $id, function () use ($pdo, $actor, $id, $input, $ip) {
            $exists = PanelData::rows($pdo, 'SELECT id FROM panel_contacts WHERE id = ?', [$id]);
            $action = $input['action'] ?? 'save';
            if (!in_array($action, ['save', 'delete', 'sync', 'resume', 'authinfo'], true)) { throw new ApiException(422, 'invalid_contact_action', 'Invalid contact action.'); }
            if (!$exists) {
                if ($action !== 'save') { throw new ApiException(404, 'contact_not_found', 'Contact was not found.'); }
                self::parse($input);
                if (!is_array($input['registries'] ?? null) || $input['registries'] === [] || count($input['registries']) > 50) { throw new ApiException(422, 'invalid_registry', 'Choose 1–50 registries.'); }
                foreach ($input['registries'] as $registry) {
                    if (!is_string($registry) || !Util::isUuid($registry)) {
                        throw new ApiException(422, 'invalid_registry', 'Choose an existing registry.');
                    }
                    self::registry($pdo, $registry, $actor);
                }
                $owner = (int) $actor['role'] === 0 ? ($input['reseller_id'] ?? $actor['id']) : $actor['id'];
                if (!is_string($owner) || !Util::isUuid($owner) || !PanelData::rows($pdo, 'SELECT id FROM resellers WHERE id = ?', [$owner])) { throw new ApiException(422, 'invalid_reseller', 'Choose a reseller.'); }
                $pdo->prepare('INSERT INTO panel_contacts (id, reseller_id, registries) VALUES (?, ?, ?)')->execute([$id, $owner, '{}']);
            }
            $row = self::row($pdo, $id, $actor);
            if ($action === 'authinfo' && !is_string($input['authInfo'] ?? null)) { throw new ApiException(422, 'invalid_contact', 'Enter an authorization code.'); }
            if ($exists && isset($input['version']) && (!is_string($input['version']) || !hash_equals($row['version'], $input['version']))) {
                throw new ApiException(409, 'contact_changed', 'This contact changed. Reload before saving.');
            }
            if ($action === 'resume') { self::dispatch($pdo, $row, $actor, $ip); return; }
            foreach ($row['registries'] as $link) {
                if (empty($link['applied']) && !empty($link['key']) && !in_array($link['status'], ['rejected'], true)) {
                    // A repeated POST resumes its original immutable intent instead of creating another write.
                    if (($row['data']['form_key'] ?? '') === $input['key']) { self::dispatch($pdo, $row, $actor, $ip); return; }
                    throw new ApiException(409, 'contact_busy', 'An operation is outstanding. Refresh its result or resume the original submission first.');
                }
            }
            if ($action === 'save') {
                $data = self::parse($input);
                $row['data'] = $data + ['requests' => $row['data']['requests'] ?? []];
                $targets = $input['registries'] ?? [];
                if (!is_array($targets) || $targets === [] || count($targets) > 50) { throw new ApiException(422, 'invalid_registry', 'Choose 1–50 registries.'); }
            } elseif ($action === 'delete') { $targets = array_keys($row['registries']); }
            else { $targets = [$input['registry_id'] ?? '']; }
            foreach ($targets as $registry) {
                if (!is_string($registry) || !Util::isUuid($registry)) { throw new ApiException(422, 'invalid_registry', 'Choose a registry.'); }
            }
            foreach (array_unique($targets) as $registry) {
                $reg = self::registry($pdo, $registry, $actor);
                $link = $row['registries'][$registry] ?? ['registry_name' => $reg['name'], 'contact_id' => null];
                if ($action === 'delete' && empty($link['contact_id']) && ($link['status'] ?? '') === 'rejected' && !empty($link['applied'])) {
                    unset($row['registries'][$registry], $row['data']['requests'][$registry]);
                    continue;
                }
                $command = match ($action) {
                    'sync' => 'contact.info', 'delete' => 'contact.delete', 'authinfo' => 'contact.update_authinfo',
                    default => empty($link['contact_id']) ? 'contact.create' : 'contact.update',
                };
                if ($action !== 'save' && empty($link['contact_id'])) { throw new ApiException(422, 'contact_not_created', 'This registry has no confirmed contact ID.'); }
                $params = array_filter(($row['data']['fields'] ?? []) + ($row['data']['extra'] ?? []), fn ($value) => $value !== '');
                // Extra values deliberately override blank/standard fields, as native API parameters.
                $params = array_replace($params, $row['data']['extra'] ?? []);
                foreach (ProfileParams::contactRequired($reg['profile'], $command) as $field) { $params[$field] ??= ''; }
                if ($command === 'contact.create' && $reg['profile'] === 'LT') { unset($params['id']); }
                elseif (in_array($command, ['contact.create', 'contact.update'], true)) { $params['id'] = $link['contact_id'] ?? ($row['data']['fields']['id'] ?? ''); }
                if (in_array($command, ['contact.info', 'contact.delete'], true)) { $params['contact'] = $link['contact_id']; }
                if ($command === 'contact.update_authinfo') { $params['contactid'] = $link['contact_id']; $params['authInfo'] = (string) ($input['authInfo'] ?? ''); }
                $row['data']['requests'][$registry] = $params;
                $row['registries'][$registry] = array_replace($link, ['registry_name' => $reg['name'], 'command' => $command,
                    'key' => 'contact-' . hash('sha256', $id . $input['key'] . $registry), 'job_uuid' => null, 'status' => 'prepared', 'applied' => false, 'message' => '', 'epp_code' => null]);
            }
            $row['data']['form_key'] = $input['key'];
            if ($row['registries'] === []) { $pdo->prepare('DELETE FROM panel_contacts WHERE id = ?')->execute([$id]); return; }
            self::save($pdo, $row); // Durable intent before the independently committed ledger job.
            self::dispatch($pdo, $row, $actor, $ip);
        });
    }

    private static function dispatch(PDO $pdo, array $row, array $actor, string $ip): void
    {
        foreach ($row['registries'] as $registry => &$link) {
            if (($link['status'] ?? '') !== 'prepared' || !empty($link['job_uuid'])) { continue; }
            try {
                // FPM submissions do not pass through Swoole's in-memory HTTP limiter.
                $bucket = Crypto::hmac('panel-contacts:' . $actor['id']);
                $pdo->prepare('INSERT INTO auth_login_limits (ip_hash) VALUES (?) ON DUPLICATE KEY UPDATE
                    attempts = IF(window_started < UTC_TIMESTAMP(6) - INTERVAL 1 MINUTE, 1, LEAST(attempts + 1, 1000000)),
                    window_started = IF(window_started < UTC_TIMESTAMP(6) - INTERVAL 1 MINUTE, UTC_TIMESTAMP(6), window_started)')->execute([$bucket]);
                $attempts = $pdo->prepare('SELECT attempts FROM auth_login_limits WHERE ip_hash = ?'); $attempts->execute([$bucket]);
                if ((int) $attempts->fetchColumn() > Config::int('API_RATE_LIMIT_PER_MINUTE', 120)) { throw new ApiException(429, 'rate_limited', 'Contact submission limit reached. Wait a minute and resume this submission.'); }
                $job = Ledger::createJob($pdo, $row['reseller_id'], $registry, $link['command'], $link['key'], $row['data']['requests'][$registry]);
                $link['job_uuid'] = $job['job_id']; $link['status'] = $job['status'];
            } catch (ApiException $error) {
                // Validation/access errors are displayed per registry; transient failures retain their intent.
                if (in_array($error->httpStatus, [400, 402, 403, 404, 409, 422, 428], true)) {
                    $link['status'] = 'rejected'; $link['applied'] = true;
                }
                $link['message'] = $error->errorCode . ': ' . $error->getMessage();
            }
        }
        unset($link);
        self::save($pdo, $row);
        Audit::record($pdo, $actor['id'], 'panel.contact.submit', 'contact', $row['id'], $ip, ['owner' => $row['reseller_id']]);
    }

    private static function fromInfo(array &$data, array $info): void
    {
        $aliases = ['org' => 'companyname', 'street1' => 'address1', 'street2' => 'address2', 'street3' => 'address3',
            'postal' => 'postcode', 'voice' => 'fullphonenumber', 'authInfo' => 'authInfoPw'];
        foreach (ProfileParams::contactFields() as $field) {
            $source = array_search($field, $aliases, true) ?: $field;
            if ($field !== 'id' && isset($info[$source]) && is_scalar($info[$source])) { $data['fields'][$field] = (string) $info[$source]; }
        }
        if (isset($info['name']) && is_string($info['name'])) {
            $name = preg_split('/\s+/u', trim($info['name']), 2);
            $data['fields']['firstname'] = $name[0]; $data['fields']['lastname'] = $name[1] ?? '';
        }
        foreach ($data['extra'] ?? [] as $key => $_) { if (isset($info[$key]) && is_scalar($info[$key])) { $data['extra'][$key] = (string) $info[$key]; } }
    }
}
