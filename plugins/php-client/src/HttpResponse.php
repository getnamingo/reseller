<?php

declare(strict_types=1);

namespace Namingo\ResellerClient;

final class HttpResponse
{
    /** @param array<string, string> $headers Lowercase header names. */
    public function __construct(public readonly int $status, public readonly array $headers,
        #[\SensitiveParameter] public readonly string $body) {}

    public function __debugInfo(): array { return ['status' => $this->status, 'body' => '[redacted]']; }
}
