<?php

declare(strict_types=1);

namespace Namingo\ResellerClient;

/** Invalid/unexpected response; a submitted write may still have been accepted. */
final class ProtocolException extends \RuntimeException {}
