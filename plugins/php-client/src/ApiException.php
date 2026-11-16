<?php

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
