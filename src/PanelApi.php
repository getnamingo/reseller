<?php

/*
 * Namingo Reseller
 *
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the PolyForm Noncommercial License 1.0.0.
 * Commercial use requires separate written authorization.
 * For licensing inquiries, contact help@namingo.org.
 *
 * See the LICENSE file distributed with this software
 * for the full license terms.
 *
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

declare(strict_types=1);

namespace Namingo\ResellerApi;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

final class PanelApi
{
    public function __construct(private readonly ClientInterface $http)
    {
    }

    public static function create(): self
    {
        $base = rtrim(Config::required('PANEL_API_URL'), '/') . '/';
        $url = parse_url($base);
        if ($url === false || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || (($url['scheme'] ?? '') !== 'https' && !in_array($url['host'] ?? '', ['localhost', '127.0.0.1', '::1', '[::1]'], true))) {
            throw new ApiException(503, 'invalid_api_url', 'Configure an HTTPS or loopback panel API URL.');
        }
        return new self(new Client(['base_uri' => $base, 'verify' => Config::get('PANEL_API_CA') ?? true,
            'allow_redirects' => false, 'http_errors' => false, 'connect_timeout' => 5, 'timeout' => 40]));
    }

    public function check(string $token, string $registry, array $domains, string $key, bool $async = true): array
    {
        return $this->call('POST', 'v1/epp/domain.check', $token, ['headers' => ['Idempotency-Key' => $key] + ($async ? ['Prefer' => 'respond-async'] : []),
            'json' => ['registry_uuid' => $registry, 'params' => ['domains' => $domains]]]);
    }

    public function job(string $token, string $id): array
    {
        if (!Util::isUuid($id)) {
            throw new ApiException(404, 'job_not_found', 'Check was not found.');
        }
        $job = $this->call('GET', 'v1/jobs/' . $id, $token);
        if (($job['command'] ?? '') !== 'domain.check') {
            throw new ApiException(403, 'check_only', 'The panel displays availability check results only.');
        }
        return $job;
    }

    public function detail(string $token, string $id): array
    {
        if (!Util::isUuid($id)) { throw new ApiException(404, 'job_not_found', 'Job was not found.'); }
        return $this->call('GET', 'v1/jobs/' . $id, $token);
    }

    public function deposit(string $token, string $amount, string $currency, string $reference): array
    {
        return $this->call('POST', 'v1/deposits', $token, ['json' => [
            'amount' => $amount, 'currency' => $currency, 'reference' => $reference]]);
    }

    public function schedule(string $token, string $id): array
    {
        if (!Util::isUuid($id)) { throw new ApiException(404, 'job_not_found', 'Job was not found.'); }
        return $this->call('POST', 'v1/jobs/' . $id . '/reconcile', $token);
    }

    public function lifecycle(string $token, string $registry, string $command, array $params, string $key): array
    {
        if (!in_array($command, ['domain.restore', 'domain.restore_report', 'domain.transfer'], true)
            || ($command === 'domain.transfer' && !in_array($params['op'] ?? '', ['query', 'cancel'], true))) {
            throw new ApiException(403, 'unsupported_panel_action', 'This lifecycle action is not exposed in the panel.');
        }
        return $this->call('POST', 'v1/epp/' . $command, $token, ['headers' => ['Idempotency-Key' => $key],
            'json' => ['registry_uuid' => $registry, 'params' => $params]]);
    }

    private function call(string $method, string $path, string $token, array $options = []): array
    {
        $options['http_errors'] = false;
        $options['allow_redirects'] = false;
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        try {
            $response = $this->http->request($method, $path, $options);
            $raw = $response->getBody()->read(1_048_577);
            if (strlen($raw) > 1_048_576) {
                throw new \RuntimeException('Oversized API response.');
            }
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ApiException(502, 'api_unavailable', 'The API is unavailable. Check job or deposit history before retrying with the same reference.');
        }
        if ($response->getStatusCode() >= 400) {
            throw new ApiException($response->getStatusCode(), 'api_error',
                is_string($body['error']['message'] ?? null) ? $body['error']['message'] : 'The API rejected this request.');
        }
        if ($response->getStatusCode() >= 300 || !is_array($body['data'] ?? null)) {
            throw new ApiException(502, 'invalid_api_response', 'The API returned an unexpected response.');
        }
        return $body['data'];
    }
}
