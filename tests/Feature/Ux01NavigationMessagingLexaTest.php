<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class Ux01NavigationMessagingLexaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['lexa.enabled_generation' => false]);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);

        return new DOMXPath($dom);
    }

    public function test_internal_pages_share_identical_head_stylesheets_and_color_scheme_meta(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $baselineLinks = null;

        foreach (['/inicio', '/comunidad', '/profesionales', '/mensajes', '/lexa', '/ayuda'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $xp = $this->xpath($html);

            $this->assertSame(
                'light dark',
                $xp->evaluate('string(//head/meta[@name="color-scheme"]/@content)'),
                "Missing color-scheme meta on {$url}"
            );

            $links = [];
            foreach ($xp->query('//head/link[@rel="stylesheet"]') as $node) {
                $links[] = $node->getAttribute('href');
            }

            $this->assertNotEmpty($links);
            if ($baselineLinks === null) {
                $baselineLinks = $links;
            } else {
                $this->assertSame(
                    $baselineLinks,
                    $links,
                    "Head stylesheets differed on {$url}, which would force Turbo Drive to wait on copyNewHeadStylesheetElements"
                );
            }
        }
    }

    public function test_messaging_poll_route_does_not_enable_session_lock_blocking_and_renders_day_separators(): void
    {
        $route = Route::getRoutes()->getByName('messages.updates');
        $this->assertNotNull($route);
        $this->assertNull($route->locksFor(), 'Read-only polling route must not hold a 10s session lock');

        $a = User::factory()->create(['status' => 'active']);
        $b = User::factory()->create(['status' => 'active']);
        $conversation = app(ConversationService::class)->start($a, $b->id);
        app(MessageService::class)->send($a, $conversation->id, [
            'content' => 'Primer mensaje con separador temporal',
            'client_token' => (string) Str::uuid(),
        ]);

        $html = $this->actingAs($a)->get(route('messages.show', $conversation->id))->assertOk()->getContent();
        $xp = $this->xpath($html);

        $this->assertGreaterThanOrEqual(1, $xp->query('//*[@class="messages-date"]/time[@data-message-time="day"]')->length);
        $this->assertSame(1, $xp->query('//details[contains(@class,"messages-upload")]')->length);
        $this->assertSame(1, $xp->query('//form[@data-composer]')->length);
    }

    public function test_lexa_workspace_renders_toolbar_identity_and_accessible_scope_controls(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $html = $this->actingAs($user)->get(route('lexa'))->assertOk()->getContent();
        $xp = $this->xpath($html);

        $this->assertSame(1, $xp->query('//*[@data-lexa and @data-stage="welcome"]')->length);
        $this->assertSame(1, $xp->query('//header[contains(@class,"lexa-toolbar")]//*[@data-session-title]')->length);
        $this->assertSame(1, $xp->query('//select[@id="lexa-jurisdiction" and @data-legal-state and @aria-describedby="lexa-scope-help"]')->length);
        $this->assertSame(1, $xp->query('//p[@id="lexa-scope-help"]')->length);
        $this->assertSame(1, $xp->query('//label[contains(@class,"lexa-attach") and @aria-disabled="true"]//input[@data-attach and @disabled]')->length);
    }
}
