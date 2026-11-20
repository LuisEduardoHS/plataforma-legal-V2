<?php

namespace App\Exceptions;

use RuntimeException;

class LexaUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'La generación con IA real aún no está habilitada.')
    {
        parent::__construct($message);
    }
}
