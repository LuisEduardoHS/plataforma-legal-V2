<?php

namespace App\Exceptions;

class ContentLibraryException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se pudo completar la operación de contenido.');
    }
}
