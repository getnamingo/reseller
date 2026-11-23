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

// Local fixture only. No external API calls or persistent application data.
$context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'local_pk' => $argv[2]]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) { fwrite(STDERR, $message); exit(1); }
$address = stream_socket_get_name($server, false);
echo substr(strrchr($address, ':'), 1) . "\n"; flush();
while ($connection = @stream_socket_accept($server, 10)) {
    stream_set_timeout($connection, 2);
    if (!@stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) { fclose($connection); continue; }
    $line = fgets($connection);
    if ($line === false) { fclose($connection); continue; }
    [, $path] = explode(' ', $line, 3);
    $path = parse_url($path, PHP_URL_PATH);
    $headers = [];
    while (($line = fgets($connection)) !== false && trim($line) !== '') {
        if (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $headers[strtolower(trim($name))] = trim($value); }
    }
    $body = '';
    $length = (int) ($headers['content-length'] ?? 0);
    while (strlen($body) < $length && !feof($connection)) {
        $chunk = fread($connection, $length - strlen($body));
        if ($chunk === false || $chunk === '') { break; }
        $body .= $chunk;
    }
    $status = '200 OK'; $extra = ''; $data = ['data' => ['username' => 'fixture']];
    if ($path === '/v1/login') {
        $input = json_decode($body, true);
        if (($input['username'] ?? '') !== 'test' || ($input['password'] ?? '') !== 'test-password') {
            $status = '401 Unauthorized'; $data = ['error' => ['code' => 'invalid_credentials']];
        } else { $data = ['data' => ['access_token' => 'test-token', 'expires_in' => 3600]]; }
    } elseif (($headers['authorization'] ?? '') !== 'Bearer test-token') {
        $status = '401 Unauthorized'; $data = ['error' => ['code' => 'invalid_token']];
    } elseif ($path === '/v1/prices') {
        $status = '402 Payment Required'; $data = ['error' => ['code' => 'fixture_error', 'message' => 'Fixture error']];
    } elseif ($path === '/v1/domains') {
        $status = '302 Found'; $extra = "Location: /v1/account\r\n";
    } elseif ($path === '/v1/ledger') {
        $data = null;
    } elseif ($path === '/v1/commands') {
        usleep(200000); // Verify caller request timeout without a long test delay.
    }
    $json = $data === null ? 'not JSON' : json_encode($data + ['request_id' => 'tls-fixture']);
    @fwrite($connection, "HTTP/1.1 {$status}\r\nContent-Type: application/json\r\nX-Request-ID: tls-fixture\r\n"
        . $extra . 'Content-Length: ' . strlen($json) . "\r\nConnection: close\r\n\r\n" . $json);
    fclose($connection);
}
fclose($server);
