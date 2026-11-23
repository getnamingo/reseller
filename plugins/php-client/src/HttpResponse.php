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

final class HttpResponse
{
    /** @param array<string, string> $headers Lowercase header names. */
    public function __construct(public readonly int $status, public readonly array $headers,
        #[\SensitiveParameter] public readonly string $body) {}

    public function __debugInfo(): array { return ['status' => $this->status, 'body' => '[redacted]']; }
}
