<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\LegalSpecialty;
use App\Models\Municipality;
use App\Models\State;
use App\Models\User;
use App\Services\Profiles\ProfileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileLocationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            DB::rollBack();
        } finally {
            parent::tearDown();
        }
    }

    private function geography(): array
    {
        $country = Country::create(['name' => 'País sintético', 'iso_code' => 'ZZ']);
        $first = State::create(['country_id' => $country->id, 'name' => 'Estado A', 'code' => '01']);
        $second = State::create(['country_id' => $country->id, 'name' => 'Estado B', 'code' => '02']);
        $empty = State::create(['country_id' => $country->id, 'name' => 'Estado vacío', 'code' => '03']);
        $cityA = Municipality::create(['state_id' => $first->id, 'name' => 'Municipio A', 'code' => '001']);
        $cityB = Municipality::create(['state_id' => $second->id, 'name' => 'Municipio B', 'code' => '001']);

        return [$first, $second, $empty, $cityA, $cityB];
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($document);
    }

    public static function editors(): array
    {
        return ['personal' => [false], 'professional' => [true]];
    }

    public static function invalidMunicipalities(): array
    {
        $cases = [];
        foreach ([false, true] as $professional) {
            foreach (['negative' => '-1', 'missing' => '999999999', 'text' => 'invalid', 'array' => ['invalid']] as $label => $value) {
                $cases[($professional ? 'professional ' : 'personal ').$label] = [$professional, $value];
            }
        }

        return $cases;
    }

    private function editorFixture(bool $professional, Municipality $city): array
    {
        $user = User::factory()->create();
        $service = app(ProfileService::class);
        if ($professional) {
            $service->saveProfessionalProfile($user, $user->id, ['visibility' => 'private', 'municipality_id' => $city->id, 'description' => 'Texto persistido', 'license_number' => '1234567']);
            $data = ['headline' => 'Presentación de borrador', 'description' => 'Texto de borrador', 'years_experience' => '9', 'attention_mode' => 'remote',
                'contact_visible' => '1', 'contact_email' => 'draft@example.invalid', 'contact_phone' => '+52 555 123 4567'];
        } else {
            $service->saveUserProfile($user, $user->id, ['visibility' => 'private', 'municipality_id' => $city->id, 'biography' => 'Texto persistido']);
            $data = ['display_name' => 'Nombre de borrador', 'username' => 'draft_'.$user->id, 'biography' => 'Texto de borrador'];
        }

        return [$user, $professional ? '/perfil/profesional/editar' : '/perfil/editar', $professional ? '/perfil/profesional' : '/perfil',
            $data + ['municipality_id' => (string) $city->id, 'visibility' => 'private', 'website' => 'https://example.invalid/draft']];
    }

    private function assertLocation(\DOMXPath $xpath, int $stateId, ?int $municipalityId): void
    {
        $this->assertSame((string) $stateId, $xpath->evaluate('string(//select[@id="state_id"]/option[@selected]/@value)'));
        $this->assertSame($municipalityId === null ? '' : (string) $municipalityId, $xpath->evaluate('string(//select[@id="municipality_id"]/option[@selected]/@value)'));
        $this->assertSame(1, $xpath->query('//select[@id="municipality_id"]/option[@selected]')->length);
        $this->assertSame((string) $stateId, $xpath->evaluate('string(//*[@data-profile-location]/@data-location-catalog-state)'));
        foreach (['state_id', 'municipality_id'] as $id) {
            $this->assertSame(1, $xpath->query('//label[@for="'.$id.'"]')->length);
            $references = $xpath->evaluate('string(//select[@id="'.$id.'"]/@aria-describedby)');
            foreach (explode(' ', $references) as $reference) {
                $this->assertSame(1, $xpath->query('//*[@id="'.$reference.'"]')->length);
            }
        }
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $ids[] = $node->getAttribute('id');
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    #[DataProvider('editors')]
    public function test_validation_recovery_overrides_stale_query_state_and_saves_the_submitted_municipality(bool $professional): void
    {
        [$first, $second, , $cityA, $cityB] = $this->geography();
        [$user, $editor, $endpoint, $data] = $this->editorFixture($professional, $cityA);
        $url = $editor.'?state_id='.$first->id;
        $data['municipality_id'] = (string) $cityB->id;
        $data['website'] = 'invalid';
        if ($professional) {
            $specialty = LegalSpecialty::create(['name' => 'Especialidad de recuperación', 'slug' => 'recovery-specialty', 'is_active' => true]);
            $data += ['specialty_ids' => [(string) $specialty->id], 'update_specialties' => '1', 'license_number' => '7654321'];
        }
        $this->actingAs($user)->get($url)->assertOk();
        $this->getJson($editor.'?state_id='.$second->id)->assertJsonPath('municipalities.0.id', $cityB->id);
        $this->from($url)->put($endpoint, $data)->assertRedirect($url)->assertSessionHasErrors('website')->assertSessionMissing('_old_input.license_number');
        $this->assertSame((string) $cityB->id, $this->app['session']->getOldInput('municipality_id'));
        $response = $this->get($url)->assertOk()->assertDontSee('7654321');
        $xpath = $this->xpath($response->getContent());
        $this->assertLocation($xpath, $second->id, $cityB->id);
        $this->assertSame(0, $xpath->query('//select[@id="municipality_id"]/option[@value="'.$cityA->id.'"]')->length);
        foreach ($data as $field => $value) {
            if (in_array($field, ['municipality_id', 'license_number', 'specialty_ids', 'update_specialties', 'contact_visible'], true)) {
                continue;
            }
            $node = $xpath->query('//form[@id="profile-edit-form"]//*[@name="'.$field.'"]')->item(0);
            $rendered = $node->nodeName === 'textarea' ? trim($node->textContent)
                : ($node->nodeName === 'select' ? $xpath->evaluate('string(.//option[@selected]/@value)', $node) : $node->getAttribute('value'));
            $this->assertSame($value, $rendered, $field);
        }
        if ($professional) {
            $this->assertSame(1, $xpath->query('//input[@name="specialty_ids[]" and @value="'.$specialty->id.'" and @checked]')->length);
            $this->assertSame('1', $xpath->evaluate('string(//form[@id="profile-edit-form"]//button[@name="update_specialties"][1]/@value)'));
            $this->assertStringContainsString('En el último envío elegiste aplicar la selección', $xpath->document->textContent);
            $this->assertSame(1, $xpath->query('//input[@name="contact_visible" and @type="checkbox" and @checked]')->length);
            $this->assertSame(0, $xpath->query('//input[@name="license_number" and string-length(@value)>0]')->length);
            $data['license_number'] = '';
        }
        $data['website'] = 'https://example.invalid/draft';
        $this->from($url)->put($endpoint, $data)->assertRedirect(route('profile'))->assertSessionHasNoErrors();
        $profile = ($professional ? $user->professionalProfile() : $user->profile())->first();
        $this->assertSame($cityB->id, $profile->municipality_id);
        $this->assertSame('Texto de borrador', $profile->{$professional ? 'description' : 'biography'});
        if ($professional) {
            $this->assertSame('1234567', $profile->license_number);
            $this->assertSame([$specialty->id], $profile->specialties()->pluck('legal_specialties.id')->all());
        }
        // After the error has been consumed, a normal GET honors its explicit state again.
        $this->assertLocation($this->xpath($this->get($url)->assertOk()->getContent()), $first->id, null);
        $this->getJson($editor.'?state_id='.$second->id)->assertJsonPath('municipalities.0.id', $cityB->id);
    }

    #[DataProvider('editors')]
    public function test_explicit_empty_municipality_is_not_restored_after_validation_and_can_be_saved(bool $professional): void
    {
        [$state, , , $city] = $this->geography();
        [$user, $editor, $endpoint, $data] = $this->editorFixture($professional, $city);
        $url = $editor.'?state_id='.$state->id;
        $data['municipality_id'] = '';
        $data['website'] = 'invalid';
        $this->actingAs($user)->from($url)->put($endpoint, $data)->assertRedirect($url)->assertSessionHasErrors('website');
        $old = $this->app['session']->getOldInput();
        $this->assertArrayHasKey('municipality_id', $old);
        $this->assertNull($old['municipality_id']);
        $xpath = $this->xpath($this->get($url)->assertOk()->getContent());
        $this->assertLocation($xpath, $state->id, null);
        $this->assertSame(0, $xpath->query('//select[@id="municipality_id"]/option[@value="'.$city->id.'" and @selected]')->length);
        $this->assertSame($city->id, ($professional ? $user->professionalProfile() : $user->profile())->first()->municipality_id);
        $data['website'] = 'https://example.invalid/draft';
        $this->put($endpoint, $data)->assertRedirect(route('profile'))->assertSessionHasNoErrors();
        $this->assertNull(($professional ? $user->professionalProfile() : $user->profile())->first()->municipality_id);
    }

    #[DataProvider('editors')]
    public function test_normal_get_keeps_explicit_state_even_if_old_input_exists_without_errors(bool $professional): void
    {
        [, $state, , $cityA, $cityB] = $this->geography();
        [$user, $editor] = $this->editorFixture($professional, $cityA);
        $url = $editor.'?state_id='.$state->id;
        $this->actingAs($user);
        foreach ([[], ['municipality_id' => (string) $cityA->id]] as $old) {
            $response = $this->withSession(['_old_input' => $old, 'errors' => new ViewErrorBag])->get($url)->assertOk();
            $xpath = $this->xpath($response->getContent());
            $this->assertLocation($xpath, $state->id, null);
            $this->assertSame(1, $xpath->query('//select[@id="municipality_id"]/option[@value="'.$cityB->id.'"]')->length);
            $this->assertSame(0, $xpath->query('//select[@id="municipality_id"]/option[@value="'.$cityA->id.'"]')->length);
        }
    }

    #[DataProvider('editors')]
    public function test_truly_absent_municipality_keeps_saved_location_after_validation(bool $professional): void
    {
        [$state, , , $city] = $this->geography();
        [$user, $editor, $endpoint, $data] = $this->editorFixture($professional, $city);
        $url = $editor.'?state_id='.$state->id;
        unset($data['municipality_id']);
        $data['website'] = 'invalid';
        $this->actingAs($user)->from($url)->put($endpoint, $data)->assertRedirect($url)->assertSessionHasErrors('website');
        $this->assertArrayNotHasKey('municipality_id', $this->app['session']->getOldInput());
        $this->assertLocation($this->xpath($this->get($url)->assertOk()->getContent()), $state->id, $city->id);
        $data['website'] = 'https://example.invalid/draft';
        $this->put($endpoint, $data)->assertRedirect(route('profile'))->assertSessionHasNoErrors();
        $this->assertSame($city->id, ($professional ? $user->professionalProfile() : $user->profile())->first()->municipality_id);
    }

    #[DataProvider('invalidMunicipalities')]
    public function test_invalid_municipality_is_not_reconstructed_or_replaced_with_saved_location(bool $professional, mixed $invalid): void
    {
        [$state, , , $city] = $this->geography();
        [$user, $editor, $endpoint, $data] = $this->editorFixture($professional, $city);
        $url = $editor.'?state_id='.$state->id;
        $data['municipality_id'] = $invalid;
        $this->actingAs($user)->from($url)->put($endpoint, $data)->assertRedirect($url)->assertSessionHasErrors('municipality_id');
        $xpath = $this->xpath($this->get($url)->assertOk()->getContent());
        $this->assertLocation($xpath, $state->id, null);
        $this->assertSame(1, $xpath->query('//select[@id="municipality_id" and @aria-invalid="true"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="municipality_id-error"]')->length);
        $this->assertSame($city->id, ($professional ? $user->professionalProfile() : $user->profile())->first()->municipality_id);
    }

    public function test_photo_validation_does_not_flash_a_municipality_outside_its_rules(): void
    {
        [$state, , , $city] = $this->geography();
        [$user] = $this->editorFixture(false, $city);
        $url = '/perfil/editar?state_id='.$state->id;
        $this->actingAs($user)->from($url)->post('/perfil/fotografia', ['municipality_id' => null])->assertSessionHasErrors(['photo', 'profile']);
        $this->assertArrayNotHasKey('municipality_id', $this->app['session']->getOldInput());
        $this->assertLocation($this->xpath($this->get($url)->assertOk()->getContent()), $state->id, $city->id);
    }

    public function test_both_existing_edit_routes_return_only_the_public_catalog_without_loading_profiles(): void
    {
        [$first, $second, , $cityA, $cityB] = $this->geography();
        $user = User::factory()->create();
        $service = app(ProfileService::class);
        $service->saveUserProfile($user, $user->id, ['visibility' => 'private', 'biography' => 'Biografía privada', 'municipality_id' => $cityA->id]);
        $service->saveProfessionalProfile($user, $user->id, ['visibility' => 'private', 'headline' => 'Privado', 'license_number' => '1234567', 'contact_email' => 'private@example.test']);
        $before = [$user->profile->getAttributes(), $user->professionalProfile->getAttributes()];
        foreach (['/perfil/editar', '/perfil/profesional/editar'] as $url) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->actingAs($user)->getJson($url.'?state_id='.$second->id);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $response->assertOk()->assertExactJson(['state_id' => $second->id, 'municipalities' => [['id' => $cityB->id, 'name' => $cityB->name]]]);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('Accept', $response->headers->get('Vary'));
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/\b(user_profiles|professional_profiles|professional_profile_specialties|attachments)\b/', $query['query']);
            }
        }
        $this->assertSame($before, [$user->profile()->first()->getAttributes(), $user->professionalProfile()->first()->getAttributes()]);
    }

    public function test_catalog_supports_empty_states_and_alphabetical_municipalities(): void
    {
        [$state, , $empty, $city] = $this->geography();
        $alpha = Municipality::create(['state_id' => $state->id, 'name' => 'Alfa', 'code' => '002']);
        $this->actingAs(User::factory()->create())->getJson('/perfil/editar?state_id='.$empty->id)
            ->assertOk()->assertExactJson(['state_id' => $empty->id, 'municipalities' => []]);
        $this->getJson('/perfil/editar?state_id='.$state->id)->assertExactJson([
            'state_id' => $state->id, 'municipalities' => [['id' => $alpha->id, 'name' => 'Alfa'], ['id' => $city->id, 'name' => $city->name]],
        ]);
    }

    public function test_catalog_rejects_invalid_ids_and_extra_query_fields_without_echoing_them(): void
    {
        [$state] = $this->geography();
        $this->actingAs(User::factory()->create());
        foreach (['', '?state_id=', '?state_id=-1', '?state_id[]=1', '?state_id=999999999', '?state_id='.$state->id.'&biography=PRIVATE_SENTINEL'] as $query) {
            foreach (['/perfil/editar', '/perfil/profesional/editar'] as $url) {
                $this->getJson($url.$query)->assertStatus(422)->assertExactJson(['message' => 'Selecciona un estado válido.'])->assertDontSee('PRIVATE_SENTINEL');
            }
        }
    }

    public function test_catalog_requires_an_authenticated_active_account(): void
    {
        [$state] = $this->geography();
        foreach (['/perfil/editar', '/perfil/profesional/editar'] as $url) {
            $this->getJson($url.'?state_id='.$state->id)->assertUnauthorized();
            $this->get($url.'?state_id='.$state->id)->assertRedirect(route('login'));
        }
        $user = User::factory()->create();
        $user->status = 'suspended';
        $user->save();
        foreach (['/perfil/editar', '/perfil/profesional/editar'] as $url) {
            $this->actingAs($user)->getJson($url.'?state_id='.$state->id)->assertUnauthorized();
        }
    }

    public function test_native_reload_excludes_incompatible_saved_municipality_and_requires_acknowledgement(): void
    {
        [, $state, , $city] = $this->geography();
        $user = User::factory()->create();
        app(ProfileService::class)->saveUserProfile($user, $user->id, ['visibility' => 'private', 'municipality_id' => $city->id]);
        $response = $this->actingAs($user)->get('/perfil/editar?state_id='.$state->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query('//select[@id="municipality_id"]/option[@value="'.$city->id.'"]')->length);
        $this->assertSame(1, $xpath->query('//form[@data-location-form and @method="GET"]//input[@data-location-ack and @required and not(@name)]')->length);
        $this->assertSame(1, $xpath->query('//button[@form="profile-edit-form" and @type="submit"]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="profile-edit-form" and @method="POST"]//input[@name="_method" and @value="PUT"]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="profile-edit-form"]//input[@name="_token"]')->length);
        $response->assertSee('descarta los cambios sin guardar')->assertSee('Guardar primero mis cambios');
        $this->assertSame($city->id, $user->profile()->first()->municipality_id);
    }

    public function test_personal_validation_redirect_restores_draft_and_new_location_state(): void
    {
        [, $state, , $cityA, $cityB] = $this->geography();
        $user = User::factory()->create();
        app(ProfileService::class)->saveUserProfile($user, $user->id, ['visibility' => 'private', 'municipality_id' => $cityA->id, 'biography' => 'Persistida']);
        $this->actingAs($user)->from('/perfil/editar')->put('/perfil', [
            'visibility' => 'private', 'municipality_id' => (string) $cityB->id, 'biography' => 'Borrador sin perder', 'website' => 'invalid',
        ])->assertRedirect('/perfil/editar')->assertSessionHasErrors('website');
        $response = $this->get('/perfil/editar')->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame((string) $state->id, $xpath->evaluate('string(//select[@id="state_id"]/option[@selected]/@value)'));
        $this->assertSame((string) $cityB->id, $xpath->evaluate('string(//select[@id="municipality_id"]/option[@selected]/@value)'));
        $this->assertSame('Borrador sin perder', trim($xpath->evaluate('string(//textarea[@name="biography"])')));
        $this->assertSame('Persistida', $user->profile()->first()->biography);
    }

    public function test_professional_validation_keeps_specialty_intention_and_location_but_never_flashes_credentials(): void
    {
        [, $state, , , $city] = $this->geography();
        $specialty = LegalSpecialty::create(['name' => 'Sintética', 'slug' => 'location-synthetic', 'is_active' => true]);
        $user = User::factory()->create();
        $this->actingAs($user)->from('/perfil/profesional/editar')->put('/perfil/profesional', [
            'visibility' => 'private', 'municipality_id' => (string) $city->id, 'description' => 'Presentación sin perder',
            'contact_visible' => '0', 'contact_email' => 'invalid', 'license_number' => '7654321',
            'specialty_ids' => [(string) $specialty->id], 'update_specialties' => '1',
        ])->assertSessionHasErrors('contact_email')->assertSessionMissing('_old_input.license_number');
        $response = $this->get('/perfil/profesional/editar')->assertOk()->assertDontSee('7654321');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame((string) $state->id, $xpath->evaluate('string(//select[@id="state_id"]/option[@selected]/@value)'));
        $this->assertSame((string) $city->id, $xpath->evaluate('string(//select[@id="municipality_id"]/option[@selected]/@value)'));
        $this->assertSame('Presentación sin perder', trim($xpath->evaluate('string(//textarea[@name="description"])')));
        $this->assertSame(1, $xpath->query('//input[@name="specialty_ids[]" and @checked]')->length);
        $this->assertSame('1', $xpath->evaluate('string(//form[@id="profile-edit-form"]//button[@name="update_specialties"][1]/@value)'));
        $this->assertStringContainsString('En el último envío elegiste aplicar la selección', $xpath->document->textContent);
        $this->assertSame(0, $xpath->query('//input[@name="license_number" and string-length(@value)>0]')->length);
    }

    public function test_municipality_errors_are_associated_with_the_actual_select(): void
    {
        $this->actingAs(User::factory()->create())->from('/perfil/editar')->put('/perfil', ['visibility' => 'private', 'municipality_id' => '-1'])
            ->assertSessionHasErrors('municipality_id');
        $xpath = $this->xpath($this->get('/perfil/editar')->assertOk()->getContent());
        $this->assertSame(1, $xpath->query('//select[@id="municipality_id" and @aria-invalid="true" and contains(@aria-describedby,"municipality_id-error")]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="municipality_id-error" and string-length(normalize-space(.))>0]')->length);
    }

    public function test_saving_selected_catalog_municipality_uses_the_unchanged_put_contract(): void
    {
        [, $state, , , $city] = $this->geography();
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/perfil/editar?state_id='.$state->id)->assertOk();
        $this->put('/perfil', ['visibility' => 'private', 'municipality_id' => (string) $city->id, 'biography' => 'Borrador guardado'])
            ->assertRedirect(route('profile'))->assertSessionHasNoErrors();
        $this->assertSame($city->id, $user->profile()->first()->municipality_id);
        $this->assertSame('Borrador guardado', $user->profile()->first()->biography);
    }
}
