<?php

namespace App\Services\Lexa\Data;

final readonly class LexaPrompt
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function __construct(
        public string $mode,
        public array $messages,
        public int $maxOutputTokens = 1024,
    ) {}
}
