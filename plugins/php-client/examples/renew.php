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

// This is a paid operation. The caller must save this exact key/payload before invoking the script.
foreach (['NAMINGO_API_URL', 'NAMINGO_USERNAME', 'NAMINGO_API_PASSWORD', 'NAMINGO_REGISTRY_UUID', 'NAMINGO_DOMAIN', 'NAMINGO_REQUEST_KEY'] as $name) {
    if (!getenv($name)) { fwrite(STDERR, "Missing environment variable: {$name}\n"); exit(1); }
}
$years = filter_var(getenv('NAMINGO_YEARS') ?: '1', FILTER_VALIDATE_INT);
if ($years === false || $years < 1 || $years > 10) { fwrite(STDERR, "NAMINGO_YEARS must be 1–10.\n"); exit(1); }
$client = new Client(getenv('NAMINGO_API_URL'), getenv('NAMINGO_USERNAME'), getenv('NAMINGO_API_PASSWORD'));
try {
    $job = $client->epp(getenv('NAMINGO_REGISTRY_UUID'), 'domain.renew',
        ['domainname' => getenv('NAMINGO_DOMAIN'), 'regperiod' => $years], getenv('NAMINGO_REQUEST_KEY'));
    // Record this job UUID in the app. Future status checks can call job()/waitForJob() directly.
    echo 'Job: ' . $job['job_uuid'] . ', status: ' . $job['status'] . PHP_EOL;
    $job = $client->waitForJob($job, 15);
    Client::assertSucceeded($job);
    echo "Renewal completed.\n";
} catch (\Namingo\ResellerClient\ApiException $error) {
    fwrite(STDERR, 'API error: HTTP ' . $error->httpStatus . ', ' . $error->apiCode . PHP_EOL); exit(1);
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1);
}
