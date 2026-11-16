<?php

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
