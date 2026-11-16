<?php

declare(strict_types=1);

namespace Namingo\ResellerApi;

use PDO;

final class PanelData
{
    public static function rows(PDO $pdo, string $sql, array $params = []): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function access(PDO $pdo, string $reseller): array
    {
        return self::rows($pdo, 'SELECT r.id AS registry_id, r.name AS registry, r.profile,
            r.enabled AS registry_enabled, rr.enabled AS access_enabled, t.tld,
            COALESCE(t.enabled, 0) AS tld_enabled, COALESCE(rt.enabled, 0) AS permitted
            FROM reseller_registries rr JOIN registries r ON r.id = rr.registry_id
            LEFT JOIN registry_tlds t ON t.registry_id = r.id
            LEFT JOIN reseller_tlds rt ON rt.registry_id = r.id AND rt.reseller_id = rr.reseller_id AND rt.tld = t.tld
            WHERE rr.reseller_id = ? ORDER BY r.name, t.tld', [$reseller]);
    }

    public static function checkRoutes(PDO $pdo, string $reseller, array $domains): array
    {
        $access = self::access($pdo, $reseller);
        $rows = [];
        foreach ($domains as $domain) {
            $row = ['name' => $domain, 'status' => 'Not supported', 'reason' => 'TLD is not supported or not permitted.'];
            try {
                $row['name'] = Ownership::name($domain, 'domain');
            } catch (ApiException $e) {
                $rows[] = array_replace($row, ['status' => 'Invalid domain', 'reason' => $e->getMessage()]);
                continue;
            }
            $matches = [];
            foreach ($access as $item) {
                if ($item['registry_enabled'] && $item['access_enabled'] && $item['tld'] !== null
                    && str_ends_with($row['name'], '.' . $item['tld'])
                    && strlen($item['tld']) > strlen($matches[$item['registry_id']]['tld'] ?? '')) {
                    // Keep each registry's most specific zone, even if its grant is disabled.
                    $matches[$item['registry_id']] = $item;
                }
            }
            $matches = array_filter($matches, fn ($item) => $item['tld_enabled'] && $item['permitted']);
            if ($matches !== []) {
                $longest = max(array_map(fn ($item) => strlen($item['tld']), $matches));
                $matches = array_filter($matches, fn ($item) => strlen($item['tld']) === $longest);
                if (count($matches) === 1) {
                    $match = reset($matches);
                    $row += ['registry_id' => $match['registry_id'], 'registry' => $match['registry']];
                    $row['status'] = 'Queued';
                    $row['reason'] = '';
                } else {
                    $row['status'] = 'Ambiguous TLD';
                    $row['reason'] = 'More than one permitted registry serves this TLD. Ask an administrator to resolve the overlap.';
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public static function adminPriceGroups(PDO $pdo, int $limit, int $offset): array
    {
        $groups = self::rows($pdo, "SELECT p.registry_id, p.reseller_id AS scope_id, p.tld, p.currency,
            r.name AS registry_name, COALESCE(u.username, 'Global defaults') AS scope_name,
            CASE WHEN p.reseller_id = ? THEN 'global' ELSE p.reseller_id END AS scope, COUNT(*) AS price_count
            FROM registry_prices p JOIN registries r ON r.id = p.registry_id LEFT JOIN resellers u ON u.id = p.reseller_id
            GROUP BY p.registry_id, p.reseller_id, p.tld, p.currency, r.name, u.username
            ORDER BY r.name, p.registry_id, p.tld, p.reseller_id, p.currency LIMIT " . $limit . ' OFFSET ' . $offset,
            [CommandCatalog::GLOBAL_PRICE_UUID]);
        if ($groups === []) { return []; }
        $where = []; $params = [];
        foreach ($groups as $group) {
            $where[] = '(registry_id = ? AND reseller_id = ? AND tld = ? AND currency = ?)';
            array_push($params, $group['registry_id'], $group['scope_id'], $group['tld'], $group['currency']);
        }
        $entries = self::rows($pdo, 'SELECT registry_id, reseller_id, tld, currency, command, period, amount FROM registry_prices WHERE '
            . implode(' OR ', $where) . ' ORDER BY command, period', $params);
        $byGroup = [];
        foreach ($entries as $entry) {
            $key = implode(':', [$entry['registry_id'], $entry['reseller_id'], $entry['tld'], $entry['currency']]);
            $byGroup[$key][] = array_intersect_key($entry, array_flip(['command', 'period', 'amount']));
        }
        foreach ($groups as &$group) {
            $key = implode(':', [$group['registry_id'], $group['scope_id'], $group['tld'], $group['currency']]);
            $rows = $byGroup[$key] ?? [];
            $group['version'] = hash('sha256', Util::canonicalJson($rows));
            $group['prices'] = [];
            foreach ($rows as $row) {
                if (CommandCatalog::canSetPrice($row['command'], $group['tld'], (int) $row['period'])) {
                    $group['prices'][$row['command']][$row['period']] = $row['amount'];
                }
            }
            $group['editable'] = $group['prices'] !== [];
        }
        unset($group);
        return $groups;
    }

    public static function prices(PDO $pdo, string $reseller, array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        $sql = "SELECT r.name AS registry, p.registry_id, p.command, p.tld, p.period,
            p.amount, p.currency, CASE WHEN p.reseller_id = ? THEN 'Reseller override' ELSE 'Global' END AS scope
            FROM registry_prices p JOIN registries r ON r.id = p.registry_id
            JOIN reseller_registries rr ON rr.registry_id = p.registry_id AND rr.reseller_id = ?
            WHERE rr.enabled = 1 AND r.enabled = 1 AND p.reseller_id IN (?, ?)
              AND p.command IN ('domain.create', 'domain.renew', 'domain.restore', 'domain.transfer')
              AND (p.command <> 'domain.transfer' OR p.period > 0)
              AND (p.tld = '' OR EXISTS (
                SELECT 1 FROM reseller_tlds rt JOIN registry_tlds t ON t.registry_id = rt.registry_id AND t.tld = rt.tld
                WHERE rt.registry_id = p.registry_id AND rt.reseller_id = ? AND rt.enabled = 1 AND t.enabled = 1
                  AND (rt.tld = p.tld OR RIGHT(rt.tld, CHAR_LENGTH(p.tld) + 1) = CONCAT('.', p.tld))))
              AND NOT EXISTS (SELECT 1 FROM registry_prices o WHERE p.reseller_id = ? AND o.reseller_id = ?
                AND o.registry_id = p.registry_id AND o.command = p.command AND o.tld = p.tld AND o.period = p.period)";
        $params = [$reseller, $reseller, $reseller, CommandCatalog::GLOBAL_PRICE_UUID, $reseller, CommandCatalog::GLOBAL_PRICE_UUID, $reseller];
        foreach (['registry_uuid' => 'registry_id', 'command' => 'command', 'tld' => 'tld', 'period' => 'period'] as $key => $column) {
            if (array_key_exists($key, $filters)) {
                $sql .= " AND p.{$column} = ?";
                $params[] = $filters[$key];
            }
        }
        $sql .= ' ORDER BY r.name, p.registry_id, p.tld, p.command, p.period';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        return self::rows($pdo, $sql, $params);
    }
}
