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

use Namingo\ResellerClient\ApiException;
use Namingo\ResellerClient\Client;
use Namingo\ResellerClient\CurlTransport;
use Namingo\ResellerClient\ProtocolException;
use Namingo\ResellerClient\TransportException;

if (!extension_loaded('curl') || !extension_loaded('openssl') || !function_exists('proc_open')) {
    fwrite(STDERR, "HTTPS tests require PHP cURL, OpenSSL and proc_open.\n"); exit(2);
}
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException('FAIL: ' . $message); } }
function fails(callable $callback, string $class): Throwable
{
    try { $callback(); } catch (Throwable $error) { check($error instanceof $class, get_class($error) . ': ' . $error->getMessage()); return $error; }
    throw new RuntimeException('Expected ' . $class);
}
$directory = sys_get_temp_dir() . '/namingo-https-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$process = null; $pipes = [];
try {
    $config = "[ req ]\ndistinguished_name = dn\n[ dn ]\n[ ca ]\nbasicConstraints = critical,CA:TRUE\nkeyUsage = critical,keyCertSign,cRLSign\n"
        . "[ server ]\nbasicConstraints = critical,CA:FALSE\nkeyUsage = digitalSignature,keyEncipherment\nextendedKeyUsage = serverAuth\nsubjectAltName = DNS:localhost\n";
    file_put_contents($directory . '/openssl.cnf', $config);
    $options = ['config' => $directory . '/openssl.cnf', 'digest_alg' => 'sha256'];
    $caKey = openssl_pkey_new(['private_key_bits' => 2048]);
    $caCsr = openssl_csr_new(['commonName' => 'Namingo ephemeral test CA'], $caKey, $options);
    $ca = openssl_csr_sign($caCsr, null, $caKey, 1, $options + ['x509_extensions' => 'ca']);
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, $options);
    $certificate = openssl_csr_sign($csr, $ca, $caKey, 1, $options + ['x509_extensions' => 'server']);
    check($ca !== false && $certificate !== false, 'Create ephemeral CA and server certificate');
    openssl_x509_export_to_file($ca, $directory . '/ca.pem');
    openssl_x509_export_to_file($certificate, $directory . '/server.pem');
    openssl_pkey_export_to_file($key, $directory . '/server.key');
    chmod($directory . '/server.key', 0600);
    // Use the normal PHP configuration for the fixture; it needs only streams/OpenSSL, not cURL.
    $process = proc_open([PHP_BINARY, __DIR__ . '/tls-server.php', $directory . '/server.pem', $directory . '/server.key'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'Start loopback fixture');
    stream_set_timeout($pipes[1], 5);
    $port = trim((string) fgets($pipes[1]));
    check(preg_match('/^[0-9]+$/D', $port) === 1, 'Read fixture port');
    putenv('NO_PROXY=localhost,127.0.0.1');
    $base = 'https://localhost:' . $port;
    $transport = new CurlTransport(1, $directory . '/ca.pem');
    $client = new Client($base, 'test', 'test-password', $transport, 2);
    check($client->account()['username'] === 'fixture', 'Real cURL login and authenticated request over verified TLS');
    check($client->request('GET', '/v1/account')->headers['x-request-id'] === 'tls-fixture', 'Response headers normalized');
    $error = fails(fn () => $client->prices(), ApiException::class);
    check($error->httpStatus === 402 && $error->apiCode === 'fixture_error', 'Real HTTP errors decoded');
    $error = fails(fn () => $client->domains(), ApiException::class);
    check($error->httpStatus === 302, 'Redirect is not followed with credentials');
    fails(fn () => $client->ledger(), ProtocolException::class);
    $untrusted = new Client($base, transport: new CurlTransport(1), timeoutSeconds: 2);
    $untrusted->setAccessToken('test-token');
    fails(fn () => $untrusted->account(), TransportException::class);
    $wrongHost = new Client('https://127.0.0.1:' . $port, transport: $transport, timeoutSeconds: 2);
    $wrongHost->setAccessToken('test-token');
    fails(fn () => $wrongHost->account(), TransportException::class);
    $short = new Client($base, transport: $transport, timeoutSeconds: .05);
    $short->setAccessToken('test-token');
    fails(fn () => $short->commands(), TransportException::class);
    echo "HTTPS integration passed: real authentication, response/errors, redirect refusal, CA/hostname verification and request timeout.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); }
    foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    if (is_resource($process)) { proc_close($process); }
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    rmdir($directory);
}
