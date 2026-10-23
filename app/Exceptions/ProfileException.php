<?php

namespace App\Exceptions;

use RuntimeException;

class ProfileException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se pudo completar la operación del perfil. Inténtalo nuevamente.');
    }
}
