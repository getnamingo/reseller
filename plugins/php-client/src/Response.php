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

/** Complete API envelope, including pagination and request ID. */
final class Response
{
    public function __construct(public readonly int $httpStatus, #[\SensitiveParameter] public readonly array $body,
        public readonly array $headers = []) {}

    public function data(): array { return $this->body['data']; }
    public function pagination(): ?array { return $this->body['pagination'] ?? null; }
    public function requestId(): ?string { return $this->body['request_id'] ?? $this->headers['x-request-id'] ?? null; }
    public function __debugInfo(): array { return ['httpStatus' => $this->httpStatus, 'requestId' => $this->requestId(), 'data' => '[redacted]']; }
}
