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

/** Raised only when the caller explicitly asks assertSucceeded() to require completion. */
final class JobException extends \RuntimeException
{
    public function __construct(public readonly string $jobId, public readonly string $status, public readonly ?int $eppCode)
    {
        parent::__construct('Namingo job ' . $jobId . ' is ' . $status . ' (EPP ' . ($eppCode ?? 'n/a') . ').');
    }
}
