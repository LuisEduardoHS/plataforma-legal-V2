<?php

namespace App\Exceptions;

use RuntimeException;

class LexaConflictException extends RuntimeException
{
    public function __construct(string $message = 'La conversación cambió o ya tiene una generación en curso.')
    {
        parent::__construct($message);
    }
}
