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

use Namingo\ResellerApi\ApiException;
use Namingo\ResellerApi\CommandCatalog;
use Namingo\ResellerApi\SelfService;
use Namingo\ResellerApi\Util;

require dirname(__DIR__) . '/vendor/autoload.php';
$dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true];
$adminDb = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$db = 'reseller_service_test_' . bin2hex(random_bytes(6));
$adminDb->exec("CREATE DATABASE `$db`");
$pdo = new PDO($dsn . ';dbname=' . $db, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', $options);
$count = 0;
function verify(bool $ok, string $message): void {
    global $count;
    if (!$ok) { throw new RuntimeException($message); }
    ++$count;
}
function listing(string $resource, array $query = [], ?string $owner = null): array {
    global $pdo, $a;
    return SelfService::listing($pdo, $owner ?? $a, $resource, $query);
}
function invalid(string $resource, array $query): void {
    try { listing($resource, $query); }
    catch (ApiException $e) { verify($e->httpStatus === 422, 'Invalid query returns 422'); return; }
    throw new RuntimeException('Expected invalid query');
}
try {
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $a = Util::uuid(); $b = Util::uuid(); $administrator = Util::uuid(); $registry = Util::uuid(); $otherRegistry = Util::uuid();
    foreach ([$a, $b, $administrator] as $i => $id) {
        $pdo->prepare("INSERT INTO resellers (id, username, password_hash, currency, role) VALUES (?, ?, 'unused', 'EUR', ?)")->execute([$id, 'user' . $i, $i === 2 ? 0 : 1]);
    }
    foreach ([$registry, $otherRegistry] as $i => $id) {
        $pdo->prepare("INSERT INTO registries (id, name, profile, config_nonce, config_cipher, enabled) VALUES (?, ?, 'generic', ?, 'SECRET_CONFIG', ?)")
            ->execute([$id, 'registry' . $i, str_repeat('n', 24), 1 - $i]);
        foreach ([$a, $b] as $owner) {
            $pdo->prepare('INSERT INTO reseller_registries (reseller_id, registry_id) VALUES (?, ?)')->execute([$owner, $id]);
        }
    }
    foreach (['com', 'net'] as $tld) {
        $pdo->prepare('INSERT INTO registry_tlds (registry_id, tld) VALUES (?, ?)')->execute([$registry, $tld]);
        $pdo->prepare('INSERT INTO reseller_tlds (reseller_id, registry_id, tld) VALUES (?, ?, ?)')->execute([$tld === 'com' ? $a : $b, $registry, $tld]);
    }
    foreach ([[CommandCatalog::GLOBAL_PRICE_UUID, 'com', 1, 12], [$a, 'com', 1, 9], [$b, 'com', 1, 999],
        [CommandCatalog::GLOBAL_PRICE_UUID, 'com', 2, 22], [CommandCatalog::GLOBAL_PRICE_UUID, 'net', 1, 7]] as [$owner, $tld, $period, $amount]) {
        $pdo->prepare("INSERT INTO registry_prices (registry_id, reseller_id, command, tld, period, amount, currency) VALUES (?, ?, 'domain.create', ?, ?, ?, 'EUR')")
            ->execute([$registry, $owner, $tld, $period, $amount]);
    }
    $pdo->prepare("INSERT INTO registry_prices (registry_id, command, amount, currency) VALUES (?, 'domain.check', 0, 'EUR')")->execute([$registry]);
    foreach ([[$a, 'domain', 'owned.com', 1], [$a, 'domain', 'pending.com', 0], [$b, 'domain', 'private.net', 1],
        [$a, 'contact', 'contact-a', 1], [$a, 'host', 'ns.owned.com', 1]] as [$owner, $type, $name, $active]) {
        $pdo->prepare('INSERT INTO object_ownership (registry_id, reseller_id, object_type, object_name, active, job_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$registry, $owner, $type, $name, $active, $active ? null : Util::uuid()]);
    }
    $ids = [];
    foreach ([$a => 55, $b => 3, $administrator => 1] as $owner => $total) {
        for ($i = 0; $i < $total; ++$i) {
            $id = Util::uuid();
            if ($owner === $a) { $ids[] = $id; }
            $pdo->prepare("INSERT INTO epp_jobs (id, reseller_id, registry_id, command, idempotency_key, request_hash, request_nonce, request_cipher, response_nonce, response_cipher, status, price, currency, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'SECRET_REQUEST', ?, 'SECRET_RESPONSE', ?, 3, 'EUR', '2026-01-01 12:00:00.000000')")
                ->execute([$id, $owner, $registry, $i % 2 ? 'domain.renew' : 'domain.check', $id, hash('sha256', $id, true), str_repeat('n', 24), str_repeat('r', 24), $i % 2 ? 'succeeded' : 'rejected']);
        }
    }
    $page1 = listing('jobs', ['limit' => '20']);
    $page2 = listing('jobs', ['limit' => '20', 'offset' => '20']);
    $page3 = listing('jobs', ['limit' => '20', 'offset' => '40']);
    verify(count($page1['data']) === 20 && $page1['pagination']['next_offset'] === 20, 'First page is bounded');
    verify(count($page2['data']) === 20 && count($page3['data']) === 15 && !$page3['pagination']['has_more'] && $page3['pagination']['next_offset'] === null, 'Complete historical pagination');
    $actual = array_column(array_merge($page1['data'], $page2['data'], $page3['data']), 'job_uuid');
    rsort($ids, SORT_STRING);
    verify($actual === $ids, 'Stable timestamp ties, no duplicates and no other reseller jobs');
    verify(listing('jobs')['pagination']['limit'] === 50, 'Default page size');
    verify(count(listing('jobs', ['limit' => 100])['data']) === 55, 'Maximum supported page size');
    verify(listing('jobs', ['offset' => 100])['data'] === [], 'Empty page beyond history');
    verify(count(listing('jobs', [], $administrator)['data']) === 1, 'Role 0 API is also scoped to its own jobs');
    $renewals = listing('jobs', ['command' => 'domain.renew', 'status' => 'succeeded', 'registry_uuid' => $registry]);
    verify(count($renewals['data']) === 27, 'Combined job filters');
    verify(listing('jobs', ['registry_uuid' => $otherRegistry])['data'] === [], 'Registry job filter');
    verify(listing('jobs', ['reseller_uuid' => $b])['data'] === listing('jobs')['data'], 'Query cannot override authenticated owner');
    $encoded = json_encode($page1);
    verify(!str_contains($encoded, 'SECRET') && !str_contains($encoded, 'cipher') && !str_contains($encoded, 'request_hash'), 'Job listing never returns or decrypts payloads or secrets');

    $backgroundId=$actual[count($actual)-1];
    $original=$pdo->prepare('SELECT command,idempotency_key FROM epp_jobs WHERE id=?'); $original->execute([$backgroundId]); $before=$original->fetch();
    $pdo->prepare("UPDATE epp_jobs SET command='domain.transfer',idempotency_key=? WHERE id=?")->execute(['transfer-discovery-'.$backgroundId,$backgroundId]);
    $requested1=listing('jobs',['source'=>'requested','limit'=>50]);
    $requested2=listing('jobs',['source'=>'requested','limit'=>50,'offset'=>50]);
    verify(count($requested1['data'])===50 && count($requested2['data'])===4 && !$requested2['pagination']['has_more'],'Source filtering is applied before pagination');
    verify(!in_array($backgroundId,array_column(array_merge($requested1['data'],$requested2['data']),'job_uuid'),true),'Automatic checks absent across requested pages');
    verify(count(listing('jobs',['source'=>'all','limit'=>100])['data'])===55 && count(listing('jobs',['source'=>'automatic'])['data'])===1,'All and automatic filters preserve history');
    try { listing('jobs',['source'=>'invalid']); throw new RuntimeException('Invalid source accepted'); }
    catch (ApiException $e) { verify($e->errorCode==='invalid_filter','Invalid source rejected'); }
    $pdo->prepare('UPDATE epp_jobs SET command=?,idempotency_key=? WHERE id=?')->execute([$before['command'],$before['idempotency_key'],$backgroundId]);

    $domains = listing('domains')['data'];
    verify(array_column($domains, 'domainname') === ['owned.com', 'pending.com'], 'Only own domains; contacts and hosts excluded');
    verify($domains[0]['active'] === true && $domains[1]['active'] === false, 'Active flags are JSON booleans');
    verify(count(listing('domains', ['active' => '1'])['data']) === 1, 'Active domain filter');
    verify(listing('domains', ['active' => '0'])['data'][0]['pending_job_uuid'] !== null, 'Pending reservations remain distinguishable');
    verify(listing('domains', ['name' => 'OWNED'])['data'][0]['domainname'] === 'owned.com', 'Case-insensitive literal domain search');
    verify(count(listing('domains', ['limit' => 1])['data']) === 1 && listing('domains', ['limit' => 1])['pagination']['has_more'], 'Domain pagination');
    verify(listing('domains', ['name' => 'private'])['data'] === [], 'Cannot search another reseller domain');

    $prices = listing('prices')['data'];
    verify(count($prices) === 2, 'Only permitted effective prices for supported commands');
    verify(is_int($prices[0]['period']) && $prices[0]['registry_uuid'] === $registry, 'API price types and registry UUID field');
    verify(!str_contains(json_encode($prices), '999.0000') && !str_contains(json_encode($prices), 'net'), 'Other reseller overrides and forbidden zones excluded');
    verify(listing('prices', ['command' => 'domain.create_dnssec'])['data'] === listing('prices', ['command' => 'domain.create'])['data'], 'DNSSEC API price filter returns shared registration prices');
    $filtered = listing('prices', ['command' => 'domain.create', 'tld' => '.COM', 'period' => '1', 'registry_uuid' => $registry]);
    verify(count($filtered['data']) === 1 && $filtered['data'][0]['amount'] === '9.0000', 'Exact zone/term and reseller override');
    verify(listing('prices', ['period' => '0'])['data'] === [], 'Ignored legacy operation prices are excluded');
    verify(count(listing('prices', ['limit' => 1, 'offset' => 1])['data']) === 1, 'Price pagination');
    verify(listing('prices', ['registry_uuid' => $otherRegistry])['data'] === [], 'Disabled registry prices excluded');
    $pdo->prepare('UPDATE reseller_registries SET enabled = 0 WHERE reseller_id = ? AND registry_id = ?')->execute([$a, $registry]);
    verify(listing('prices')['data'] === [] && count(listing('jobs')['data']) === 50 && count(listing('domains')['data']) === 2, 'Revoked access hides current prices while preserving own historical jobs and ownership');

    foreach ([['limit' => 0], ['limit' => 101], ['offset' => -1], ['offset' => 1000001], ['limit' => '1e2'], ['limit' => []],
        ['status' => 'invalid'], ['command' => 'invalid'], ['registry_uuid' => 'invalid']] as $query) { invalid('jobs', $query); }
    foreach ([['name' => '%'], ['name' => ['bad']], ['active' => 2], ['active' => false]] as $query) { invalid('domains', $query); }
    foreach ([['period' => 11], ['period' => []], ['command' => 'invalid'], ['tld' => 'bad%']] as $query) { invalid('prices', $query); }
    echo "PASS: {$count} self-service assertions\n";
} finally {
    $pdo = null;
    $adminDb->exec("DROP DATABASE `$db`");
}
