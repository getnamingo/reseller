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
$countries = array_column((new League\ISO3166\ISO3166())->all(), 'name', 'alpha2');
asort($countries, SORT_NATURAL | SORT_FLAG_CASE);
$twig = Slim\Views\Twig::create(dirname(__DIR__) . '/panel/views', ['cache' => false, 'autoescape' => 'html'])->getEnvironment();
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
function selectCountry(string $value, bool $required = false): string {
    global $twig, $countries;
    return $twig->render('partials/country_select.twig', ['countries' => $countries, 'country_name' => 'country_code', 'country_value' => $value, 'country_required' => $required]);
}
$blank = selectCountry('');
check(str_contains($blank, '<option value="" selected>Select a country</option>') && !str_contains($blank, ' required'), 'Optional empty country stays blank');
check(substr_count($blank, '<option ') === count($countries) + 1, 'Every library country appears once, with an empty choice');
check(str_contains(selectCountry('bg'), '<option value="BG" selected>Bulgaria (BG)</option>'), 'Existing lowercase code selects its uppercase ISO value');
check(str_contains(selectCountry('DE', true), ' required') && str_contains(selectCountry('DE', true), '<option value="DE" selected>Germany (DE)</option>'), 'Required country keeps its saved choice');
check(str_contains(selectCountry('ZZ'), '<option value="ZZ" selected>ZZ (saved code)</option>'), 'An unlisted saved code is preserved during unrelated edits');
check(!str_contains(selectCountry('<script>'), '<script>'), 'Unlisted values remain escaped in option value and label');
echo "PASS: {$checks} country dropdown assertions\n";
