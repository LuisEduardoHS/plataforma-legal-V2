<?php

namespace App\Services\Lexa\Providers;

use App\Exceptions\LexaUnavailableException;
use App\Services\Lexa\Contracts\AiProvider;
use App\Services\Lexa\Data\LexaCompletion;
use App\Services\Lexa\Data\LexaPrompt;

final class NullAiProvider implements AiProvider
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function generate(LexaPrompt $prompt): LexaCompletion
    {
        throw new LexaUnavailableException('La generación con IA real aún no está habilitada.');
    }
}
