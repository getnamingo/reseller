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

    public static function prices(PDO $pdo, string $reseller): array
    {
        return self::rows($pdo, "SELECT r.name AS registry, p.registry_id, p.command, p.tld, p.period,
            p.amount, p.currency, CASE WHEN p.reseller_id = ? THEN 'Reseller override' ELSE 'Global' END AS scope
            FROM registry_prices p JOIN registries r ON r.id = p.registry_id
            JOIN reseller_registries rr ON rr.registry_id = p.registry_id AND rr.reseller_id = ?
            WHERE rr.enabled = 1 AND r.enabled = 1 AND p.reseller_id IN (?, ?)
              AND (p.tld = '' OR EXISTS (
                SELECT 1 FROM reseller_tlds rt JOIN registry_tlds t ON t.registry_id = rt.registry_id AND t.tld = rt.tld
                WHERE rt.registry_id = p.registry_id AND rt.reseller_id = ? AND rt.enabled = 1 AND t.enabled = 1
                  AND (rt.tld = p.tld OR RIGHT(rt.tld, CHAR_LENGTH(p.tld) + 1) = CONCAT('.', p.tld))))
              AND NOT EXISTS (SELECT 1 FROM registry_prices o WHERE p.reseller_id = ? AND o.reseller_id = ?
                AND o.registry_id = p.registry_id AND o.command = p.command AND o.tld = p.tld AND o.period = p.period)
            ORDER BY r.name, p.tld, p.command, p.period",
            [$reseller, $reseller, $reseller, CommandCatalog::GLOBAL_PRICE_UUID, $reseller, CommandCatalog::GLOBAL_PRICE_UUID, $reseller]);
    }
}
