<?php

namespace App\Exceptions;

class MessagingException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se pudo completar la operación de mensajería.');
    }
}
