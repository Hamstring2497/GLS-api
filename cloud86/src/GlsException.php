<?php

declare(strict_types=1);

namespace App;

final class GlsException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $outcomeUnknown = false,
        public readonly int $httpStatus = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
