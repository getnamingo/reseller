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

/** Transfer index contains no authorization codes; registry work stays in Ledger. */
final class Transfers
{
    public static function entries(array $input): array
    {
        $lines = preg_split('/\r\n|\r|\n/', DomainParams::text($input, 'domains'));
        $entries = [];
        foreach ($lines as $line) {
            if (trim($line) === '') { continue; }
            [$name, $auth] = array_pad(explode(',', $line, 2), 2, '');
            $name = Ownership::name(trim($name), 'domain');
            if (isset($entries[$name])) { throw new ApiException(422, 'duplicate_domain', 'Enter each domain only once.'); }
            $entries[$name] = trim($auth);
        }
        if (count($entries) < 1 || count($entries) > 50) { throw new ApiException(422, 'invalid_domains', 'Enter between 1 and 50 domains, one per line.'); }
        return $entries;
    }

    public static function plan(string $profile, string $op, string $name, array $input): array
    {
        $params = array_replace(DomainParams::native($input), ['domainname' => $name, 'op' => $op]);
        if ($op === 'request') {
            unset($params['authInfoPw']);
            $auth = self::entries($input)[$name];
            if ($auth !== '') { $params['authInfoPw'] = $auth; }
            $params['years'] = DomainParams::years($input);
        }
        CommandCatalog::validateParams('domain.transfer', $params, $profile);
        return [['command' => 'domain.transfer', 'params' => $params, 'status' => 'prepared', 'job_uuid' => null]];
    }

    /** Runs in the same transaction as job creation. */
    public static function queued(PDO $pdo, string $id, string $owner, string $registry, array $params): void
    {
        $name = Ownership::name($params['domainname'], 'domain');
        if ($params['op'] === 'request') {
            $pdo->prepare("INSERT IGNORE INTO domain_transfers
                (id, registry_id, reseller_id, domainname, direction, observation_key, request_job_id, latest_job_id, status)
                VALUES (?, ?, ?, ?, 'incoming', ?, ?, ?, 'queued')")
                ->execute([$id, $registry, $owner, $name, hash('sha256', $id), $id, $id]);
        } else {
            $pdo->prepare("UPDATE domain_transfers SET latest_job_id = ? WHERE registry_id = ? AND reseller_id = ?
                AND domainname = ? AND status = 'pending'")->execute([$id, $registry, $owner, $name]);
            if (in_array($params['op'], ['approve', 'reject'], true)
                && !PanelData::rows($pdo, 'SELECT id FROM domain_transfers WHERE latest_job_id = ?', [$id])) {
                $pdo->prepare("INSERT INTO domain_transfers
                    (id, registry_id, reseller_id, domainname, direction, observation_key, latest_job_id, status)
                    VALUES (?, ?, ?, ?, 'outgoing', ?, ?, 'queued')")
                    ->execute([$id, $registry, $owner, $name, hash('sha256', $id), $id]);
            }
        }
    }

    /** Called atomically with completion, including API jobs and reconciliation. */
    public static function observe(PDO $pdo, array $job, string $status, array $result, bool $updateOwnership = true): void
    {
        if ($job['command'] !== 'domain.transfer') { return; }
        $params = Ownership::params($job);
        $op = $params['op'];
        $result = $result['observation'] ?? $result;
        $rows = PanelData::rows($pdo, 'SELECT * FROM domain_transfers WHERE latest_job_id = ? OR request_job_id = ? FOR UPDATE', [$job['id'], $job['id']]);
        $state = $result['trStatus'] ?? '';
        $valid = !isset($result['error']) && (int) ($result['code'] ?? 0) === 1000
            && strtolower($result['name'] ?? '') === strtolower($params['domainname']);
        if ($op === 'query' && $valid && !empty($result['reDate'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['direction'] !== 'outgoing'
                || $r['requested_at'] === '' || $r['requested_at'] === $result['reDate']));
        }
        if ($rows === [] && $op === 'query' && $valid
            && in_array($state, ['pending', 'clientApproved', 'serverApproved', 'clientCancelled', 'clientRejected', 'serverCancelled'], true)
            && !empty($result['reDate'])) {
            $registry = PanelData::rows($pdo, 'SELECT config_nonce, config_cipher FROM registries WHERE id = ?', [$job['registry_id']])[0];
            $config = json_decode(Crypto::decrypt($registry['config_nonce'], $registry['config_cipher'], 'registry-config:' . $job['registry_id']), true, 64, JSON_THROW_ON_ERROR);
            $client = $config['login']['clID'] ?? '';
            // Query permission is tied to the managed object's owner. Never import somebody else's transfer.
            $owned = PanelData::rows($pdo, "SELECT reseller_id FROM object_ownership WHERE registry_id = ? AND object_type = 'domain' AND object_name = ? AND active = 1",
                [$job['registry_id'], Ownership::name($params['domainname'], 'domain')])[0] ?? null;
            if ($client === '' || ($result['acID'] ?? '') !== $client || ($result['reID'] ?? '') === $client
                || ($owned['reseller_id'] ?? '') !== $job['reseller_id']) { return; }
            $key = hash('sha256', Util::json([$job['registry_id'], $job['reseller_id'], strtolower($params['domainname']), $result['reDate'], $result['reID'] ?? '', $result['acID']]));
            $pdo->prepare("INSERT IGNORE INTO domain_transfers
                (id, registry_id, reseller_id, domainname, direction, observation_key, latest_job_id, status, requested_at)
                VALUES (?, ?, ?, ?, 'outgoing', ?, ?, 'pending', ?)")
                ->execute([Util::uuid(), $job['registry_id'], $job['reseller_id'], strtolower($params['domainname']), $key, $job['id'], $result['reDate']]);
            $rows = PanelData::rows($pdo, 'SELECT * FROM domain_transfers WHERE observation_key = ? FOR UPDATE', [$key]);
            $pdo->prepare("UPDATE domain_transfers SET status = 'unknown' WHERE registry_id = ? AND reseller_id = ?
                AND domainname = ? AND direction = 'outgoing' AND observation_key <> ? AND status = 'pending'")
                ->execute([$job['registry_id'], $job['reseller_id'], strtolower($params['domainname']), $key]);
        }
        foreach ($rows as $row) {
            $next = $row['status']; $tr = $row['tr_status']; $date = $row['requested_at'];
            if ($row['direction'] === 'incoming' && $op === 'query' && $valid && $state !== '') {
                $parent = PanelData::rows($pdo, 'SELECT * FROM epp_jobs WHERE id = ?', [$row['request_job_id']])[0];
                $observed = strtotime($result['reDate'] ?? '');
                $expected = strtotime($date);
                $created = (new \DateTimeImmutable($parent['created_at'], new \DateTimeZone('UTC')))->getTimestamp();
                if ($observed === false || ($expected !== false ? $observed !== $expected : $observed < $created - 60 || $observed > time() + 300)) { continue; }
            }
            if ($op === 'request' || $row['status'] === 'queued') { $next = $status; }
            if ($valid && in_array($state, ['pending', 'clientApproved', 'serverApproved', 'clientCancelled', 'clientRejected', 'serverCancelled'], true)) {
                // Do not apply a historical query to a newer outgoing request.
                if ($row['direction'] === 'outgoing' && $date !== '' && ($result['reDate'] ?? '') !== $date) { continue; }
                $tr = $state; $date = $result['reDate'] ?? $date;
                $next = match ($state) {
                    'pending' => 'pending',
                    'clientApproved', 'serverApproved' => 'succeeded',
                    default => 'rejected',
                };
            } elseif ($status === 'succeeded' && in_array($op, ['approve', 'reject', 'cancel'], true)) {
                $tr = match ($op) { 'approve' => 'clientApproved', 'reject' => 'clientRejected', 'cancel' => 'clientCancelled' };
                $next = $op === 'approve' ? 'succeeded' : 'rejected';
            }
            if ($updateOwnership && $row['direction'] === 'outgoing' && $valid && in_array($tr, ['clientApproved', 'serverApproved'], true)) {
                $pdo->prepare("DELETE FROM object_ownership WHERE registry_id = ? AND object_type = 'domain' AND object_name = ? AND reseller_id = ? AND job_id = ?")
                    ->execute([$job['registry_id'], $row['domainname'], $row['reseller_id'], $job['id']]);
            }
            $pdo->prepare('UPDATE domain_transfers SET status = ?, tr_status = ?, requested_at = ? WHERE id = ?')
                ->execute([$next, $tr, $date, $row['id']]);
        }
    }

    public static function actions(array $row): array
    {
        if ($row['profile'] === 'LT' || $row['status'] !== 'pending'
            || in_array($row['job_status'], ['queued', 'processing', 'unknown'], true)
            || $row['job_status'] === 'pending' && $row['latest_job_id'] !== $row['request_job_id']) { return []; }
        return $row['direction'] === 'incoming' ? ['query', 'cancel'] : ['query', 'approve', 'reject'];
    }

    public static function get(PDO $pdo, array $actor, string $id): array
    {
        $where = (int) $actor['role'] === 0 ? '' : ' AND t.reseller_id = ?';
        $args = [$id]; if ($where !== '') { $args[] = $actor['id']; }
        $row = PanelData::rows($pdo, self::sql() . ' WHERE t.id = ?' . $where, $args)[0] ?? null;
        if ($row === null) { throw new ApiException(404, 'transfer_not_found', 'Transfer was not found.'); }
        return $row;
    }

    public static function authorizeAction(PDO $pdo, array $actor, array $domain, array $input, string $op): void
    {
        $row = self::get($pdo, $actor, DomainParams::text($input, 'transfer_id'));
        if ($row['registry_id'] !== $domain['registry_id'] || $row['domainname'] !== $domain['object_name']
            || $row['reseller_id'] !== $domain['reseller_id'] || !in_array($op, self::actions($row), true)) {
            throw new ApiException(409, 'transfer_action_unavailable', 'This action is no longer available. Refresh the transfers list.');
        }
    }

    /** Populate existing request history on upgrade, without sending registry commands. */
    public static function backfill(PDO $pdo): void
    {
        $cursor = ''; $date = '1970-01-01';
        do {
            $jobs = PanelData::rows($pdo, "SELECT * FROM epp_jobs WHERE command = 'domain.transfer'
                AND (created_at > ? OR created_at = ? AND id > ?) ORDER BY created_at, id LIMIT 100", [$date, $date, $cursor]);
            foreach ($jobs as $job) {
                $cursor = $job['id']; $date = $job['created_at']; $params = Ownership::params($job);
                $pdo->beginTransaction();
                try {
                    self::queued($pdo, $job['id'], $job['reseller_id'], $job['registry_id'], $params);
                    $result = $job['response_cipher'] === null ? [] : json_decode(Crypto::decrypt($job['response_nonce'], $job['response_cipher'], 'job-response:' . $job['id']), true, 64, JSON_THROW_ON_ERROR);
                    self::observe($pdo, $job, $job['status'], $result, false);
                    $pdo->prepare('UPDATE domain_transfers SET created_at = ? WHERE id = ?')->execute([$job['created_at'], $job['id']]);
                    $pdo->commit();
                } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
            }
        } while (count($jobs) === 100);
    }

    private static function sql(): string
    {
        return 'SELECT t.*, r.name AS registry, r.profile, u.username, j.status AS job_status
            FROM domain_transfers t JOIN registries r ON r.id = t.registry_id
            JOIN resellers u ON u.id = t.reseller_id JOIN epp_jobs j ON j.id = t.latest_job_id';
    }

    public static function listing(PDO $pdo, array $actor, int $page): array
    {
        $args = []; $where = '';
        if ((int) $actor['role'] !== 0) { $where = ' WHERE t.reseller_id = ?'; $args[] = $actor['id']; }
        $rows = PanelData::rows($pdo, self::sql() . $where . ' ORDER BY t.created_at DESC, t.id DESC LIMIT 51 OFFSET ' . (($page - 1) * 50), $args);
        $next = count($rows) > 50; if ($next) { array_pop($rows); }
        foreach ($rows as &$row) {
            $row['actions'] = self::actions($row);

        }
        return ['rows' => $rows, 'next' => $next];
    }

    /** Internal discovery keys distinguish scheduled queries from customer requests. */
    public const DISCOVERY_SQL = "(j.command = 'domain.transfer' AND j.idempotency_key LIKE 'transfer-discovery-%')";

    /** Periodic read-only discovery; does not send or replay any transfer request. */
    public static function discover(PDO $pdo): void
    {
        $interval = max(60, Config::int('API_TRANSFER_CHECK_SECONDS', 900));
        $rows = PanelData::rows($pdo, "SELECT o.*, r.profile FROM object_ownership o JOIN registries r ON r.id = o.registry_id
            JOIN reseller_registries a ON a.registry_id = o.registry_id AND a.reseller_id = o.reseller_id
            JOIN resellers u ON u.id = o.reseller_id
            WHERE o.object_type = 'domain' AND o.active = 1 AND o.domain_state = 'active' AND o.job_id IS NULL
              AND r.enabled = 1 AND a.enabled = 1 AND u.status = 'active' AND r.profile <> 'LT'
              AND (o.next_check_at IS NULL OR o.next_check_at <= UTC_TIMESTAMP(6))
            ORDER BY COALESCE(o.next_check_at, '1970-01-01'), o.registry_id, o.object_name LIMIT 8");
        foreach ($rows as $row) {
            $claim = $pdo->prepare("UPDATE object_ownership SET next_check_at = UTC_TIMESTAMP(6) + INTERVAL {$interval} SECOND
                WHERE registry_id = ? AND object_type = 'domain' AND object_name = ? AND job_id IS NULL
                AND (next_check_at IS NULL OR next_check_at <= UTC_TIMESTAMP(6))");
            $claim->execute([$row['registry_id'], $row['object_name']]);
            if ($claim->rowCount() !== 1) { continue; }
            try {
                Ledger::createJob($pdo, $row['reseller_id'], $row['registry_id'], 'domain.transfer',
                    'transfer-discovery-' . Util::uuid(), ['domainname' => $row['object_name'], 'op' => 'query']);
            } catch (ApiException) { /* Unsupported profile/policy or a concurrent operation: try next interval. */ }
        }
    }
}
