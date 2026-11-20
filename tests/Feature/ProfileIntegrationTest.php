<?php

namespace Tests\Feature;

use App\Enums\ProfessionalVerificationStatus;
use App\Exceptions\VerificationException;
use App\Models\Country;
use App\Models\LegalSpecialty;
use App\Models\Municipality;
use App\Models\ProfessionalProfile;
use App\Models\State;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Profiles\ProfessionalDirectory;
use App\Services\Profiles\ProfessionalEditor;
use App\Services\Profiles\ProfileService;
use App\Services\Verification\VerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnforcedCsrfToken;
use Tests\TestCase;

class ProfileIntegrationTest extends TestCase
{
    private bool $transactionStarted = false;

    private ProfileService $profiles;

    protected function setUp(): void
    {
        parent::setUp(); // Verifies configured and actual MySQL testing database first.
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->profiles = app(ProfileService::class);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->transactionStarted) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function user(string $name = 'Persona privada'): User
    {
        return User::factory()->create(['name' => $name])->refresh();
    }

    private function professional(string $name = 'Persona pública', array $data = [], bool $publicIdentity = true): User
    {
        $user = $this->user($name);
        $this->profiles->saveUserProfile($user, $user->id, ['display_name' => $name, 'visibility' => $publicIdentity ? 'public' : 'private']);
        $this->profiles->saveProfessionalProfile($user, $user->id, $data + ['visibility' => 'public', 'headline' => 'Asesoría jurídica']);

        return $user;
    }

    public function test_integrated_directory_accepts_multiple_specialties_and_preserves_legacy_filter(): void
    {
        $first = $this->specialty();
        $second = LegalSpecialty::create(['name' => 'Otra especialidad sintética', 'slug' => 'otra-sintetica', 'is_active' => true]);
        $a = $this->professional('Profesional Alfa', ['specialty_ids' => [$first->id]]);
        $b = $this->professional('Profesional Beta', ['specialty_ids' => [$second->id]]);
        $this->professional('Profesional sin categoría');

        $this->get('/profesionales?'.http_build_query(['specialty_ids' => [$first->id, $second->id]]))
            ->assertOk()->assertViewHas('profiles', fn ($page) => $page->total() === 2)
            ->assertSee('Profesional Alfa')->assertSee('Profesional Beta')->assertDontSee('Profesional sin categoría');
        $this->get('/profesionales?specialty_id='.$first->id)->assertOk()
            ->assertViewHas('profiles', fn ($page) => $page->count() === 1 && $page->first()['user_id'] === $a->id);
        $this->getJson('/profesionales?'.http_build_query(['specialty_ids' => [$first->id, $first->id]]))->assertUnprocessable();
        $this->getJson('/profesionales?specialty_ids[]=999999999')->assertUnprocessable();
        $second->update(['is_active' => false]);
        $this->getJson('/profesionales?specialty_ids[]='.$second->id)->assertUnprocessable();
    }

    private function municipality(): Municipality
    {
        $country = Country::create(['name' => 'País de prueba', 'iso_code' => 'ZZ']);
        $state = State::create(['country_id' => $country->id, 'name' => 'Estado de prueba', 'code' => '01']);

        return Municipality::create(['state_id' => $state->id, 'name' => 'Villa Jurídica', 'code' => '001']);
    }

    private function specialty(string $name = 'Derecho civil', bool $active = true): LegalSpecialty
    {
        return LegalSpecialty::create(['name' => $name, 'slug' => fake()->uuid(), 'is_active' => $active]);
    }

    private function confirmedProfessionalUpdate(array $data)
    {
        $data['profile_version'] = app(ProfessionalEditor::class)->version(auth()->user());
        $pending = $this->postJson(route('profile.professional.prepare'), $data)->assertOk()->json('pending_change');
        $this->assertIsString($pending);
        $this->withCookie(config('session.cookie'), session()->getId());

        return $this->put(route('profile.professional.update'), ['pending_change' => $pending, 'confirm_material' => '1']);
    }

    public function test_guests_can_read_the_directory_but_cannot_edit_or_download_private_photos(): void
    {
        $this->get('/profesionales')->assertOk()->assertSee('No encontramos perfiles');
        foreach (['/perfil', '/perfil/editar', '/perfil/profesional/editar', '/perfil/fotografia'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->put('/perfil', ['visibility' => 'public'])->assertRedirect(route('login'));
        $this->put('/perfil/profesional', [])->assertRedirect(route('login'));
        $this->post('/perfil/fotografia')->assertRedirect(route('login'));
        $this->delete('/perfil/fotografia')->assertRedirect(route('login'));
        $this->get('/usuarios/999999999')->assertNotFound();
        $this->get('/profesionales/999999999')->assertNotFound();
        $this->get('/profesionales/99999999999999999999999999')->assertNotFound();
        $this->get('/usuarios/0')->assertNotFound();
    }

    public function test_optional_profile_pages_do_not_create_rows_and_offer_real_editing(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get('/perfil')->assertOk()->assertSee($user->name)->assertSee('Completar mi perfil')->assertDontSee('Juanito Pérez');
        $this->get('/perfil/editar')->assertOk();
        $this->get('/perfil/profesional/editar')->assertOk();
        $this->assertSame(0, UserProfile::count());
        $this->assertSame(0, ProfessionalProfile::count());
    }

    public function test_personal_profile_upsert_preserves_account_identity_and_location_is_normalized(): void
    {
        $user = $this->user();
        $municipality = $this->municipality();
        $this->actingAs($user)->put('/perfil', [
            'display_name' => 'Alias público', 'username' => 'Mi_Alias', 'biography' => 'Descripción real',
            'municipality_id' => $municipality->id, 'visibility' => 'public', 'website' => 'https://example.test',
        ])->assertRedirect(route('profile'))->assertSessionHas('status');
        $profileId = $user->profile()->firstOrFail()->id;
        $this->get('/perfil')->assertSee('Villa Jurídica')->assertSee('Alias público');
        $this->put('/perfil', ['username' => 'MI_ALIAS', 'visibility' => 'private'])->assertRedirect(route('profile'));
        $this->assertSame($profileId, $user->profile()->firstOrFail()->id);
        $this->assertSame('mi_alias', $user->profile()->firstOrFail()->username);
        $this->assertSame('Persona privada', $user->fresh()->name);
        $this->assertSame(1, User::count());
        $this->assertSame([], $user->getRoleNames()->all());
    }

    public static function forbiddenFields(): array
    {
        return [['user_id', 999], ['roles', ['admin']], ['permissions', ['users.manage']], ['status', 'active'],
            ['verification_status', 'verified'], ['verified_at', '2026-11-14 00:00:00'], ['photo_attachment_id', 1],
            ['name', 'Reemplazo'], ['email', 'private@example.test']];
    }

    #[DataProvider('forbiddenFields')]
    public function test_http_rejects_internal_fields_on_both_own_forms(string $field, mixed $value): void
    {
        $user = $this->user();
        $this->actingAs($user)->putJson('/perfil', ['visibility' => 'public', $field => $value])->assertUnprocessable()->assertJsonValidationErrors('profile');
        $this->putJson('/perfil/profesional', ['visibility' => 'public', 'contact_visible' => false, $field => $value])->assertUnprocessable()->assertJsonValidationErrors('profile');
        $this->assertSame(0, UserProfile::count());
        $this->assertSame(0, ProfessionalProfile::count());
    }

    public function test_no_foreign_update_route_exists_and_forged_target_does_not_modify_either_profile(): void
    {
        $owner = $this->professional();
        $attacker = $this->user();
        $this->actingAs($attacker)->putJson('/perfil', ['user_id' => $owner->id, 'visibility' => 'public'])->assertUnprocessable();
        $this->put('/usuarios/'.$owner->id, ['display_name' => 'Atacante'])->assertStatus(405);
        $this->put('/profesionales/'.$owner->id, ['headline' => 'Atacante'])->assertStatus(405);
        $this->assertSame('Persona pública', $owner->profile->display_name);
        $this->assertNull($attacker->profile);
    }

    public function test_invalid_fields_and_malformed_old_input_cannot_break_the_form(): void
    {
        $user = $this->user();
        $this->actingAs($user)->from('/perfil/editar')->put('/perfil', [
            'visibility' => 'public', 'display_name' => ['bad'], 'username' => 'ab', 'website' => 'javascript:alert(1)', 'municipality_id' => -1,
        ])->assertRedirect('/perfil/editar')->assertSessionHasErrors(['display_name', 'username', 'website', 'municipality_id']);
        $this->get('/perfil/editar')->assertOk();
        $this->assertSame(0, UserProfile::count());
    }

    public function test_username_uniqueness_is_enforced_by_the_existing_domain_service(): void
    {
        $owner = $this->user();
        $this->profiles->saveUserProfile($owner, $owner->id, ['username' => 'nombre_unico']);
        $this->actingAs($this->user())->putJson('/perfil', ['visibility' => 'public', 'username' => 'NOMBRE_UNICO'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame(1, UserProfile::count());
    }

    public function test_public_profiles_hide_optional_private_identity_contacts_and_credentials(): void
    {
        $user = $this->professional('Nombre secreto', ['license_number' => '123456789', 'contact_email' => 'oculto@example.test', 'contact_phone' => '+52 5555555555'], false);
        $this->get('/usuarios/'.$user->id)->assertNotFound();
        foreach (['/profesionales', '/profesionales/'.$user->id] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Nombre secreto')->assertDontSee($user->email)->assertDontSee('123456789')->assertDontSee('oculto@example.test')->assertDontSee('+52 5555555555')->assertDontSee('license_number');
        }
        $this->profiles->saveProfessionalProfile($user, $user->id, ['contact_visible' => true]);
        $this->get('/profesionales/'.$user->id)->assertSee('oculto@example.test')->assertDontSee($user->email);
        $this->profiles->saveProfessionalProfile($user, $user->id, ['visibility' => 'private']);
        $this->get('/profesionales/'.$user->id)->assertNotFound();
        $this->get('/profesionales')->assertSee('No encontramos perfiles');
    }

    public function test_public_personal_profile_is_escaped_and_does_not_publish_private_professional_data(): void
    {
        $user = $this->professional('<script>alert(1)</script>', ['visibility' => 'private', 'description' => 'Información reservada']);
        $this->get('/usuarios/'.$user->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Información reservada');
        $this->get('/profesionales/'.$user->id)->assertNotFound();
    }

    public static function inactiveStatuses(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_persisted_inactive_status_stops_reads_and_writes_even_with_a_stale_actor(string $status): void
    {
        $user = $this->professional();
        DB::table('users')->where('id', $user->id)->update(['status' => $status]);
        $this->actingAs($user)->putJson('/perfil', ['display_name' => 'Alterado', 'visibility' => 'public'])->assertUnauthorized();
        $this->assertSame('Persona pública', $user->profile()->firstOrFail()->display_name);
        $this->get('/usuarios/'.$user->id)->assertNotFound();
        $this->get('/profesionales/'.$user->id)->assertNotFound();
        $this->get('/profesionales')->assertSee('No encontramos perfiles');
    }

    public function test_directory_filters_search_only_public_identity_specialties_and_locations(): void
    {
        $municipality = $this->municipality();
        $specialty = $this->specialty();
        $user = $this->professional('Ana Pública', ['specialty_ids' => [$specialty->id], 'municipality_id' => $municipality->id]);
        $this->professional('Nombre privado', [], false);
        foreach ([['q' => 'Ana Pública'], ['q' => 'Derecho civil'], ['q' => 'Villa Jurídica'], ['specialty_id' => $specialty->id], ['state_id' => $municipality->state_id], ['municipality_id' => $municipality->id]] as $filters) {
            $this->get('/profesionales?'.http_build_query($filters))->assertOk()->assertSee('Ana Pública')->assertViewHas('profiles', fn ($p) => $p->total() === 1);
        }
        foreach (['Nombre privado', $user->email, '%', '_', '0', "' OR 1=1 --"] as $query) {
            $this->get('/profesionales?'.http_build_query(['q' => $query]))->assertOk()->assertViewHas('profiles', fn ($p) => $p->total() === 0);
        }
    }

    public function test_directory_supports_professionals_without_personal_profiles_and_public_account_name_fallback(): void
    {
        $user = $this->user('Nombre protegido');
        $this->profiles->saveProfessionalProfile($user, $user->id, ['visibility' => 'public']);
        $this->get('/profesionales')->assertOk()->assertDontSee('Nombre protegido');
        $this->get('/profesionales/'.$user->id)->assertOk()->assertDontSee('Nombre protegido');
        $this->profiles->saveUserProfile($user, $user->id, ['visibility' => 'public']);
        $this->get('/profesionales?q=Nombre')->assertOk()->assertSee('Nombre protegido');
    }

    public function test_server_pagination_is_stable_preserves_filters_and_avoids_n_plus_one(): void
    {
        $first = $this->professional('Persona 1');
        for ($i = 2; $i <= 13; $i++) {
            $this->professional('Persona '.$i);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->get('/profesionales?q=Persona');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $response->assertOk()->assertViewHas('profiles', fn ($p) => $p->count() === 12 && $p->total() === 13 && $p[0]['url'] === route('professionals.show', $first->id))->assertSee('q=Persona', false);
        $this->assertLessThanOrEqual(15, $queryCount);
        $this->get('/profesionales?q=Persona&page=2')->assertOk()->assertViewHas('profiles', fn ($p) => $p->count() === 1 && $p[0]['name'] === 'Persona 13');
        $this->get('/profesionales?page=500')->assertOk()->assertSee('No encontramos perfiles');
    }

    public function test_invalid_directory_filters_redirect_to_a_clean_url_without_a_loop(): void
    {
        $this->get('/profesionales?q[]=bad')->assertRedirect(route('professionals'))->assertSessionHasErrors('filters');
        $this->get('/profesionales')->assertOk();
        $this->getJson('/profesionales?specialty_id=-1')->assertUnprocessable();
        $this->getJson('/profesionales?page=10001')->assertUnprocessable();
        $this->getJson('/profesionales?state_id[]=1&municipality_id=1')->assertUnprocessable();
    }

    public function test_invalid_location_catalog_redirects_safely_and_current_location_is_not_lost(): void
    {
        $user = $this->user();
        $municipality = $this->municipality();
        $this->profiles->saveUserProfile($user, $user->id, ['municipality_id' => $municipality->id]);
        $this->actingAs($user)->get('/perfil/editar?state_id=-1')->assertRedirect(route('profile.edit'));
        $this->get('/perfil/editar')->assertOk()->assertSee('Villa Jurídica');
        $this->get('/perfil/editar?state_id=')->assertOk()->assertSee('Villa Jurídica');
        $this->assertSame($municipality->id, $user->profile->municipality_id);
    }

    public function test_write_rate_limit_prevents_additional_mutations(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        for ($i = 0; $i < 20; $i++) {
            $this->put('/perfil', ['visibility' => 'private', 'biography' => 'Versión '.$i])->assertRedirect(route('profile'));
        }
        $this->putJson('/perfil', ['visibility' => 'public', 'biography' => 'No guardar'])->assertStatus(429);
        $this->assertSame('Versión 19', $user->profile()->firstOrFail()->biography);
    }

    public function test_public_query_failure_is_sanitized_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        $this->mock(ProfileService::class)->shouldReceive('publicUserProfile')->once()->andThrow(new QueryException('mysql', 'SELECT secret FROM credentials', [], new \PDOException('private SQL detail')));
        $this->get('/usuarios/123')->assertStatus(503)->assertDontSee('credentials')->assertDontSee('private SQL detail')->assertDontSee('Stack trace');
    }

    public function test_failed_verification_invalidation_rolls_back_the_professional_edit_with_a_safe_message(): void
    {
        $user = $this->professional();
        $profile = $user->professionalProfile;
        $profile->forceFill(['verification_status' => 'verified', 'verified_at' => now()])->save();
        $this->mock(VerificationService::class)->shouldReceive('invalidateForProfileChange')->once()->andThrow(new VerificationException);
        $this->actingAs($user)->from('/perfil/profesional/editar');
        $this->confirmedProfessionalUpdate([
            'visibility' => 'public', 'contact_visible' => false, 'headline' => 'No guardar', 'license_number' => '001234567',
        ])->assertRedirect('/perfil/profesional/editar')->assertSessionHasErrors('profile')->assertSessionMissing('_old_input.license_number');
        $this->assertSame('Asesoría jurídica', $profile->fresh()->headline);
        $this->assertSame(ProfessionalVerificationStatus::Verified, $profile->fresh()->verification_status);
        $this->assertNull($profile->fresh()->license_number);
    }

    public function test_sql_failure_in_account_middleware_uses_the_generic_profile_error_renderer(): void
    {
        config(['app.debug' => true]);
        $this->actingAs($this->user());
        $failed = false;
        DB::listen(function () use (&$failed) {
            if (! $failed) {
                $failed = true;
                throw new QueryException('mysql', 'SELECT secret FROM credentials', [], new \PDOException('private SQL detail'));
            }
        });
        $this->get('/perfil')->assertStatus(503)->assertSee('Los perfiles no están disponibles')->assertDontSee('credentials')->assertDontSee('private SQL detail')->assertDontSee('Stack trace');
    }

    public function test_professional_edit_stores_only_allowed_fields_and_never_renders_or_flashes_the_license(): void
    {
        $user = $this->user();
        $specialty = $this->specialty();
        $this->actingAs($user)->put('/perfil/profesional', [
            'visibility' => 'public', 'contact_visible' => '1', 'contact_email' => 'profesional@example.test',
            'license_number' => '123456789', 'years_experience' => 8, 'specialty_ids' => [$specialty->id], 'update_specialties' => '1',
        ])->assertRedirect(route('profile'));
        $profile = $user->professionalProfile()->firstOrFail();
        $this->assertSame(ProfessionalVerificationStatus::Unverified, $profile->verification_status);
        $this->assertSame([$specialty->id], $profile->specialties->pluck('id')->all());
        $this->assertNotSame('123456789', $profile->getRawOriginal('license_number'));
        $this->get('/perfil/profesional/editar')->assertOk()->assertDontSee('123456789')->assertSee('Cédula registrada');
        $this->put('/perfil/profesional', ['visibility' => 'public', 'contact_visible' => false, 'license_number' => '123456789', 'headline' => ['invalid']])->assertSessionHasErrors('headline')->assertSessionMissing('_old_input.license_number');
        $this->put('/perfil/profesional', ['visibility' => 'private', 'contact_visible' => false, 'license_number' => ''])->assertRedirect(route('profile'));
        $this->assertSame('123456789', $profile->fresh()->license_number);
        $this->assertSame([], $user->getRoleNames()->all());
    }

    public function test_inactive_specialties_are_preserved_until_explicitly_replaced_and_invalid_selections_rejected(): void
    {
        $specialty = $this->specialty();
        $user = $this->professional('Persona', ['specialty_ids' => [$specialty->id]]);
        $specialty->update(['is_active' => false]);
        $this->actingAs($user)->put('/perfil/profesional', ['visibility' => 'private', 'contact_visible' => false])->assertRedirect(route('profile'));
        $this->assertCount(1, $user->professionalProfile->specialties);
        $this->putJson('/perfil/profesional', ['visibility' => 'public', 'contact_visible' => false, 'update_specialties' => true, 'specialty_ids' => [$specialty->id]])->assertUnprocessable()->assertJsonValidationErrors('specialty_ids');
        $this->putJson('/perfil/profesional', ['visibility' => 'public', 'contact_visible' => false, 'update_specialties' => true, 'specialty_ids' => [999999]])->assertUnprocessable();
        $this->confirmedProfessionalUpdate(['visibility' => 'private', 'contact_visible' => false, 'update_specialties' => true])->assertRedirect(route('profile'));
        $this->assertSame(0, $user->professionalProfile->specialties()->count());
    }

    public function test_material_changes_invalidate_verification_but_contact_changes_do_not(): void
    {
        $user = $this->professional();
        $profile = $user->professionalProfile;
        $profile->forceFill(['verification_status' => 'verified', 'verified_at' => now()])->save();
        $this->get('/profesionales')->assertSee('Perfil verificado');
        $this->actingAs($user)->put('/perfil/profesional', ['visibility' => 'public', 'contact_visible' => true, 'contact_email' => 'publico@example.test'])->assertRedirect(route('profile'));
        $this->assertSame(ProfessionalVerificationStatus::Verified, $profile->fresh()->verification_status);
        $this->confirmedProfessionalUpdate(['visibility' => 'public', 'contact_visible' => true, 'headline' => 'Nueva presentación'])->assertRedirect(route('profile'));
        $this->assertSame(ProfessionalVerificationStatus::Unverified, $profile->fresh()->verification_status);
        $this->assertNull($profile->fresh()->verified_at);
        $this->get('/profesionales/'.$user->id)->assertSee('Sin verificar')->assertDontSee('Perfil verificado');
    }

    public function test_profile_mutations_enforce_csrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $this->actingAs($this->user());
        foreach (['/perfil', '/perfil/profesional'] as $url) {
            $this->put($url, [])->assertStatus(419);
        }
        $this->post('/perfil/fotografia')->assertStatus(419);
        $this->delete('/perfil/fotografia')->assertStatus(419);
    }

    public function test_directory_failures_are_sanitized_and_display_a_retry_state(): void
    {
        $this->mock(ProfessionalDirectory::class)->shouldReceive('search')->once()->andThrow(new QueryException('mysql', 'SELECT secret FROM credentials', [], new \PDOException('private SQL detail')));
        $this->get('/profesionales')->assertStatus(503)->assertSee('No pudimos cargar los perfiles')->assertDontSee('credentials')->assertDontSee('private SQL detail');
    }

    public function test_search_is_rate_limited(): void
    {
        for ($i = 0; $i < 90; $i++) {
            $this->get('/profesionales')->assertOk();
        }
        $this->get('/profesionales')->assertStatus(429);
    }
}
