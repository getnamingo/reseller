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

final class Access
{
    public static function tld(string $value): string
    {
        $value = strtolower(ltrim($value, '.'));
        Ownership::name('example.' . $value, 'domain');
        if (strlen($value) > 247) {
            throw new ApiException(422, 'invalid_tld', 'TLD is too long.');
        }
        return $value;
    }

    public static function domains(PDO $pdo, string $registry, string $reseller, string $command, array $params): void
    {
        if (!str_starts_with($command, 'domain.')) {
            return;
        }
        $domains = $command === 'domain.check' ? ($params['domains'] ?? null) : [$params['domainname'] ?? null];
        if (!is_array($domains) || !array_is_list($domains) || count($domains) < 1 || count($domains) > 50) {
            throw new ApiException(422, 'invalid_domains', 'Supply between 1 and 50 domain names.');
        }
        foreach ($domains as $domain) {
            $name = Ownership::name($domain, 'domain');
            $labels = explode('.', $name);
            $zones = [];
            while (count($labels) > 1) {
                array_shift($labels);
                $zones[] = implode('.', $labels);
            }
            $stmt = $pdo->prepare('SELECT t.enabled, COALESCE(rt.enabled, 0) AS permitted
                FROM registry_tlds t LEFT JOIN reseller_tlds rt
                  ON rt.registry_id = t.registry_id AND rt.tld = t.tld AND rt.reseller_id = ?
                WHERE t.registry_id = ? AND t.tld IN (' . implode(',', array_fill(0, count($zones), '?')) . ')
                ORDER BY CHAR_LENGTH(t.tld) DESC LIMIT 1' . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
            $stmt->execute([$reseller, $registry, ...$zones]);
            $row = $stmt->fetch();
            if ($row === false || !(bool) $row['enabled'] || !(bool) $row['permitted']) {
                throw new ApiException(403, 'tld_not_permitted', 'The TLD is unavailable for this reseller.');
            }
        }
    }
}
