<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Per-request memoization for static Lucide SVG icons in Community cards.
 *
 * Scoped to the active request container so rendered HTML is never persisted
 * or shared across requests/users, while avoiding repeated Blade component
 * resolution across 20 feed cards.
 */
final class CommunityCardIcons
{
    /** @var array<string, HtmlString> */
    private array $rendered = [];

    public function svg(string $key): HtmlString
    {
        if (isset($this->rendered[$key])) {
            return $this->rendered[$key];
        }

        $template = match ($key) {
            'badge-check' => '<x-lucide-badge-check class="community-verified-icon" aria-label="Perfil verificado" title="Revisión documental interna vigente; no es una consulta oficial automática ni garantía de calidad." />',
            'check' => '<x-lucide-check aria-hidden="true" />',
            'plus' => '<x-lucide-plus aria-hidden="true" />',
            'file-text' => '<x-lucide-file-text />',
            'download' => '<x-lucide-download aria-hidden="true" />',
            'thumbs-up' => '<x-lucide-thumbs-up aria-hidden="true" />',
            'message-circle' => '<x-lucide-message-circle aria-hidden="true" />',
            'share-2' => '<x-lucide-share-2 aria-hidden="true" />',
            'bookmark' => '<x-lucide-bookmark aria-hidden="true" />',
            default => throw new InvalidArgumentException("Unsupported community card icon [{$key}]."),
        };

        return $this->rendered[$key] = new HtmlString(Blade::render($template));
    }
}
