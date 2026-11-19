<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\LegalSpecialty;
use App\Models\Municipality;
use App\Models\State;
use App\Models\User;
use App\Models\VerificationEvent;
use App\Services\Profiles\ProfileService;
use App\Services\Verification\VerificationService;
use Database\Seeders\VerificationPermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\EnforcedCsrfToken;
use Tests\Support\VerificationFixtures;
use Tests\TestCase;

class ProfessionalManagementIntegrationTest extends TestCase
{
    use VerificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('cache.default'));
        // Carry the native session cookie between HTTP requests, as a browser does.
        session()->start();
        $this->withCredentials();
        $this->withCookie(config('session.cookie'), session()->getId());
        DB::beginTransaction();
        Storage::fake('attachments');
        $this->seed(VerificationPermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } finally {
            parent::tearDown();
        }
    }

    private function approved(): array
    {
        [$owner, $profile, $dossier] = $this->submitted();
        app(VerificationService::class)->approve($this->reviewer(), $dossier->id, 'Revisión sintética interna.');

        return [$owner, $profile->fresh(), $dossier->fresh()];
    }

    private function payload(User $owner, array $data = []): array
    {
        $version = $this->actingAs($owner)->get(route('profile.professional.edit'))->assertOk()->viewData('editorVersion');

        return $data + ['profile_version' => $version, 'visibility' => 'private', 'contact_visible' => '0'];
    }

    private function review(User $owner, array $data = []): array
    {
        $response = $this->postJson(route('profile.professional.prepare'), $this->payload($owner, $data))->assertOk();
        $this->assertIsString($response->json('pending_change'));

        return $response->json();
    }

    public static function states(): array
    {
        return array_map(fn ($state) => [$state], ['absent', 'incomplete', 'ready', 'draft', 'pending', 'verified', 'rejected', 'revoked', 'invalidated', 'historical']);
    }

    #[DataProvider('states')]
    public function test_profile_projects_real_professional_states_and_next_steps_without_private_dossier_data(string $state): void
    {
        $service = app(VerificationService::class);
        if ($state === 'absent') {
            $owner = User::factory()->create();
        } elseif ($state === 'incomplete') {
            $owner = User::factory()->create();
            app(ProfileService::class)->saveProfessionalProfile($owner, $owner->id, []);
        } elseif (in_array($state, ['ready', 'draft'])) {
            [$owner, $profile] = $this->professional();
            if ($state === 'draft') {
                $service->createDraft($owner, $profile->id);
            }
        } else {
            [$owner, $profile, $dossier] = $this->submitted();
            if ($state === 'rejected') {
                $service->reject($this->reviewer(), $dossier->id, 'MOTIVO PRIVADO NO PROYECTAR');
            } elseif ($state !== 'pending') {
                $reviewer = $this->reviewer();
                $service->approve($reviewer, $dossier->id, 'MOTIVO PRIVADO NO PROYECTAR');
                if ($state === 'revoked') {
                    $service->revoke($reviewer, $dossier->id, 'MOTIVO PRIVADO NO PROYECTAR');
                } elseif ($state === 'invalidated') {
                    app(ProfileService::class)->saveProfessionalProfile($owner, $owner->id, ['description' => 'Descripción material nueva']);
                } elseif ($state === 'historical') {
                    // Synthetic legacy inconsistency: approved history is not a current public badge.
                    DB::table('professional_profiles')->where('id', $profile->id)->update(['verification_status' => 'unverified', 'verified_at' => null]);
                }
            }
        }
        $response = $this->actingAs($owner)->get(route('profile'))->assertOk()
            ->assertSee('data-professional-state="'.$state.'"', false)
            ->assertDontSee('12345678')->assertDontSee('evidencia.pdf')->assertDontSee('MOTIVO PRIVADO NO PROYECTAR');
        $journey = $response->viewData('journey');
        $this->assertNotEmpty($journey['next']);
        $this->get(route($journey['route'], $journey['id'] ? [$journey['id']] : []))->assertOk();
        if ($state === 'pending') {
            $pending = $this->review($owner, ['description' => 'Cambio mientras se espera revisión']);
            $this->assertStringContainsString('invalidará tu solicitud presentada', $pending['notice']);
            $this->assertSame('pending', $profile->fresh()->verification_status->value);
        }
        $this->assertFalse($owner->can('verification.review'));
        $this->assertFalse($owner->can('operations.accounts.manage'));
    }

    public static function materialFields(): array
    {
        return [['headline', 'Otra presentación'], ['description', 'Otra descripción'], ['license_number', '87654321'],
            ['years_experience', '8'], ['attention_mode', 'remote'], ['municipality_id', 'fixture'], ['specialty_ids', 'fixture']];
    }

    #[DataProvider('materialFields')]
    public function test_actual_material_changes_require_an_integral_confirmation_and_one_final_put(string $field, mixed $value): void
    {
        [$owner, $profile, $dossier] = $this->approved();
        if ($field === 'municipality_id') {
            $country = Country::create(['name' => 'País QA', 'iso_code' => 'ZZ']);
            $state = State::create(['country_id' => $country->id, 'name' => 'Estado QA', 'code' => 'QA']);
            $value = Municipality::create(['state_id' => $state->id, 'name' => 'Municipio QA', 'code' => '01'])->id;
        } elseif ($field === 'specialty_ids') {
            $value = [LegalSpecialty::create(['name' => 'Especialidad nueva', 'slug' => fake()->uuid()])->id];
        }
        $change = [$field => $value] + ($field === 'specialty_ids' ? ['update_specialties' => '1'] : []);
        $this->actingAs($owner)->putJson(route('profile.professional.update'), $change + ['visibility' => 'private', 'contact_visible' => false])->assertUnprocessable();
        $pending = $this->review($owner, $change);
        $this->assertNotEmpty($pending['categories']);
        $this->assertStringContainsString('invalidará tu verificación vigente', $pending['notice']);
        $this->assertSame('verified', $profile->fresh()->verification_status->value);
        $this->assertSame('approved', $dossier->fresh()->status->value);
        $response = $this->putJson(route('profile.professional.update'), ['pending_change' => $pending['pending_change'], 'confirm_material' => '1']);
        $response->assertRedirect(route('profile'));
        $this->assertSame('unverified', $profile->fresh()->verification_status->value);
        $this->assertSame('invalidated', $dossier->fresh()->status->value);
        $this->assertSame(1, VerificationEvent::where('action', 'invalidated')->count());
        $this->putJson(route('profile.professional.update'), ['pending_change' => $pending['pending_change'], 'confirm_material' => '1'])->assertConflict();
        $this->assertSame(1, VerificationEvent::where('action', 'invalidated')->count());
    }

    public function test_contacts_and_noop_do_not_require_confirmation_or_invalidate_verification(): void
    {
        [$owner, $profile] = $this->approved();
        $this->postJson(route('profile.professional.prepare'), $this->payload($owner, ['contact_email' => 'qa@example.invalid', 'contact_visible' => '1']))
            ->assertOk()->assertJsonPath('redirect', route('profile'))->assertJsonMissingPath('pending_change');
        $this->postJson(route('profile.professional.prepare'), $this->payload($owner, ['headline' => $profile->headline, 'license_number' => '12345678',
            'specialty_ids' => $profile->specialties()->pluck('legal_specialties.id')->all(), 'update_specialties' => '1']))
            ->assertOk()->assertJsonMissingPath('pending_change');
        $this->assertSame('verified', $profile->fresh()->verification_status->value);
        $this->assertSame(0, VerificationEvent::where('action', 'invalidated')->count());
    }

    public function test_unverified_profile_uses_normal_post_without_confirmation(): void
    {
        [$owner, $profile] = $this->professional();
        $this->post(route('profile.professional.prepare'), $this->payload($owner, ['description' => 'Descripción nueva']))->assertRedirect(route('profile'));
        $this->assertSame('Descripción nueva', $profile->fresh()->description);
    }

    public function test_no_javascript_confirmation_has_only_categories_and_ciphertext_and_cancel_does_not_write(): void
    {
        [$owner, $profile] = $this->approved();
        $response = $this->post(route('profile.professional.prepare'), $this->payload($owner, ['description' => 'BORRADOR PRIVADO', 'license_number' => '87654321']))
            ->assertOk()->assertViewIs('pages.profile.confirm-professional')->assertSee('Descripción profesional')->assertSee('Cédula declarada (privada)')
            ->assertDontSee('87654321')->assertDontSee('12345678')->assertDontSee('BORRADOR PRIVADO')->assertSee('name="_token"', false)->assertSee('value="PUT"', false);
        $this->get(route('profile.professional.edit'))->assertOk()->assertDontSee('87654321');
        $this->assertSame('verified', $profile->fresh()->verification_status->value);
        $this->assertNotSame('BORRADOR PRIVADO', $profile->fresh()->description);
        $this->assertStringNotContainsString('BORRADOR PRIVADO', $response->viewData('pending_change'));
    }

    public function test_stale_preview_concurrent_edit_and_tampered_or_expired_confirmations_are_rejected(): void
    {
        [$owner, $profile] = $this->approved();
        $data = $this->payload($owner, ['description' => 'Cambios pendientes']);
        $pending = $this->postJson(route('profile.professional.prepare'), $data)->assertOk()->json('pending_change');
        $this->putJson(route('profile.professional.update'), ['pending_change' => $pending.'invalid', 'confirm_material' => '1'])->assertConflict();
        $this->putJson(route('profile.professional.update'), ['pending_change' => $pending, 'confirm_material' => '1', 'description' => 'OTRO DATO'])->assertUnprocessable();
        app(ProfileService::class)->saveProfessionalProfile($owner, $owner->id, ['contact_phone' => '5551234567']);
        $this->postJson(route('profile.professional.prepare'), $data)->assertConflict();
        $this->putJson(route('profile.professional.update'), ['pending_change' => $pending, 'confirm_material' => '1'])->assertConflict();
        $pending = $this->review($owner, ['description' => 'Propuesta vigente']);
        $this->travel(6)->minutes();
        $this->putJson(route('profile.professional.update'), ['pending_change' => $pending['pending_change'], 'confirm_material' => '1'])->assertConflict();
        $this->assertSame('verified', $profile->fresh()->verification_status->value);
    }

    public function test_confirmation_cannot_cross_identity_or_logout_and_still_requires_csrf(): void
    {
        [$owner, $profile] = $this->approved();
        $pending = $this->review($owner, ['description' => 'Cambio QA']);
        $this->actingAs(User::factory()->create())->putJson(route('profile.professional.update'), ['pending_change' => $pending['pending_change'], 'confirm_material' => '1'])->assertConflict();
        $this->actingAs($owner)->post(route('logout'));
        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password'])->assertRedirect(route('home'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->putJson(route('profile.professional.update'), ['pending_change' => $pending['pending_change'], 'confirm_material' => '1'])->assertConflict();
        $data = $this->payload($owner, ['description' => 'Cambio con CSRF']);
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $this->postJson(route('profile.professional.prepare'), $data)->assertStatus(419);
        $this->assertSame('verified', $profile->fresh()->verification_status->value);
    }

    public function test_inactive_associations_survive_other_edits_and_are_removed_only_after_confirmation(): void
    {
        [$owner, $profile] = $this->professional();
        $inactive = $profile->specialties()->first();
        $inactive->update(['is_active' => false]);
        $this->actingAs($owner)->get(route('profile.professional.edit'))->assertOk()->assertSee('Inactivas registradas:')->assertSee($inactive->name);
        $this->post(route('profile.professional.prepare'), $this->payload($owner, ['description' => 'Otro dato', 'specialty_ids' => [], 'update_specialties' => '0']))->assertRedirect(route('profile'));
        $this->assertSame([$inactive->id], $profile->specialties()->pluck('legal_specialties.id')->all());
        $pending = $this->review($owner, ['update_specialties' => '1']);
        $this->assertContains('Retirada de las especialidades inactivas registradas', $pending['categories']);
        $this->assertStringNotContainsString('verificación vigente', $pending['notice']);
        $this->assertSame(1, $profile->specialties()->count());
        $this->put(route('profile.professional.update'), ['pending_change' => $pending['pending_change'], 'confirm_material' => '1'])->assertRedirect(route('profile'));
        $this->assertSame(0, $profile->specialties()->count());
    }

    public function test_selection_add_remove_empty_and_validation_recovery_preserve_effective_intent(): void
    {
        [$owner, $profile] = $this->professional();
        $other = LegalSpecialty::create(['name' => 'Otra especialidad', 'slug' => fake()->uuid()]);
        $ids = [...$profile->specialties()->pluck('legal_specialties.id')->all(), $other->id];
        foreach ([$ids, [$other->id], []] as $selection) {
            $this->post(route('profile.professional.prepare'), $this->payload($owner, ['update_specialties' => '1', 'specialty_ids' => $selection]))->assertRedirect(route('profile'));
            $this->assertSame($selection, $profile->specialties()->orderBy('legal_specialties.id')->pluck('legal_specialties.id')->all());
        }
        foreach (['0', '1'] as $intent) {
            $data = $this->payload($owner, ['website' => 'invalid', 'update_specialties' => $intent, 'specialty_ids' => [$other->id], 'license_number' => '87654321']);
            $this->from(route('profile.professional.edit'))->post(route('profile.professional.prepare'), $data)->assertSessionHasErrors('website')->assertSessionMissing('_old_input.license_number');
            $html = $this->get(route('profile.professional.edit'))->assertOk()->assertDontSee('87654321');
            $dom = new \DOMDocument;
            @$dom->loadHTML($html->getContent());
            $xpath = new \DOMXPath($dom);
            $this->assertSame($intent, $xpath->evaluate('string(//form[@id="profile-edit-form"]//button[@name="update_specialties"][1]/@value)'));
            $this->assertSame(1, $xpath->query('//input[@name="specialty_ids[]" and @checked]')->length);
        }
    }

    public function test_domain_validation_after_confirmation_rolls_back_and_recovers_only_safe_draft(): void
    {
        [$owner, $profile] = $this->approved();
        $new = LegalSpecialty::create(['name' => 'Seleccionable', 'slug' => fake()->uuid()]);
        $pending = $this->review($owner, ['specialty_ids' => [$new->id], 'update_specialties' => '1', 'description' => 'BORRADOR RECUPERABLE', 'license_number' => '87654321']);
        $new->update(['is_active' => false]);
        $this->put(route('profile.professional.update'), ['pending_change' => $pending['pending_change'], 'confirm_material' => '1'])
            ->assertRedirect(route('profile.professional.edit'))->assertSessionHasErrors('specialty_ids')->assertSessionHas('_old_input.description', 'BORRADOR RECUPERABLE')
            ->assertSessionMissing('_old_input.license_number')->assertSessionMissing('_old_input.pending_change');
        $this->assertSame('verified', $profile->fresh()->verification_status->value);
        $this->assertSame('12345678', $profile->fresh()->license_number);
        $this->assertSame(0, VerificationEvent::where('action', 'invalidated')->count());
    }

    public function test_professional_and_personal_privacy_remain_independent_and_guests_cannot_prepare_edits(): void
    {
        $this->postJson(route('profile.professional.prepare'), [])->assertUnauthorized();
        [$owner, $profile] = $this->professional();
        app(ProfileService::class)->saveUserProfile($owner, $owner->id, ['display_name' => 'NOMBRE PERSONAL PRIVADO', 'visibility' => 'private']);
        app(ProfileService::class)->saveProfessionalProfile($owner, $owner->id, ['visibility' => 'public', 'contact_email' => 'privado@example.invalid', 'contact_visible' => false]);
        $this->get(route('professionals.show', $owner->id))->assertOk()->assertDontSee('NOMBRE PERSONAL PRIVADO')->assertDontSee($owner->name)->assertDontSee('12345678')->assertDontSee('privado@example.invalid');
        $this->actingAs($owner)->get(route('profile'))->assertOk()->assertSee('Perfil personal privado')->assertSee('Visible en el directorio');
        $owner->status = 'suspended';
        $owner->save();
        $this->postJson(route('profile.professional.prepare'), [])->assertUnauthorized();
    }

    public function test_profile_query_cannot_page_the_current_dossier_out_of_the_professional_projection(): void
    {
        [$owner, $profile] = $this->professional();
        $dossier = app(VerificationService::class)->createDraft($owner, $profile->id);
        $response = $this->actingAs($owner)->get(route('profile', ['page' => 99]))->assertOk()
            ->assertSee('data-professional-state="draft"', false);
        $this->assertSame($dossier->id, $response->viewData('journey')['id']);
        $this->assertSame('verification.show', $response->viewData('journey')['route']);
    }

    public function test_opening_a_dossier_invalidates_an_older_profile_confirmation_without_changing_profile_fields(): void
    {
        [$owner, $profile] = $this->professional();
        $profile->specialties()->first()->update(['is_active' => false]);
        $pending = $this->review($owner, ['update_specialties' => '1']);
        app(VerificationService::class)->createDraft($owner, $profile->id);
        $this->putJson(route('profile.professional.update'), ['pending_change' => $pending['pending_change'], 'confirm_material' => '1'])->assertConflict();
        $this->assertSame(1, $profile->specialties()->count());
        $this->assertSame('unverified', $profile->fresh()->verification_status->value);
    }

    public function test_maximum_length_unicode_description_survives_the_encrypted_confirmation_round_trip(): void
    {
        [$owner, $profile] = $this->approved();
        $description = str_repeat('🔎', 10000);
        $pending = $this->review($owner, ['description' => $description]);
        $this->assertLessThanOrEqual(262144, strlen($pending['pending_change']));
        $this->put(route('profile.professional.update'), ['pending_change' => $pending['pending_change'], 'confirm_material' => '1'])->assertRedirect(route('profile'));
        $this->assertSame($description, $profile->fresh()->description);
        $this->assertSame('unverified', $profile->fresh()->verification_status->value);
        $this->assertSame(1, VerificationEvent::where('action', 'invalidated')->count());
    }

    public static function emptySelectionRecoveryCases(): array
    {
        return [
            'review-active' => ['profile.professional.prepare', false, false],
            'legacy-update-active' => ['profile.professional.update', false, false],
            'review-verified' => ['profile.professional.prepare', true, false],
            'review-inactive' => ['profile.professional.prepare', false, true],
        ];
    }

    #[DataProvider('emptySelectionRecoveryCases')]
    public function test_native_empty_selection_survives_another_field_error_and_the_corrected_submission(string $route, bool $verified, bool $inactive): void
    {
        [$owner, $profile] = $verified ? $this->approved() : $this->professional();
        if ($inactive) {
            $profile->specialties()->first()->update(['is_active' => false]);
        }
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $data = $this->payload($owner, ['update_specialties' => '1', 'years_experience' => '81', 'license_number' => '87654321']);
        $data['_token'] = session()->token();
        $this->assertArrayNotHasKey('specialty_ids', $data); // Unchecked native checkboxes send no key.
        $method = $route === 'profile.professional.prepare' ? 'post' : 'put';
        $this->from(route('profile.professional.edit'))->{$method}(route($route), $data)
            ->assertRedirect(route('profile.professional.edit'))->assertSessionHasErrors('years_experience')
            ->assertSessionHasInput('specialty_ids', [])->assertSessionHasInput('update_specialties', true)
            ->assertSessionMissing('_old_input.license_number');
        $html = $this->get(route('profile.professional.edit'))->assertOk()
            ->assertSee('En el último envío elegiste aplicar la selección')->assertDontSee('87654321')->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $selected = $xpath->query('//input[@name="specialty_ids[]" and @checked]');
        $this->assertSame(0, $selected->length);
        $this->assertSame('1', $xpath->evaluate('string(//form[@id="profile-edit-form"]//button[@name="update_specialties"][1]/@value)'));
        $this->assertSame('', $xpath->evaluate('string(//input[@name="license_number"]/@value)'));
        $this->assertSame(1, $profile->specialties()->count());
        $this->assertSame($verified ? 'verified' : 'unverified', $profile->fresh()->verification_status->value);
        $this->assertSame(0, VerificationEvent::where('action', 'invalidated')->count());

        // Re-submit the rendered form after correcting the other field, still with no checked IDs.
        $retry = ['_token' => session()->token(), 'profile_version' => $xpath->evaluate('string(//input[@name="profile_version"]/@value)'),
            'visibility' => 'private', 'contact_visible' => '0', 'update_specialties' => '1', 'years_experience' => '0', 'license_number' => ''];
        $response = $this->post(route('profile.professional.prepare'), $retry);
        if ($verified || $inactive) {
            $response->assertOk()->assertViewIs('pages.profile.confirm-professional');
            $this->assertSame(1, $profile->specialties()->count());
            $this->putJson(route('profile.professional.update'), $retry)->assertUnprocessable()->assertJsonValidationErrors('profile');
            $this->put(route('profile.professional.update'), ['_token' => session()->token(),
                'pending_change' => $response->viewData('pending_change'), 'confirm_material' => '1'])->assertRedirect(route('profile'));
        } else {
            $response->assertRedirect(route('profile'));
        }
        $this->assertSame(0, $profile->specialties()->count());
        $this->assertSame('12345678', $profile->fresh()->license_number);
        $this->assertSame($verified ? 1 : 0, VerificationEvent::where('action', 'invalidated')->count());
    }

    public function test_native_keep_intention_survives_errors_and_preserves_inactive_associations_on_retry(): void
    {
        [$owner, $profile] = $this->professional();
        $inactive = $profile->specialties()->first();
        $inactive->update(['is_active' => false]);
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $data = $this->payload($owner, ['update_specialties' => '0', 'years_experience' => '81']);
        $data['_token'] = session()->token();
        $this->from(route('profile.professional.edit'))->post(route('profile.professional.prepare'), $data)
            ->assertSessionHasErrors('years_experience')->assertSessionHasInput('update_specialties', false);
        $this->assertArrayNotHasKey('specialty_ids', session()->getOldInput());
        $html = $this->get(route('profile.professional.edit'))->assertOk()->assertSee('Inactivas registradas:')->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame('0', $xpath->evaluate('string(//form[@id="profile-edit-form"]//button[@name="update_specialties"][1]/@value)'));
        $data['profile_version'] = $xpath->evaluate('string(//input[@name="profile_version"]/@value)');
        $data['years_experience'] = '0';
        $this->post(route('profile.professional.prepare'), $data)->assertRedirect(route('profile'));
        $this->assertSame([$inactive->id], $profile->specialties()->pluck('legal_specialties.id')->all());
        $this->assertSame(0, VerificationEvent::where('action', 'invalidated')->count());
    }
}
