<?php

namespace App\Policies;

class LegalResourcePolicy extends EditorialPolicy
{
    protected function prefix(): string
    {
        return 'resources';
    }
}
