<?php

namespace Tests\Feature;

use App\Support\CommunityCardIcons;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use Tests\TestCase;

class CommunityCardIconsTest extends TestCase
{
    public function test_static_community_card_icons_match_lucide_blade_output_byte_for_byte(): void
    {
        $icons = app(CommunityCardIcons::class);

        $expectedTemplates = [
            'badge-check' => '<x-lucide-badge-check class="community-verified-icon" aria-label="Perfil verificado" title="Revisión documental interna vigente; no es una consulta oficial automática ni garantía de calidad." />',
            'check' => '<x-lucide-check aria-hidden="true" />',
            'plus' => '<x-lucide-plus aria-hidden="true" />',
            'file-text' => '<x-lucide-file-text />',
            'download' => '<x-lucide-download aria-hidden="true" />',
            'thumbs-up' => '<x-lucide-thumbs-up aria-hidden="true" />',
            'message-circle' => '<x-lucide-message-circle aria-hidden="true" />',
            'share-2' => '<x-lucide-share-2 aria-hidden="true" />',
            'bookmark' => '<x-lucide-bookmark aria-hidden="true" />',
        ];

        foreach ($expectedTemplates as $key => $template) {
            $expected = Blade::render($template);
            $actual = $icons->svg($key)->toHtml();

            $this->assertSame($expected, $actual, "Icon [{$key}] diverged from Blade output.");
            $this->assertSame($expected, $icons->svg($key)->toHtml(), "Memoized icon [{$key}] diverged on reuse.");
        }
    }

    public function test_unknown_icon_key_is_rejected_and_cache_is_scoped_per_request(): void
    {
        $first = app(CommunityCardIcons::class);
        $first->svg('plus');

        $this->app->forgetScopedInstances();

        $second = app(CommunityCardIcons::class);
        $this->assertNotSame($first, $second);

        $this->expectException(InvalidArgumentException::class);
        $second->svg('unknown-icon" onclick="alert(1)');
    }
}
