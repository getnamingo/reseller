<?php

declare(strict_types=1);

namespace Namingo\ResellerApi;

use PDO;
use Throwable;

/** Durable database queue. Only never-attempted queued jobs may be dispatched again. */
final class Queue
{
    public static function lockName(string $registry): string { return 'epp:' . substr(hash('sha256', $registry), 0, 48); }

    public static function tick(PDO $pdo, callable $send): int
    {
        $name = 'dispatch:' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 48);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $lock->execute([$name]);
        if ((string) $lock->fetchColumn() !== '1') { return 0; }
        try { return self::dispatch($pdo, $send); }
        finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
    }

    private static function dispatch(PDO $pdo, callable $send): int
    {
        $batch = min(100, max(1, Config::int('API_QUEUE_BATCH', 8)));
        self::recover($pdo, $batch);
        $maximum = max(1, Config::int('API_QUEUE_MAX_INFLIGHT', Config::int('API_TASK_WORKERS', 4) * 2));
        $inflight = (int) $pdo->query("SELECT COUNT(*) FROM epp_jobs WHERE status = 'processing'
            OR status IN ('queued', 'pending') AND dispatch_until > UTC_TIMESTAMP(6)")->fetchColumn();
        $inflight += (int) $pdo->query('SELECT COUNT(*) FROM object_ownership WHERE check_until > UTC_TIMESTAMP(6)')->fetchColumn();
        $batch = min($batch, max(0, $maximum - $inflight));
        if ($batch === 0) { return 0; }
        $lease = max(5, Config::int('API_DISPATCH_LEASE_SECONDS', 60));
        $rows = PanelData::rows($pdo, "SELECT id, status FROM epp_jobs WHERE
            (status = 'queued' AND next_attempt_at <= UTC_TIMESTAMP(6) OR status = 'pending' AND reconcile_at <= UTC_TIMESTAMP(6))
            AND (dispatch_until IS NULL OR dispatch_until <= UTC_TIMESTAMP(6)) ORDER BY created_at, id LIMIT {$batch}");
        $count = 0;
        foreach ($rows as $row) {
            $claim = $pdo->prepare("UPDATE epp_jobs SET dispatch_until = UTC_TIMESTAMP(6) + INTERVAL {$lease} SECOND
                WHERE id = ? AND status = ? AND (dispatch_until IS NULL OR dispatch_until <= UTC_TIMESTAMP(6))
                  AND (status = 'queued' AND next_attempt_at <= UTC_TIMESTAMP(6) OR status = 'pending' AND reconcile_at <= UTC_TIMESTAMP(6))");
            $claim->execute([$row['id'], $row['status']]);
            if ($claim->rowCount() !== 1) { continue; }
            try { $sent = $send(['kind' => $row['status'] === 'queued' ? 'job' : 'reconcile', 'id' => $row['id']]); }
            catch (Throwable) { $sent = false; }
            if ($sent === false) {
                $pdo->prepare('UPDATE epp_jobs SET dispatch_until = NULL WHERE id = ? AND status = ?')->execute([$row['id'], $row['status']]);
            } else { ++$count; }
        }
        $remaining = max(0, $batch - $count);
        $deleted = PanelData::rows($pdo, "SELECT registry_id, object_name, reseller_id FROM object_ownership
            WHERE object_type = 'domain' AND domain_state = 'deleted' AND job_id IS NULL AND next_check_at <= UTC_TIMESTAMP(6) AND (check_until IS NULL OR check_until <= UTC_TIMESTAMP(6)) ORDER BY next_check_at LIMIT {$remaining}");
        foreach ($deleted as $row) {
            $interval = max(30, Config::int('API_PURGE_CHECK_SECONDS', 300));
            $claim = $pdo->prepare("UPDATE object_ownership SET next_check_at = UTC_TIMESTAMP(6) + INTERVAL {$interval} SECOND, check_until = UTC_TIMESTAMP(6) + INTERVAL {$lease} SECOND
                WHERE registry_id = ? AND object_type = 'domain' AND object_name = ? AND reseller_id = ?
                  AND domain_state = 'deleted' AND job_id IS NULL AND next_check_at <= UTC_TIMESTAMP(6) AND (check_until IS NULL OR check_until <= UTC_TIMESTAMP(6))");
            $claim->execute([$row['registry_id'], $row['object_name'], $row['reseller_id']]);
            if ($claim->rowCount() !== 1) { continue; }
            try { $sent = $send(['kind' => 'deleted', ...$row]); } catch (Throwable) { $sent = false; }
            if ($sent === false) {
                $pdo->prepare("UPDATE object_ownership SET next_check_at = UTC_TIMESTAMP(6) + INTERVAL 5 SECOND, check_until = NULL WHERE registry_id = ? AND object_type = 'domain' AND object_name = ? AND reseller_id = ? AND domain_state = 'deleted'")
                    ->execute([$row['registry_id'], $row['object_name'], $row['reseller_id']]);
            } else { ++$count; }
        }
        return $count;
    }

    public static function recover(PDO $pdo, int $batch = 8): void
    {
        $seconds = max(30, Config::int('API_JOB_STALE_SECONDS', 900));
        $batch = min(100, max(1, $batch));
        $rows = PanelData::rows($pdo, "SELECT id, registry_id FROM epp_jobs WHERE status = 'processing'
            AND started_at < UTC_TIMESTAMP(6) - INTERVAL {$seconds} SECOND ORDER BY started_at LIMIT {$batch}");
        foreach ($rows as $row) {
            $name = self::lockName($row['registry_id']);
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $lock->execute([$name]);
            if ((string) $lock->fetchColumn() !== '1') { continue; }
            try {
                // Never replay processing writes: a dead worker may already have sent them.
                $still = $pdo->prepare("SELECT 1 FROM epp_jobs WHERE id = ? AND status = 'processing' AND started_at < UTC_TIMESTAMP(6) - INTERVAL {$seconds} SECOND");
                $still->execute([$row['id']]);
                if (!$still->fetchColumn()) { continue; }
                Ledger::completeJob($pdo, $row['id'], 'unknown', ['error' => 'worker_interrupted',
                    'message' => 'Stale processing job requires registry-side verification. Original command was not replayed.'], null);
            } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
        }
    }
}
