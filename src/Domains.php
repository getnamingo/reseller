<?php

declare(strict_types=1);

namespace Namingo\ResellerApi;

use PDO;

/** Durable panel plans; all registry work and billing remain in the API ledger. */
final class Domains
{
    private const ACTIVE = ['prepared', 'queued', 'processing', 'pending', 'unknown'];

    public static function owned(PDO $pdo, array $actor, string $registry, string $name): array
    {
        $sql = "SELECT o.*, r.name AS registry, r.profile, u.username FROM object_ownership o
            JOIN registries r ON r.id = o.registry_id JOIN resellers u ON u.id = o.reseller_id
            WHERE o.object_type = 'domain' AND o.registry_id = ? AND o.object_name = ?";
        $args = [$registry, Ownership::name($name, 'domain')];
        if ((int) $actor['role'] !== 0) { $sql .= ' AND o.reseller_id = ?'; $args[] = $actor['id']; }
        $row = PanelData::rows($pdo, $sql, $args)[0] ?? null;
        if ($row === null) { throw new ApiException(404, 'domain_not_found', 'Domain was not found.'); }
        return $row;
    }

    private static function locked(PDO $pdo, string $owner, callable $fn): mixed
    {
        $lock = 'panel-domains:' . $owner;
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $stmt->execute([$lock]);
        if ((string) $stmt->fetchColumn() !== '1') { throw new ApiException(409, 'domain_busy', 'Domain operations are being refreshed. Retry shortly.'); }
        try { return $fn(); }
        finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
    }

    private static function decode(array $row): array
    {
        $row['data'] = json_decode(Crypto::decrypt($row['data_nonce'], $row['data_cipher'], 'panel-domain:' . $row['id']), true, 64, JSON_THROW_ON_ERROR);
        unset($row['data_nonce'], $row['data_cipher']);
        return $row;
    }

    private static function save(PDO $pdo, array $row): void
    {
        [$nonce, $cipher] = Crypto::encrypt(Util::json($row['data']), 'panel-domain:' . $row['id']);
        $pdo->prepare('UPDATE panel_domain_operations SET status = ?, data_nonce = ?, data_cipher = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')
            ->execute([$row['status'], $nonce, $cipher, $row['id']]);
    }

    public static function submit(PDO $pdo, array $actor, array $input, string $ip, ?array $domain = null): string
    {
        $key = $input['key'] ?? null;
        if (!is_string($key) || !Util::isUuid($key)) { throw new ApiException(422, 'invalid_key', 'Reload the form before submitting.'); }
        $owner = $domain['reseller_id'] ?? ((int) $actor['role'] === 0 ? ($input['reseller_id'] ?? $actor['id']) : $actor['id']);
        if (!is_string($owner) || !Util::isUuid($owner) || !PanelData::rows($pdo, 'SELECT id FROM resellers WHERE id = ?', [$owner])) { throw new ApiException(422, 'invalid_reseller', 'Choose a reseller.'); }
        if ((int) $actor['role'] !== 0 && $owner !== $actor['id']) { throw new ApiException(403, 'domain_not_owned', 'Domain is unavailable for this account.'); }
        $action = DomainParams::text($input, 'action');
        if ($domain === null && $action !== 'create' && $action !== 'transfer-request') { throw new ApiException(422, 'invalid_action', 'Open the domain before changing it.'); }
        if ($domain !== null && $action === 'create') { throw new ApiException(422, 'invalid_action', 'Use the create-domain form.'); }
        $hashInput = $input; unset($hashInput['csrf'], $hashInput['key']);
        $hash = hash('sha256', Util::json([$hashInput, $domain['registry_id'] ?? null, $domain['object_name'] ?? null, $owner]));
        return self::locked($pdo, $owner, function () use ($pdo, $actor, $input, $ip, $domain, $owner, $key, $hash, $action) {
            $existing = PanelData::rows($pdo, 'SELECT * FROM panel_domain_operations WHERE batch_id = ?', [$key]);
            if ($existing !== []) {
                foreach ($existing as $row) {
                    if ($row['reseller_id'] !== $owner || !hash_equals($row['request_hash'], $hash)) { throw new ApiException(409, 'idempotency_conflict', 'This submission key already belongs to another request.'); }
                }
                foreach ($existing as $row) { self::advance($pdo, self::decode($row), $actor); }
                return $key;
            }
            if (str_starts_with($action, 'transfer-') && $domain !== null) {
                Transfers::authorizeAction($pdo, $actor, $domain, $input, substr($action, 9));
            }
            $names = $domain === null && $action === 'transfer-request' ? array_keys(Transfers::entries($input)) : ($domain === null ? preg_split('/[\s,]+/', DomainParams::text($input, 'domains'), -1, PREG_SPLIT_NO_EMPTY) : [$domain['object_name']]);
            if (count($names) < 1 || count($names) > 50) { throw new ApiException(422, 'invalid_domains', 'Enter between 1 and 50 ASCII/punycode domain names.'); }
            $names = array_values(array_unique(array_map('strtolower', $names)));
            $routes = $domain === null ? PanelData::checkRoutes($pdo, $owner, $names)
                : [['name' => $domain['object_name'], 'registry_id' => $domain['registry_id']]];
            $prepared = [];
            foreach ($routes as $route) {
                $row = ['id' => Util::uuid(), 'batch_id' => $key, 'reseller_id' => $owner, 'registry_id' => $route['registry_id'] ?? null,
                    'domainname' => $route['name'], 'request_hash' => $hash, 'status' => 'prepared', 'data' => ['action' => $action, 'steps' => [], 'message' => '']];
                // Invalid bulk entries stay visible as per-domain errors, and never reach the registry.
                if (strlen($row['domainname']) > 253) { $row['domainname'] = mb_strcut($row['domainname'], 0, 253, 'UTF-8'); }
                try {
                    if ($row['registry_id'] === null) { throw new ApiException(422, 'domain_unavailable', $route['reason']); }
                    $reg = PanelData::rows($pdo, 'SELECT profile FROM registries WHERE id = ?', [$row['registry_id']])[0];
                    $busy = PanelData::rows($pdo, "SELECT id FROM panel_domain_operations WHERE reseller_id = ? AND registry_id = ? AND domainname = ?
                        AND status IN ('prepared','queued','processing','pending','unknown') LIMIT 1", [$owner, $row['registry_id'], $row['domainname']]);
                    $steps = str_starts_with($action, 'transfer-')
                        ? Transfers::plan($reg['profile'], substr($action, 9), $row['domainname'], $input)
                        : DomainParams::plan($reg['profile'], $action, $row['domainname'], $input);
                    if ($busy && !self::followup($pdo, $owner, $row['registry_id'], $row['domainname'], $steps[0])) {
                        throw new ApiException(409, 'domain_busy', 'An existing operation must finish or be reconciled before another request.');
                    }
                    Access::domains($pdo, $row['registry_id'], $owner, $action === 'create' ? 'domain.create' : 'domain.info', ['domainname' => $row['domainname']]);
                    $row['data']['steps'] = $steps;
                } catch (ApiException $e) { $row['status'] = 'rejected'; $row['data']['message'] = $e->errorCode . ': ' . $e->getMessage(); }
                $prepared[] = $row;
            }
            $pdo->beginTransaction();
            try {
                foreach ($prepared as $row) {
                    [$nonce, $cipher] = Crypto::encrypt(Util::json($row['data']), 'panel-domain:' . $row['id']);
                    $pdo->prepare('INSERT INTO panel_domain_operations (id, batch_id, reseller_id, registry_id, domainname, request_hash, status, data_nonce, data_cipher) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$row['id'], $key, $owner, $row['registry_id'], $row['domainname'], $hash, $row['status'], $nonce, $cipher]);
                }
                $pdo->commit();
            } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
            Audit::record($pdo, $actor['id'], 'panel.domain.submit', 'domain', $key, $ip, ['owner' => $owner, 'action' => $action, 'count' => count($prepared)]);
            foreach ($prepared as $row) { self::advance($pdo, $row, $actor); }
            return $key;
        });
    }

    private static function followup(PDO $pdo, string $owner, string $registry, string $name, array $step): bool
    {
        $parent = PanelData::rows($pdo, "SELECT j.*, o.active AS owned_active FROM object_ownership o
            JOIN epp_jobs j ON j.id = o.job_id AND j.reseller_id = o.reseller_id AND j.registry_id = o.registry_id
            WHERE o.object_type = 'domain' AND o.reseller_id = ? AND o.registry_id = ? AND o.object_name = ?",
            [$owner, $registry, $name])[0] ?? null;
        return $parent !== null && Lifecycle::followup($parent, $step['command'], $step['params']);
    }

    /** At most one bounded chain per saved intent; Ledger recovers a lost job UUID by key. */
    private static function advance(PDO $pdo, array $row, array $actor): array
    {
        if (!in_array($row['status'], self::ACTIVE, true)) { return $row; }
        if (($row['data']['retry_at'] ?? 0) > time()) { return $row; }
        foreach ($row['data']['steps'] as $i => &$step) {
            if (in_array($step['status'], ['succeeded', 'skipped'], true)) { continue; }
            $row['status'] = 'prepared';
            try {
                if ($step['job_uuid'] === null && isset($step['check'])) {
                    // Recover our own committed create before treating its reservation as shared.
                    $saved = PanelData::rows($pdo, 'SELECT id FROM epp_jobs WHERE reseller_id = ? AND registry_id = ? AND command = ? AND idempotency_key = ?',
                        [$row['reseller_id'], $row['registry_id'], $step['command'], 'panel-domain-' . $row['id'] . '-' . $i])[0] ?? null;
                    if ($saved !== null) { $step['job_uuid'] = $saved['id']; }
                }
                if ($step['job_uuid'] === null) {
                    if (isset($step['check'])) {
                        $check = $row['data']['steps'][$step['check']]['result']['hosts'] ?? [];
                        $matched = array_values(array_filter($check, fn ($h) => is_array($h) && strtolower($h['name'] ?? '') === $step['params']['hostname']));
                        if (count($matched) !== 1 || !in_array($matched[0]['avail'] ?? null, [0, 1, '0', '1'], true)) {
                            throw new ApiException(422, 'invalid_host_check', 'The registry did not return a definite availability result for this host.');
                        }
                        if (!(bool) $matched[0]['avail']) { $step['status'] = 'skipped'; continue; }
                        // Other bulk members may have observed availability before the first create.
                        // Consult the shared ownership reservation instead of submitting another create.
                        $host = PanelData::rows($pdo, "SELECT active, job_id FROM object_ownership
                            WHERE registry_id = ? AND object_type = 'host' AND object_name = ?",
                            [$row['registry_id'], $step['params']['hostname']])[0] ?? null;
                        if ($host !== null && $host['job_id'] !== null) {
                            $step['message'] = 'Waiting for the shared nameserver operation to complete.';
                            break;
                        }
                        if ($host !== null && (bool) $host['active']) {
                            $step['status'] = 'skipped'; $step['message'] = 'Shared nameserver is available; reused.';
                            continue;
                        }
                    }
                    self::throttle($pdo, $actor['id']);
                    $job = Ledger::createJob($pdo, $row['reseller_id'], $row['registry_id'], $step['command'], 'panel-domain-' . $row['id'] . '-' . $i, $step['params']);
                    $step['job_uuid'] = $job['job_id'];
                    // Save immediately. A crash before this save still recovers the same ledger job by key.
                    self::save($pdo, $row);
                }
                $job = Ledger::fetchJob($pdo, $step['job_uuid'], $row['reseller_id']);
                if ($job['registry_uuid'] !== $row['registry_id'] || $job['command'] !== $step['command']) { throw new \RuntimeException('Domain job identity mismatch.'); }
                $step['status'] = $job['status']; $step['epp_code'] = $job['epp_code']; $step['result'] = $job['result'];
                $step['message'] = $job['result']['error'] ?? $job['result']['msg'] ?? $job['result']['message'] ?? '';
                $row['status'] = $job['status'];
                if ($job['status'] !== 'succeeded') { break; }
            } catch (ApiException $e) {
                $step['message'] = $e->errorCode . ': ' . $e->getMessage();
                if ($step['job_uuid'] === null && $step['command'] === 'host.create' && isset($step['check']) && $e->errorCode === 'object_busy') {
                    // A competing API create can reserve the host after the check above.
                    // Revisit that reservation next tick without replacing the frozen step key.
                    $row['status'] = 'prepared';
                }
                elseif ($step['job_uuid'] !== null) { $row['status'] = $step['status']; $row['data']['retry_at'] = time() + 10; }
                elseif (in_array($e->httpStatus, [400, 402, 403, 404, 409, 422, 428], true)) { $step['status'] = $row['status'] = 'rejected'; }
                else { $row['data']['retry_at'] = time() + 10; $row['status'] = 'prepared'; }
                break;
            }
        }
        unset($step);
        $states = array_column($row['data']['steps'], 'status');
        if ($states !== [] && array_diff($states, ['succeeded', 'skipped']) === []) { $row['status'] = 'succeeded'; }
        self::save($pdo, $row);
        return $row;
    }

    private static function throttle(PDO $pdo, string $actor): void
    {
        $bucket = Crypto::hmac('panel-domains:' . $actor);
        $pdo->prepare('INSERT INTO auth_login_limits (ip_hash) VALUES (?) ON DUPLICATE KEY UPDATE
            attempts = IF(window_started < UTC_TIMESTAMP(6) - INTERVAL 1 MINUTE, 1, LEAST(attempts + 1, 1000000)),
            window_started = IF(window_started < UTC_TIMESTAMP(6) - INTERVAL 1 MINUTE, UTC_TIMESTAMP(6), window_started)')->execute([$bucket]);
        $attempts = $pdo->prepare('SELECT attempts FROM auth_login_limits WHERE ip_hash = ?'); $attempts->execute([$bucket]);
        if ((int) $attempts->fetchColumn() > Config::int('API_RATE_LIMIT_PER_MINUTE', 120)) { throw new ApiException(429, 'rate_limited', 'Submission limit reached; the saved operation will resume shortly.'); }
    }

    public static function refresh(PDO $pdo, array $actor, ?string $batch = null): array
    {
        $where = "status IN ('prepared','queued','processing','pending','unknown')"; $args = [];
        if ((int) $actor['role'] !== 0) { $where .= ' AND reseller_id = ?'; $args[] = $actor['id']; }
        if ($batch !== null) { $where .= ' AND batch_id = ?'; $args[] = $batch; }
        // Bound a page request; saved plans beyond this window remain resumable.
        $rows = PanelData::rows($pdo, 'SELECT * FROM panel_domain_operations WHERE ' . $where . ' ORDER BY updated_at, id LIMIT 50', $args);
        foreach ($rows as $row) {
            self::locked($pdo, $row['reseller_id'], function () use ($pdo, $row, $actor) {
                // Read again after acquiring the lock; another request may already have advanced it.
                $current = PanelData::rows($pdo, 'SELECT * FROM panel_domain_operations WHERE id = ?', [$row['id']])[0];
                self::advance($pdo, self::decode($current), $actor);
            });
        }
        return $rows;
    }

    /** Resume submitted plans independently of an open browser, from the API queue timer. */
    public static function tick(PDO $pdo): void
    {
        $rows = PanelData::rows($pdo, "SELECT * FROM panel_domain_operations
            WHERE status IN ('prepared','queued','processing','pending','unknown') ORDER BY updated_at, id LIMIT 50");
        foreach ($rows as $row) {
            try {
                self::locked($pdo, $row['reseller_id'], function () use ($pdo, $row) {
                    $current = PanelData::rows($pdo, 'SELECT * FROM panel_domain_operations WHERE id = ?', [$row['id']])[0];
                    self::advance($pdo, self::decode($current), ['id' => $row['reseller_id'], 'role' => 1]);
                });
            } catch (ApiException $e) {
                if ($e->errorCode !== 'domain_busy') { throw $e; }
            }
        }
    }

    public static function operations(PDO $pdo, array $actor, ?string $batch = null, ?array $domain = null): array
    {
        $where = '1 = 1'; $args = [];
        if ((int) $actor['role'] !== 0) { $where .= ' AND reseller_id = ?'; $args[] = $actor['id']; }
        if ($batch !== null) { $where .= ' AND batch_id = ?'; $args[] = $batch; }
        if ($domain !== null) { $where .= ' AND reseller_id = ? AND registry_id = ? AND domainname = ?'; array_push($args, $domain['reseller_id'], $domain['registry_id'], $domain['object_name']); }
        return array_map([self::class, 'decode'], PanelData::rows($pdo, 'SELECT * FROM panel_domain_operations WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT 50', $args));
    }

    public static function info(array $operations): array
    {
        foreach ($operations as $op) {
            foreach (array_reverse($op['data']['steps']) as $step) {
                if ($step['command'] === 'domain.info' && $step['status'] === 'succeeded') {
                    $info = $step['result'] ?? [];
                    if (isset($info['name']) && strtolower($info['name']) !== $op['domainname']) { continue; }
                    return $info;
                }
            }
        }
        return [];
    }

    public static function listing(PDO $pdo, array $actor, int $page): array
    {
        $where = "o.object_type = 'domain'"; $args = [];
        if ((int) $actor['role'] !== 0) { $where .= ' AND o.reseller_id = ?'; $args[] = $actor['id']; }
        $offset = ($page - 1) * 50;
        $rows = PanelData::rows($pdo, "SELECT o.*, u.username, r.name AS registry FROM object_ownership o
            JOIN registries r ON r.id = o.registry_id JOIN resellers u ON u.id = o.reseller_id WHERE {$where}
            ORDER BY o.object_name, o.registry_id LIMIT 51 OFFSET {$offset}", $args);
        $next = count($rows) > 50; if ($next) { array_pop($rows); }
        return ['rows' => $rows, 'next' => $next];
    }

    public static function publicOperations(array $operations): array
    {
        return array_map(fn ($row) => ['id' => $row['id'], 'name' => $row['domainname'], 'status' => $row['status'],
            'message' => $row['data']['message'], 'steps' => array_map(fn ($s) => [
                'command' => $s['command'], 'status' => $s['status'], 'job_uuid' => $s['job_uuid'],
                'message' => $s['message'] ?? '', 'epp_code' => $s['epp_code'] ?? null], $row['data']['steps'])], $operations);
    }
}
