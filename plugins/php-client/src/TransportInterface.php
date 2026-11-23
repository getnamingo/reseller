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

interface TransportInterface
{
    /**
     * @param array<string, string> $headers Never log Authorization or the request body.
     * @throws TransportException On connection/timeout failures; do not automatically retry writes.
     */
    public function send(string $method, string $url, #[\SensitiveParameter] array $headers,
        #[\SensitiveParameter] ?string $body, float $timeoutSeconds): HttpResponse;
}
