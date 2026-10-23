<?php

namespace App\Exceptions;

use RuntimeException;

class AttachmentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se pudo completar la operación del archivo. Inténtalo nuevamente.');
    }
}
