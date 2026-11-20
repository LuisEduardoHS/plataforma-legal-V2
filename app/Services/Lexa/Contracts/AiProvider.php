<?php

namespace App\Services\Lexa\Contracts;

use App\Services\Lexa\Data\LexaCompletion;
use App\Services\Lexa\Data\LexaPrompt;

interface AiProvider
{
    public function isConfigured(): bool;

    public function generate(LexaPrompt $prompt): LexaCompletion;
}
