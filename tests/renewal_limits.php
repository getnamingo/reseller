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

require dirname(__DIR__) . '/vendor/autoload.php';
use Namingo\ResellerApi\Domains;
$now = new DateTimeImmutable('2026-11-17T12:00:00Z');
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
check(Domains::renewalLimit('2030-11-17T12:00:00Z', $now) === 6, 'Six years fit exactly at the ten-year horizon');
check(Domains::renewalLimit('2030-11-18T12:00:00Z', $now) === 5, 'Full date matters, not only the expiry year');
check(Domains::renewalLimit('2036-11-17T12:00:00Z', $now) === 0, 'At the cap no whole-year renewal is possible');
check(Domains::renewalLimit('2037-01-01', $now) === 0, 'Already beyond the cap does not create negative choices');
check(Domains::renewalLimit('2035-11-17T12:00:00Z', $now) === 1, 'One remaining year is offered');
check(Domains::renewalLimit('2025-01-01', $now) === 10, 'An expired domain never offers more than ten years');
check(Domains::renewalLimit('2028-02-29T12:00:00Z', $now) === 8, 'Leap-day expiry uses calendar years');
check(Domains::renewalLimit('2028-01-01', $now, 'PT') === 1, 'Implicit one-year profile remains capped at one');
foreach ([null, '', 'tomorrow', '2030-02-30', 'not a date'] as $invalid) { check(Domains::renewalLimit($invalid, $now) === null, 'Unknown or invalid expiry requires sync'); }
$twig = Slim\Views\Twig::create(dirname(__DIR__) . '/panel/views', ['cache' => false, 'autoescape' => 'html'])->getEnvironment();
foreach ([6, 0, null] as $limit) {
    $html = $twig->render('domain.twig', ['renewal_limit' => $limit, 'domain' => ['object_name' => 'example.com'], 'info' => [], 'contact_ids' => [], 'hosts' => []]);
    preg_match('/id="renew"(.*?)id="delete"/s', $html, $match);
    $renew = $match[1];
    if ($limit === 6) { check(substr_count($renew, '<option>') === 6 && !str_contains($renew, '<option>7</option>'), 'Dropdown renders only available terms'); }
    elseif ($limit === 0) { check(!str_contains($renew, 'name="years"') && !str_contains($renew, 'Renew at configured price'), 'No renewal form at cap'); }
    else { check(str_contains($renew, 'Sync the domain') && !str_contains($renew, 'name="years"'), 'Unknown expiry asks for sync'); }
}
echo "PASS: {$checks} renewal limit assertions\n";
