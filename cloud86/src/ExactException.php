<?php

declare(strict_types=1);

namespace App;

final class ExactException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}

