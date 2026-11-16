<?php

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
