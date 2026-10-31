<?php

namespace App\Exceptions;

use RuntimeException;

class BusinessRule extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
