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

final class SelfService
{
    public static function listing(PDO $pdo, string $reseller, string $resource, array $query = []): array
    {
        $limit = self::integer($query, 'limit', 50, 1, 100);
        $offset = self::integer($query, 'offset', 0, 0, 1000000);
        $registry = self::text($query, 'registry_uuid');
        if ($registry !== '' && !Util::isUuid($registry)) {
            throw new ApiException(422, 'invalid_filter', 'registry_uuid must be a valid UUID.');
        }
        $filters = $registry === '' ? [] : ['registry_uuid' => strtolower($registry)];
        $params = [$reseller];
        if ($resource === 'jobs') {
            $discovery = Transfers::DISCOVERY_SQL;
            $source = self::text($query, 'source');
            if (!in_array($source, ['', 'all', 'requested', 'automatic'], true)) {
                throw new ApiException(422, 'invalid_filter', 'Invalid job source filter.');
            }
            $sql = "SELECT CASE WHEN {$discovery} THEN 'automatic' ELSE 'requested' END AS source, j.id AS job_uuid, j.registry_id AS registry_uuid, r.name AS registry_name,
                j.command, j.status, j.parent_job_id AS parent_job_uuid, j.reconcile_at, j.last_error, j.price, j.currency, j.epp_code, j.created_at, j.started_at, j.finished_at
                FROM epp_jobs j JOIN registries r ON r.id = j.registry_id WHERE j.reseller_id = ?";
            if ($source === 'requested') { $sql .= " AND NOT {$discovery}"; }
            elseif ($source === 'automatic') { $sql .= " AND {$discovery}"; }
            foreach (['status' => ['queued', 'processing', 'succeeded', 'rejected', 'unknown', 'pending'],
                'command' => array_column(CommandCatalog::all(), 'command')] as $key => $allowed) {
                $value = self::text($query, $key);
                if ($value !== '') {
                    if (!in_array($value, $allowed, true)) {
                        throw new ApiException(422, 'invalid_filter', "Invalid {$key} filter.");
                    }
                    $sql .= " AND j.{$key} = ?";
                    $params[] = $value;
                }
            }
            if ($registry !== '') {
                $sql .= ' AND j.registry_id = ?';
                $params[] = $filters['registry_uuid'];
            }
            $order = 'j.created_at DESC, j.id DESC';
        } elseif ($resource === 'domains') {
            $sql = "SELECT o.object_name AS domainname, o.registry_id AS registry_uuid, r.name AS registry_name,
                o.active, o.domain_state, o.job_id AS pending_job_uuid FROM object_ownership o JOIN registries r ON r.id = o.registry_id
                WHERE o.reseller_id = ? AND o.object_type = 'domain'";
            if ($registry !== '') {
                $sql .= ' AND o.registry_id = ?';
                $params[] = $filters['registry_uuid'];
            }
            $name = self::text($query, 'name');
            if ($name !== '') {
                if (strlen($name) > 253 || preg_match('/^[a-z0-9.-]+$/iD', $name) !== 1) {
                    throw new ApiException(422, 'invalid_filter', 'name must be an ASCII domain name or fragment.');
                }
                $sql .= ' AND o.object_name LIKE ?';
                $params[] = '%' . strtolower($name) . '%';
            }
            if (self::text($query, 'active') !== '') {
                $sql .= ' AND o.active = ?';
                $params[] = self::integer($query, 'active', 1, 0, 1);
            }
            $order = 'o.object_name, o.registry_id';
        } elseif ($resource === 'prices') {
            $command = self::text($query, 'command');
            if ($command !== '') {
                if (!in_array($command, array_column(CommandCatalog::all(), 'command'), true)) {
                    throw new ApiException(422, 'invalid_filter', 'Invalid command filter.');
                }
                $filters['command'] = $command === 'domain.create_dnssec' ? 'domain.create' : $command;
            }
            $tld = self::text($query, 'tld');
            if ($tld !== '') {
                $filters['tld'] = Access::tld($tld);
            }
            if (self::text($query, 'period') !== '') {
                $filters['period'] = self::integer($query, 'period', 0, 0, 10);
            }
            $rows = PanelData::prices($pdo, $reseller, $filters, $limit + 1, $offset);
        } else {
            throw new ApiException(404, 'not_found', 'Listing was not found.');
        }
        if ($resource !== 'prices') {
            $rows = PanelData::rows($pdo, $sql . ' ORDER BY ' . $order . ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset, $params);
        }
        foreach ($rows as &$row) {
            if ($resource === 'prices') {
                $row['registry_uuid'] = $row['registry_id'];
                $row['registry_name'] = $row['registry'];
                $row['period'] = (int) $row['period'];
                unset($row['registry_id'], $row['registry']);
            } elseif ($resource === 'domains') {
                $row['active'] = (bool) $row['active'];
            } else {
                $row['epp_code'] = $row['epp_code'] === null ? null : (int) $row['epp_code'];
            }
        }
        unset($row);
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        return ['data' => $rows, 'pagination' => ['limit' => $limit, 'offset' => $offset,
            'has_more' => $more, 'next_offset' => $more ? $offset + $limit : null]];
    }

    private static function text(array $query, string $key): string
    {
        $value = $query[$key] ?? '';
        if (!is_string($value) && !is_int($value)) {
            throw new ApiException(422, 'invalid_filter', "{$key} must be a scalar value.");
        }
        return (string) $value;
    }

    private static function integer(array $query, string $key, int $default, int $min, int $max): int
    {
        $value = self::text($query, $key);
        if ($value === '') {
            return $default;
        }
        if (strlen($value) > 7 || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            throw new ApiException(422, 'invalid_filter', "{$key} must be an integer from {$min} to {$max}.");
        }
        return (int) $value;
    }
}
