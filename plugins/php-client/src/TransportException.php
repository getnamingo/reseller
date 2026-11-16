<?php

declare(strict_types=1);

namespace Namingo\ResellerClient;

/** The server may have accepted a write. Reuse its saved key and exact payload. */
final class TransportException extends \RuntimeException {}
