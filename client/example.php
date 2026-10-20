#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

require dirname(__DIR__) . '/vendor/autoload.php';

$baseUrl = rtrim(getenv('API_BASE_URL') ?: 'https://api.example.test', '/') . '/';
$username = getenv('API_USERNAME') ?: '';
$password = getenv('API_PASSWORD') ?: '';
$registryId = getenv('API_REGISTRY_UUID') ?: '';
$caBundle = getenv('API_CA_BUNDLE') ?: true;
$command = $argv[1] ?? 'domain.check';
$paramsJson = $argv[2] ?? '{"domains":["example.test"]}';

$urlScheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
$urlHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
if ($urlScheme !== 'https' && !in_array($urlHost, ['127.0.0.1', '::1', '[::1]', 'localhost'], true)) {
    throw new RuntimeException('API_BASE_URL must use HTTPS except for a loopback development server.');
}

if ($username === '' || $password === '') {
    fwrite(STDERR, "Set API_USERNAME and API_PASSWORD.\n");
    exit(1);
}

$params = json_decode($paramsJson, true, 32, JSON_THROW_ON_ERROR);
if (!is_array($params)) {
    throw new RuntimeException('The second argument must be a JSON params object.');
}

$http = new Client([
    'base_uri' => $baseUrl,
    'verify' => $caBundle,
    'connect_timeout' => 10,
    'timeout' => 40,
    'http_errors' => false,
    'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => 'Namingo-Reseller-API-Guzzle-Example/1.0',
    ],
]);

$call = static function (string $method, string $uri, array $options = []) use ($http): array {
    try {
        $response = $http->request($method, $uri, $options);
    } catch (GuzzleException $e) {
        throw new RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
    }
    $body = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
    if ($response->getStatusCode() >= 400) {
        $message = $body['error']['message'] ?? 'API request failed.';
        throw new RuntimeException($response->getStatusCode() . ': ' . $message);
    }
    return [$response->getStatusCode(), $body];
};

[, $login] = $call('POST', 'v1/login', [
    'json' => ['username' => $username, 'password' => $password],
]);
$token = $login['data']['access_token'];
$auth = ['Authorization' => 'Bearer ' . $token];

if ($registryId === '') {
    [, $registries] = $call('GET', 'v1/registries', ['headers' => $auth]);
    $registryId = $registries['data'][0]['registry_uuid'] ?? '';
    if ($registryId === '') {
        throw new RuntimeException('The reseller has no enabled registry.');
    }
}

$headers = $auth;
if (!in_array($command, ['contact.check', 'contact.info', 'domain.check', 'domain.info', 'hello', 'host.check', 'host.info'], true)) {
    $headers['Idempotency-Key'] = 'guzzle-' . bin2hex(random_bytes(16));
}

[$status, $reply] = $call('POST', 'v1/epp/' . rawurlencode($command), [
    'headers' => $headers,
    'json' => ['registry_uuid' => $registryId, 'params' => $params],
]);

$job = $reply['data'];
for ($attempt = 0; in_array($job['status'], ['queued', 'processing'], true) && $attempt < 30; $attempt++) {
    usleep(1_000_000);
    [$status, $reply] = $call('GET', 'v1/jobs/' . $job['job_uuid'], ['headers' => $auth]);
    $job = $reply['data'];
}

echo json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

$call('POST', 'v1/logout', ['headers' => $auth, 'json' => new stdClass()]);
