<?php

/*
 * Namingo Reseller Plugins
 *
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the Apache License, Version 2.0.
 * See the LICENSE-APACHE-2.0 file at the repository root
 * for the full license terms.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Namingo\ResellerClient\Client;

// php examples/check.php example.com example.net
foreach (['NAMINGO_API_URL', 'NAMINGO_USERNAME', 'NAMINGO_API_PASSWORD', 'NAMINGO_REGISTRY_UUID'] as $name) {
    if (!getenv($name)) { fwrite(STDERR, "Missing environment variable: {$name}\n"); exit(1); }
}
$domains = array_slice($argv, 1);
if ($domains === []) { fwrite(STDERR, "Pass ASCII/punycode domain names as arguments.\n"); exit(1); }
$client = new Client(getenv('NAMINGO_API_URL'), getenv('NAMINGO_USERNAME'), getenv('NAMINGO_API_PASSWORD'));
try {
    // All supplied names must belong to the selected registry and be permitted for this reseller.
    $job = $client->epp(getenv('NAMINGO_REGISTRY_UUID'), 'domain.check', ['domains' => $domains], respondAsync: true);
    $job = $client->waitForJob($job, 30);
    $result = Client::assertSucceeded($job);
    foreach ($result['domains'] ?? [] as $row) {
        echo $row['name'] . ': ' . (!empty($row['avail']) ? 'available' : 'unavailable') . PHP_EOL;
    }
} catch (\Namingo\ResellerClient\ApiException $error) {
    fwrite(STDERR, 'API error: HTTP ' . $error->httpStatus . ', ' . $error->apiCode . PHP_EOL); exit(1);
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1);
}
