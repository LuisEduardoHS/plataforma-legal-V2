<?php

namespace App\Exceptions;

use RuntimeException;

class CommunityException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se pudo completar la operación de Comunidad.');
    }
}
