<?php

namespace Tests\Feature;

use App\Models\LegalSpecialty;
use App\Models\User;
use App\Services\Profiles\ProfileService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FrontendMergeIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private function assertSafeRenderedForms(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/<a\b[^>]*\bhref\s*=[^>]*\bhref\s*=/i', $html);
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $xpath = new DOMXPath($dom);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $id = $node->getAttribute('id');
            $this->assertNotContains($id, $ids, 'Duplicated DOM id: '.$id);
            $ids[] = $id;
        }
        foreach ($xpath->query('//form[translate(@method,"POST","post")="post"]') as $form) {
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        }
        preg_match_all('/<\/?form\b[^>]*>/i', $html, $tags);
        $open = false;
        foreach ($tags[0] as $tag) {
            if (str_starts_with(strtolower($tag), '</form')) {
                $this->assertTrue($open);
                $open = false;
            } else {
                $this->assertFalse($open, 'Nested form in rendered HTML');
                $open = true;
            }
        }
        $this->assertFalse($open);
    }

    public function test_public_redesign_preserves_native_forms_and_header_semantics(): void
    {
        LegalSpecialty::create(['name' => 'Especialidad sintética', 'slug' => 'merge-sintetica', 'is_active' => true]);
        foreach (['/', '/iniciar-sesion', '/registro', '/profesionales', '/ayuda'] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertSafeRenderedForms($response->getContent());
        }
        $html = $this->get('/profesionales')->assertOk()->getContent();
        $this->assertStringContainsString('name="specialty_ids[]"', $html);
        $this->assertStringNotContainsString('x-cloak', $html);
    }

    public function test_public_professional_card_does_not_claim_that_a_private_personal_profile_is_public(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $profiles = app(ProfileService::class);
        $profiles->saveUserProfile($user, $user->id, ['display_name' => 'PRIVATE-MERGE-NAME', 'visibility' => 'private']);
        $profiles->saveProfessionalProfile($user, $user->id, ['headline' => 'Profesional público sintético', 'visibility' => 'public']);
        $this->get('/profesionales/'.$user->id)->assertOk()->assertSee('Perfil personal privado')
            ->assertDontSee('Perfil personal público')->assertDontSee('PRIVATE-MERGE-NAME');
    }

    public function test_authenticated_main_pages_keep_real_controllers_and_safe_markup(): void
    {
        Http::preventStrayRequests();
        config(['lexa.enabled_generation' => false]);
        $this->actingAs(User::factory()->create(['status' => 'active']));
        foreach (['/inicio', '/comunidad', '/profesionales', '/perfil', '/mensajes', '/lexa', '/ayuda'] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertSafeRenderedForms($response->getContent());
        }
        $this->assertSame(1, substr_count($this->get('/perfil')->getContent(), 'Mis publicaciones en Comunidad'));
    }
}
