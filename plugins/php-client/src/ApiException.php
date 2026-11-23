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

/** Non-2xx HTTP response. Registry job rejection is represented separately by job.status. */
final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $httpStatus, public readonly string $apiCode,
        string $message, #[\SensitiveParameter] public readonly array $details = [],
        public readonly ?string $requestId = null, public readonly array $headers = [])
    {
        parent::__construct($message, $httpStatus);
    }
}
