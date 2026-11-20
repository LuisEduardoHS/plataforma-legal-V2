<?php

namespace App\Services\Lexa\Data;

final readonly class LexaCompletion
{
    /**
     * @param  array<int, array<string, mixed>>  $citations
     */
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $latencyMs = null,
        public array $citations = [],
    ) {}
}
