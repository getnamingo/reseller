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

use PDO;

final class Lifecycle
{
    public static function status(string $command, array $params, array $result): string
    {
        $code = isset($result['code']) && is_numeric($result['code']) ? (int) $result['code'] : null;
        $mutating = CommandCatalog::isMutating($command, $params);
        if (isset($result['error']) || ($code !== null && $code >= 2000)) {
            return $mutating && ($code === null || $code < 2000) ? 'unknown' : 'rejected';
        }
        if (!$mutating) { return 'succeeded'; }
        if ($code === null) { return 'unknown'; }
        if ($code === 1001 || in_array($command, ['domain.restore', 'domain.restore_report'], true)) { return 'pending'; }
        if ($command === 'domain.transfer' && ($params['op'] ?? '') === 'request') {
            return match ($result['trStatus'] ?? '') {
                'clientApproved', 'serverApproved' => 'succeeded',
                'clientCancelled', 'clientRejected', 'serverCancelled' => 'rejected',
                default => 'pending',
            };
        }
        return 'succeeded';
    }

    public static function followup(array $parent, string $command, array $params): bool
    {
        $original = Ownership::params($parent);
        if ($command === 'domain.transfer' && $parent['command'] === 'domain.transfer') {
            return ($params['op'] ?? '') === 'query' && in_array($parent['status'], ['pending', 'unknown'], true)
                || ($params['op'] ?? '') === 'cancel' && ($original['op'] ?? '') === 'request' && $parent['status'] === 'pending';
        }
        return $command === 'domain.restore_report' && $parent['command'] === 'domain.restore' && $parent['status'] === 'pending'
            || $command === 'domain.info' && in_array($parent['status'], ['pending', 'unknown'], true) && (bool) ($parent['owned_active'] ?? false);
    }

    /** Only registry observations settle accepted work. Unknown writes need operator reconciliation. */
    public static function decision(array $job, array $observation, string $clientId, ?array $request = null): ?string
    {
        if ($job['status'] !== 'pending' || isset($observation['error'])) { return null; }
        $params = Ownership::params($job);
        if ($job['command'] === 'domain.transfer') {
            if ((int) ($observation['code'] ?? 0) !== 1000 || strtolower($observation['name'] ?? '') !== strtolower($params['domainname'])) { return null; }
            $incoming = in_array($params['op'] ?? '', ['request', 'cancel'], true);
            if (($observation[$incoming ? 'reID' : 'acID'] ?? '') !== $clientId) { return null; }
            $date = strtotime($observation['reDate'] ?? '');
            // A cancellation may arrive days after the transfer request, without its own reDate.
            $correlation = ($params['op'] ?? '') === 'cancel' ? ($request ?? $job) : $job;
            $created = (new \DateTimeImmutable($correlation['created_at'], new \DateTimeZone('UTC')))->getTimestamp();
            $old = $correlation['response_cipher'] === null ? [] : json_decode(Crypto::decrypt($correlation['response_nonce'], $correlation['response_cipher'], 'job-response:' . $correlation['id']), true);
            $expected = strtotime($old['reDate'] ?? $old['observation']['reDate'] ?? '');
            if ($date === false || ($expected !== false ? $date !== $expected : $date < $created - 60 || $date > time() + 300)) { return null; }
            $state = $observation['trStatus'] ?? '';
            $approved = in_array($state, ['clientApproved', 'serverApproved'], true);
            $cancelled = in_array($state, ['clientCancelled', 'clientRejected', 'serverCancelled'], true);
            return match ($params['op'] ?? '') {
                'request' => $approved ? 'succeeded' : ($cancelled ? 'rejected' : null),
                'approve' => $approved ? 'succeeded' : null,
                'cancel' => $state === 'clientCancelled' ? 'succeeded' : null,
                'reject' => $state === 'clientRejected' ? 'succeeded' : null,
                default => null,
            };
        }
        if ($job['command'] === 'domain.delete' && (int) ($observation['code'] ?? 0) === 2303) { return 'succeeded'; }
        if ((int) ($observation['code'] ?? 0) !== 1000 || ($observation['clID'] ?? '') !== $clientId) { return null; }
        $statuses = array_values($observation['status'] ?? []);
        if ($statuses === [] || count(array_filter($statuses, 'is_string')) !== count($statuses)) { return null; }
        $rgp = $observation['rgp_status'] ?? null;
        if ($job['command'] === 'domain.delete') {
            return is_array($rgp) && array_intersect($rgp, ['redemptionPeriod', 'pendingDelete']) !== [] ? 'succeeded' : null;
        }
        if (in_array($job['command'], ['domain.restore', 'domain.restore_report'], true)) {
            return is_array($rgp) && array_intersect($rgp, ['redemptionPeriod', 'pendingRestore', 'pendingDelete']) === []
                && !in_array('pendingDelete', $statuses, true) ? 'succeeded' : null;
        }
        if (in_array($job['command'], ['domain.create', 'domain.create_dnssec'], true)) {
            return !in_array('pendingCreate', $statuses, true) ? 'succeeded' : null;
        }
        return null;
    }

    public static function settle(PDO $pdo, array $job, array $observation, string $clientId): bool
    {
        $request = null;
        if ($job['command'] === 'domain.transfer' && (Ownership::params($job)['op'] ?? '') === 'cancel' && $job['parent_job_id'] !== null) {
            $stmt = $pdo->prepare("SELECT * FROM epp_jobs WHERE id = ? AND reseller_id = ? AND registry_id = ? AND command = 'domain.transfer'");
            $stmt->execute([$job['parent_job_id'], $job['reseller_id'], $job['registry_id']]);
            $request = $stmt->fetch();
            if ($request === false || (Ownership::params($request)['op'] ?? '') !== 'request'
                || strtolower(Ownership::params($request)['domainname'] ?? '') !== strtolower(Ownership::params($job)['domainname'] ?? '')) { return false; }
        }
        $status = self::decision($job, $observation, $clientId, $request);
        if ($status === null) { return false; }
        Ledger::completeJob($pdo, $job['id'], $status,
            ['code' => $status === 'succeeded' ? 1000 : 2304, 'reconciled' => true,
                'purged' => $job['command'] === 'domain.delete' && (int) ($observation['code'] ?? 0) === 2303,
                'observation' => $observation], $status === 'succeeded' ? 1000 : 2304, true);
        return true;
    }

    public static function observeFollowup(PDO $pdo, array $child, array $result, string $clientId): void
    {
        if ($child['parent_job_id'] === null) { return; }
        $stmt = $pdo->prepare('SELECT * FROM epp_jobs WHERE id = ? AND reseller_id = ? AND registry_id = ?');
        $stmt->execute([$child['parent_job_id'], $child['reseller_id'], $child['registry_id']]);
        $parent = $stmt->fetch();
        if ($parent === false || $parent['status'] !== 'pending') { return; }
        if ($parent['command'] === 'domain.transfer') { self::settle($pdo, $parent, $result, $clientId); }
        else { $pdo->prepare('UPDATE epp_jobs SET reconcile_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = \'pending\'')->execute([$parent['id']]); }
    }

    public static function observeDeleted(PDO $pdo, string $registry, string $name, string $owner, array $info): void
    {
        if ((int) ($info['code'] ?? 0) === 2303) {
            $pdo->prepare("DELETE FROM object_ownership WHERE registry_id = ? AND object_type = 'domain' AND object_name = ? AND reseller_id = ? AND domain_state = 'deleted' AND job_id IS NULL")
                ->execute([$registry, $name, $owner]);
        }
    }

    public static function schedule(PDO $pdo, string $id, string $reseller): array
    {
        $job = Ledger::fetchJob($pdo, $id, $reseller);
        if (!in_array($job['status'], ['queued', 'pending'], true)) {
            throw new ApiException(409, 'not_reconcilable', 'Only queued or registry-accepted pending jobs can be scheduled. Unknown writes require operator verification.');
        }
        $pdo->prepare("UPDATE epp_jobs SET next_attempt_at = UTC_TIMESTAMP(6), reconcile_at = UTC_TIMESTAMP(6) WHERE id = ? AND reseller_id = ? AND status IN ('queued', 'pending')")->execute([$id, $reseller]);
        return Ledger::fetchJob($pdo, $id, $reseller);
    }
}
