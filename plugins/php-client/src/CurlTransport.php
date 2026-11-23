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

namespace Namingo\ResellerClient;

final class CurlTransport implements TransportInterface
{
    public function __construct(private readonly float $connectTimeoutSeconds = 10, private readonly ?string $caFile = null)
    {
        if (!is_finite($connectTimeoutSeconds) || $connectTimeoutSeconds <= 0) { throw new \InvalidArgumentException('Connect timeout must be positive.'); }
        if ($caFile !== null && !is_readable($caFile)) { throw new \InvalidArgumentException('CA file is not readable.'); }
    }

    public function send(string $method, string $url, #[\SensitiveParameter] array $headers,
        #[\SensitiveParameter] ?string $body, float $timeoutSeconds): HttpResponse
    {
        if (!function_exists('curl_init')) { throw new TransportException('Enable the PHP cURL extension or supply a TransportInterface implementation.'); }
        $handle = curl_init($url);
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) { $lines[] = $name . ': ' . $value; }
        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $lines,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) (min($this->connectTimeoutSeconds, $timeoutSeconds) * 1000)),
                CURLOPT_TIMEOUT_MS => max(1, (int) ($timeoutSeconds * 1000)),
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    if (str_starts_with($line, 'HTTP/')) { $responseHeaders = []; }
                    if (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                },
            ]);
            if ($this->caFile !== null) { curl_setopt($handle, CURLOPT_CAINFO, $this->caFile); }
            if ($body !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, $body); }
            $result = curl_exec($handle);
            if ($result === false) {
                throw new TransportException('Namingo HTTP request failed or timed out; reuse the saved key and payload when retrying a write.', curl_errno($handle));
            }
            return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $result);
        } finally { curl_close($handle); }
    }
}
