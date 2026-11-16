<?php

namespace App\Exceptions;

class PasswordRecoveryUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('La recuperación no está disponible temporalmente.');
    }
}
