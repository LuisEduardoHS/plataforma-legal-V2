<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Enums\AttentionMode;
use App\Enums\ProfessionalVerificationStatus;
use App\Enums\ProfileVisibility;
use App\Exceptions\ProfileException;
use App\Models\Attachment;
use App\Models\Country;
use App\Models\LegalSpecialty;
use App\Models\Municipality;
use App\Models\ProfessionalProfile;
use App\Models\State;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Attachments\AttachmentService;
use App\Services\Profiles\ProfileService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    private bool $transactionStarted = false;

    private ProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('plataforma_legal_v2_test', DB::connection()->getDatabaseName());
        $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        DB::beginTransaction();
        $this->transactionStarted = true;
        $this->service = app(ProfileService::class);
    }

    protected function tearDown(): void
    {
        try {
            ProfessionalProfile::flushEventListeners();
            if ($this->transactionStarted) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function user(): User
    {
        return User::factory()->create()->refresh();
    }

    private function municipality(): Municipality
    {
        $country = Country::create(['name' => 'País de prueba', 'iso_code' => 'ZZ']);
        $state = State::create(['country_id' => $country->id, 'name' => 'Estado de prueba', 'code' => '01']);

        return Municipality::create(['state_id' => $state->id, 'name' => 'Municipio de prueba', 'code' => '001']);
    }

    private function specialty(string $slug = 'back03-specialty', bool $active = true): LegalSpecialty
    {
        return LegalSpecialty::create(['name' => $slug, 'slug' => $slug, 'is_active' => $active]);
    }

    private function photo(User $owner, string $category = 'image', AttachmentStatus $status = AttachmentStatus::Available): Attachment
    {
        $name = fake()->uuid().'.png';
        $attachment = new Attachment;
        $attachment->forceFill([
            'user_id' => $owner->id, 'original_name' => 'foto.png',
            'storage_name' => $name, 'path' => 'attachments/'.$name, 'disk' => 'attachments',
            'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => 10,
            'sha256' => hash('sha256', $name), 'category' => $category,
            'visibility' => 'private', 'status' => $status,
        ])->save();

        return $attachment;
    }

    public function test_incomplete_profiles_are_private_optional_and_use_a_single_account(): void
    {
        $owner = $this->user();
        $profile = $this->service->saveUserProfile($owner, $owner->id, []);
        $professional = $this->service->saveProfessionalProfile($owner, $owner->id, []);
        $this->assertTrue($owner->profile->is($profile));
        $this->assertTrue($owner->professionalProfile->is($professional));
        $this->assertTrue($profile->user->is($owner));
        $this->assertTrue($professional->user->is($owner));
        $this->assertSame(ProfileVisibility::Private, $profile->visibility);
        $this->assertSame(ProfileVisibility::Private, $professional->visibility);
        $this->assertSame(ProfessionalVerificationStatus::Unverified, $professional->verification_status);
        $this->assertNull($professional->verified_at);
        $this->assertNull($profile->municipality);
        $this->assertNull($profile->username);
        $this->assertNull($professional->attention_mode);
        $this->assertNull($professional->license_number);
        $this->assertFalse($professional->contact_visible);
        $this->assertSame(1, User::count());
        $this->assertSame([], $owner->getRoleNames()->all());
    }

    public function test_user_profile_upsert_normalizes_username_preserves_id_and_does_not_duplicate_account_data(): void
    {
        $owner = $this->user();
        $name = $owner->name;
        $email = $owner->email;
        $a = $this->service->saveUserProfile($owner, $owner->id, ['username' => '  Legal_User  ', 'display_name' => 'Nombre público']);
        $b = $this->service->saveUserProfile($owner, $owner->id, ['username' => 'LEGAL_USER', 'biography' => 'Biografía', 'website' => 'https://example.test']);
        $this->assertSame($a->id, $b->id);
        $this->assertSame('legal_user', $b->username);
        $this->assertSame('Nombre público', $b->display_name);
        $this->assertSame('Biografía', $b->biography);
        $this->assertSame(1, $owner->profile()->count());
        $this->assertSame($name, $owner->fresh()->name);
        $this->assertSame($email, $owner->fresh()->email);
        $this->assertTrue($this->service->userProfile($owner, $owner->id)->is($b));
        $this->service->saveUserProfile($owner, $owner->id, ['username' => null, 'display_name' => null, 'biography' => null, 'website' => null]);
        $this->assertNull($b->fresh()->username);
        $this->assertNull($b->fresh()->display_name);
    }

    public function test_username_is_unique_but_multiple_incomplete_profiles_can_have_null_usernames(): void
    {
        $a = $this->user();
        $b = $this->user();
        $this->service->saveUserProfile($a, $a->id, []);
        $this->service->saveUserProfile($b, $b->id, []);
        $this->service->saveUserProfile($a, $a->id, ['username' => 'usuario_legal']);
        $this->expectException(ValidationException::class);
        $this->service->saveUserProfile($b, $b->id, ['username' => 'USUARIO_LEGAL']);
    }

    public static function invalidUserData(): array
    {
        return [
            'short username' => [['username' => 'ab']],
            'spaces' => [['username' => 'legal user']],
            'traversal' => [['username' => '../usuario']],
            'non ASCII' => [['username' => 'jurídico']],
            'leading digit' => [['username' => '1usuario']],
            'long name' => [['display_name' => str_repeat('a', 151)]],
            'long biography' => [['biography' => str_repeat('a', 2001)]],
            'javascript website' => [['website' => 'javascript:alert(1)']],
            'invalid visibility' => [['visibility' => 'unlisted']],
            'missing municipality' => [['municipality_id' => -1]],
        ];
    }

    #[DataProvider('invalidUserData')]
    public function test_invalid_user_fields_do_not_create_a_profile(array $data): void
    {
        $owner = $this->user();
        try {
            $this->service->saveUserProfile($owner, $owner->id, $data);
            $this->fail('Se aceptaron datos inválidos.');
        } catch (ValidationException) {
            $this->assertSame(0, UserProfile::count());
        }
    }

    public function test_location_uses_one_municipality_and_derives_state_and_country_for_both_profiles(): void
    {
        $owner = $this->user();
        $municipality = $this->municipality();
        $data = ['municipality_id' => $municipality->id, 'visibility' => 'public'];
        $profile = $this->service->saveUserProfile($owner, $owner->id, $data);
        $professional = $this->service->saveProfessionalProfile($owner, $owner->id, $data);
        foreach ([$profile, $professional] as $record) {
            $this->assertTrue($record->municipality->is($municipality));
            $this->assertSame('ZZ', $record->municipality->state->country->iso_code);
        }
        foreach (['publicUserProfile', 'publicProfessionalProfile'] as $method) {
            $location = $this->service->$method($owner->id)['location'];
            $this->assertSame($municipality->id, $location['municipality']['id']);
            $this->assertSame($municipality->state_id, $location['state']['id']);
            $this->assertSame('ZZ', $location['country']['iso_code']);
        }
        $this->service->saveUserProfile($owner, $owner->id, ['municipality_id' => null]);
        $this->service->saveProfessionalProfile($owner, $owner->id, ['municipality_id' => null]);
        $this->assertNull($profile->fresh()->municipality);
        $this->assertNull($professional->fresh()->municipality);
    }

    public function test_professional_details_are_updated_in_place_and_license_is_encrypted_and_hidden(): void
    {
        $owner = $this->user();
        $professional = $this->service->saveProfessionalProfile($owner, $owner->id, [
            'headline' => 'Abogada laboral', 'description' => 'Asesoría profesional',
            'license_number' => '00123456', 'years_experience' => 0, 'attention_mode' => 'hybrid',
            'contact_email' => 'contacto@example.test', 'contact_phone' => '+52 81 1234 5678',
        ]);
        $updated = $this->service->saveProfessionalProfile($owner, $owner->id, ['years_experience' => 8]);
        $this->assertSame($professional->id, $updated->id);
        $this->assertSame('00123456', $updated->license_number);
        $this->assertSame(8, $updated->years_experience);
        $this->assertSame(AttentionMode::Hybrid, $updated->attention_mode);
        $stored = DB::table('professional_profiles')->where('id', $updated->id)->value('license_number');
        $this->assertNotSame('00123456', $stored);
        $this->assertStringNotContainsString('00123456', $stored);
        foreach (['license_number', 'contact_email', 'contact_phone', 'user'] as $hidden) {
            $updated->load('user');
            $this->assertArrayNotHasKey($hidden, $updated->toArray());
        }
        $this->assertTrue($this->service->professionalProfile($owner, $owner->id)->is($updated));
        $this->assertSame(ProfessionalVerificationStatus::Unverified, $updated->verification_status);
    }

    public static function invalidProfessionalData(): array
    {
        return [
            'credential letters' => [['license_number' => 'ABCD1234']],
            'credential too short' => [['license_number' => '123']],
            'negative experience' => [['years_experience' => -1]],
            'implausible experience' => [['years_experience' => 81]],
            'fractional experience' => [['years_experience' => 1.5]],
            'invalid mode' => [['attention_mode' => 'anywhere']],
            'invalid email' => [['contact_email' => 'not an email']],
            'invalid phone' => [['contact_phone' => '<script>']],
            'invalid flag' => [['contact_visible' => 'yes']],
            'invalid website' => [['website' => 'ftp://example.test']],
            'invalid location' => [['municipality_id' => -1]],
            'invalid specialties' => [['specialty_ids' => ['not an id']]],
            'null specialties' => [['specialty_ids' => null]],
        ];
    }

    #[DataProvider('invalidProfessionalData')]
    public function test_invalid_professional_fields_are_rejected(array $data): void
    {
        $owner = $this->user();
        try {
            $this->service->saveProfessionalProfile($owner, $owner->id, $data);
            $this->fail('Se aceptaron datos profesionales inválidos.');
        } catch (ValidationException) {
            $this->assertSame(0, ProfessionalProfile::count());
        }
    }

    public function test_specialties_are_normalized_unique_and_sync_preserves_profile_identity(): void
    {
        $owner = $this->user();
        $first = $this->specialty('primera');
        $second = $this->specialty('segunda');
        $profile = $this->service->saveProfessionalProfile($owner, $owner->id, ['specialty_ids' => [$second->id, $first->id]]);
        $this->assertSame(2, $profile->specialties()->count());
        $this->assertTrue($first->professionalProfiles()->firstOrFail()->is($profile));
        $before = DB::table('professional_profile_specialties')->where('professional_profile_id', $profile->id)->orderBy('legal_specialty_id')->get()->toArray();
        $this->service->syncSpecialties($owner, $owner->id, [$first->id, $second->id]);
        $this->assertEquals($before, DB::table('professional_profile_specialties')->where('professional_profile_id', $profile->id)->orderBy('legal_specialty_id')->get()->toArray());
        $updated = $this->service->syncSpecialties($owner, $owner->id, [$second->id]);
        $this->assertSame($profile->id, $updated->id);
        $this->assertSame([$second->id], $updated->specialties()->pluck('legal_specialties.id')->all());
        $this->service->syncSpecialties($owner, $owner->id, []);
        $this->assertSame(0, $profile->specialties()->count());
    }

    public function test_missing_inactive_and_repeated_specialties_are_rejected_without_changing_existing_links(): void
    {
        $owner = $this->user();
        $active = $this->specialty();
        $inactive = $this->specialty('inactive', false);
        $profile = $this->service->syncSpecialties($owner, $owner->id, [$active->id]);
        foreach ([[-1], [$inactive->id], [$active->id, $active->id]] as $ids) {
            try {
                $this->service->saveProfessionalProfile($owner, $owner->id, ['headline' => 'No persistir', 'specialty_ids' => $ids]);
                $this->fail('Se aceptaron especialidades inválidas.');
            } catch (ValidationException) {
                $this->assertSame([$active->id], $profile->specialties()->pluck('legal_specialties.id')->all());
                $this->assertNull($profile->fresh()->headline);
            }
        }
        $active->update(['is_active' => false]);
        $this->service->saveProfessionalProfile($owner, $owner->id, ['description' => 'Edición válida']);
        $this->assertSame(1, $profile->specialties()->count());
    }

    public function test_public_data_respects_visibility_contact_consent_and_active_specialties(): void
    {
        $owner = $this->user();
        $specialty = $this->specialty();
        $profile = $this->service->saveUserProfile($owner, $owner->id, ['visibility' => 'public']);
        $photo = $this->photo($owner);
        $this->service->saveUserProfile($owner, $owner->id, ['photo_attachment_id' => $photo->id]);
        $this->service->saveProfessionalProfile($owner, $owner->id, [
            'visibility' => 'public', 'license_number' => '12345678',
            'contact_email' => 'privado@example.test', 'contact_phone' => '+52 12345678',
            'specialty_ids' => [$specialty->id],
        ]);
        $userData = $this->service->publicUserProfile($owner->id);
        $this->assertSame($owner->name, $userData['display_name']);
        foreach (['photo_attachment_id', 'photo', 'user', 'email', 'password'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $userData);
        }
        $public = $this->service->publicProfessionalProfile($owner->id);
        $this->assertNull($public['contact']);
        $this->assertSame('unverified', $public['verification_status']);
        $this->assertArrayNotHasKey('license_number', $public);
        $this->assertCount(1, $public['specialties']);
        $this->service->saveProfessionalProfile($owner, $owner->id, ['contact_visible' => true]);
        $this->assertSame('privado@example.test', $this->service->publicProfessionalProfile($owner->id)['contact']['email']);
        $specialty->update(['is_active' => false]);
        $this->assertSame([], $this->service->publicProfessionalProfile($owner->id)['specialties']);
        $this->assertTrue($profile->fresh()->photo->is($photo));
    }

    public function test_private_profiles_and_inactive_owners_are_not_publicly_readable(): void
    {
        $owner = $this->user();
        $this->service->saveUserProfile($owner, $owner->id, []);
        $this->service->saveProfessionalProfile($owner, $owner->id, []);
        foreach (['publicUserProfile', 'publicProfessionalProfile'] as $method) {
            try {
                $this->service->$method($owner->id);
                $this->fail('Se expuso un perfil privado.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
        $this->service->saveUserProfile($owner, $owner->id, ['visibility' => 'public']);
        $this->service->saveProfessionalProfile($owner, $owner->id, ['visibility' => 'public']);
        DB::table('users')->where('id', $owner->id)->update(['status' => 'suspended']);
        foreach (['publicUserProfile', 'publicProfessionalProfile'] as $method) {
            try {
                $this->service->$method($owner->id);
                $this->fail('Se expuso una cuenta suspendida.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    public static function inactiveStates(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    #[DataProvider('inactiveStates')]
    public function test_inactive_actors_cannot_edit_or_read_private_profiles_with_a_stale_model(string $status): void
    {
        $owner = $this->user();
        $this->service->saveUserProfile($owner, $owner->id, []);
        $this->service->saveProfessionalProfile($owner, $owner->id, []);
        DB::table('users')->where('id', $owner->id)->update(['status' => $status]);
        $this->assertTrue($owner->isActive());
        foreach (['saveUserProfile', 'saveProfessionalProfile', 'syncSpecialties', 'userProfile', 'professionalProfile'] as $method) {
            try {
                $this->service->$method($owner, $owner->id, []);
                $this->fail('Se admitió acceso de una cuenta inactiva.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_other_users_cannot_create_modify_or_read_private_profiles(): void
    {
        $owner = $this->user();
        $other = $this->user();
        foreach (['saveUserProfile', 'saveProfessionalProfile'] as $method) {
            try {
                $this->service->$method($other, $owner->id, []);
                $this->fail('Se creó un perfil ajeno.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $this->service->saveUserProfile($owner, $owner->id, []);
        $this->service->saveProfessionalProfile($owner, $owner->id, []);
        foreach (['saveUserProfile', 'saveProfessionalProfile', 'syncSpecialties', 'userProfile', 'professionalProfile'] as $method) {
            try {
                $this->service->$method($other, $owner->id, []);
                $this->fail('Se admitió acceso ajeno.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public static function forbiddenFields(): array
    {
        return [
            ['verification_status', 'verified'], ['verified_at', '2026-10-23 00:00:00'],
            ['user_id', 1], ['roles', ['admin']], ['permissions', ['users.manage']],
            ['status', 'active'], ['country_id', 1], ['state_id', 1], ['email', 'otro@example.test'],
        ];
    }

    #[DataProvider('forbiddenFields')]
    public function test_internal_account_and_location_fields_cannot_be_assigned(string $field, mixed $value): void
    {
        $owner = $this->user();
        foreach (['saveUserProfile', 'saveProfessionalProfile'] as $method) {
            try {
                $this->service->$method($owner, $owner->id, [$field => $value]);
                $this->fail('Se permitió modificar un campo interno.');
            } catch (ValidationException) {
                $this->assertSame([], $owner->fresh()->getRoleNames()->all());
            }
        }
        $this->assertSame(0, UserProfile::count());
        $this->assertSame(0, ProfessionalProfile::count());
    }

    public function test_profiles_reject_unsafe_mass_assignment(): void
    {
        foreach ([UserProfile::class, ProfessionalProfile::class] as $model) {
            try {
                new $model(['user_id' => 1, 'verification_status' => 'verified']);
                $this->fail('Se permitió asignación masiva.');
            } catch (MassAssignmentException) {
                $this->assertTrue(true);
            }
        }
    }

    public static function invalidPhotos(): array
    {
        return [
            ['image', AttachmentStatus::PendingUpload],
            ['image', AttachmentStatus::PendingDeletion],
            ['image', AttachmentStatus::Deleted],
            ['document', AttachmentStatus::Available],
            ['video', AttachmentStatus::Available],
        ];
    }

    #[DataProvider('invalidPhotos')]
    public function test_photo_must_be_an_available_image(string $category, AttachmentStatus $status): void
    {
        $owner = $this->user();
        $photo = $this->photo($owner, $category, $status);
        $this->expectException(ValidationException::class);
        $this->service->saveUserProfile($owner, $owner->id, ['photo_attachment_id' => $photo->id]);
    }

    public function test_photo_requires_owner_and_can_be_replaced_or_removed_without_weakening_attachment_policy(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $a = $this->photo($owner);
        $b = $this->photo($owner);
        $foreign = $this->photo($other);
        $profile = $this->service->saveUserProfile($owner, $owner->id, ['photo_attachment_id' => $a->id]);
        foreach ([$foreign->id, -1] as $id) {
            try {
                $this->service->saveUserProfile($owner, $owner->id, ['photo_attachment_id' => $id]);
                $this->fail('Se aceptó una foto ajena o inexistente.');
            } catch (ValidationException) {
                $this->assertSame($a->id, $profile->fresh()->photo_attachment_id);
            }
        }
        $this->service->saveUserProfile($owner, $owner->id, ['photo_attachment_id' => $b->id]);
        $this->assertSame($b->id, $profile->fresh()->photo_attachment_id);
        $this->service->saveUserProfile($owner, $owner->id, ['photo_attachment_id' => null]);
        $this->assertNull($profile->fresh()->photo);
        $this->assertSame(AttachmentStatus::Available, $a->fresh()->status);
        $this->expectException(AuthorizationException::class);
        app(AttachmentService::class)->metadata($other, $a->id);
    }

    public function test_existing_admin_permission_allows_editing_without_verification_or_attachment_bypass(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = $this->user();
        $admin = $this->user();
        $admin->assignRole('admin');
        $photo = $this->photo($admin);
        $profile = $this->service->saveUserProfile($admin, $owner->id, ['display_name' => 'Edición autorizada']);
        $professional = $this->service->saveProfessionalProfile($admin, $owner->id, ['headline' => 'Perfil profesional']);
        $this->assertSame($owner->id, $profile->user_id);
        $this->assertSame('Edición autorizada', $this->service->userProfile($admin, $owner->id)->display_name);
        $this->assertSame(ProfessionalVerificationStatus::Unverified, $professional->verification_status);
        $this->assertSame([], $owner->fresh()->getRoleNames()->all());
        try {
            $this->service->saveProfessionalProfile($admin, $owner->id, ['verification_status' => 'verified']);
            $this->fail('El permiso de gestión no permite verificar.');
        } catch (ValidationException) {
            $this->assertSame(ProfessionalVerificationStatus::Unverified, $professional->fresh()->verification_status);
        }
        $this->expectException(ValidationException::class);
        $this->service->saveUserProfile($admin, $owner->id, ['photo_attachment_id' => $photo->id]);
    }

    public function test_material_changes_reset_verification_but_noop_and_contact_preferences_do_not(): void
    {
        $owner = $this->user();
        $a = $this->specialty('a');
        $b = $this->specialty('b');
        $profile = $this->service->saveProfessionalProfile($owner, $owner->id, [
            'headline' => 'Título', 'license_number' => '12345678', 'specialty_ids' => [$a->id],
        ]);
        $profile->forceFill(['verification_status' => ProfessionalVerificationStatus::Verified, 'verified_at' => now()])->save();
        $this->service->saveProfessionalProfile($owner, $owner->id, ['headline' => 'Título', 'license_number' => '12345678', 'specialty_ids' => [$a->id]]);
        $this->assertSame(ProfessionalVerificationStatus::Verified, $profile->fresh()->verification_status);
        $this->service->saveProfessionalProfile($owner, $owner->id, ['contact_visible' => true, 'visibility' => 'public']);
        $this->assertSame(ProfessionalVerificationStatus::Verified, $profile->fresh()->verification_status);
        $this->service->syncSpecialties($owner, $owner->id, [$b->id]);
        $this->assertSame(ProfessionalVerificationStatus::Unverified, $profile->fresh()->verification_status);
        $this->assertNull($profile->fresh()->verified_at);
        $profile->forceFill(['verification_status' => ProfessionalVerificationStatus::Pending])->save();
        $this->service->saveProfessionalProfile($owner, $owner->id, ['license_number' => '87654321']);
        $this->assertSame(ProfessionalVerificationStatus::Unverified, $profile->fresh()->verification_status);
    }

    public function test_mysql_protects_one_to_one_username_foreign_keys_and_verification_integrity(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $municipality = $this->municipality();
        $photo = $this->photo($owner);
        $profile = $this->service->saveUserProfile($owner, $owner->id, ['username' => 'unico', 'municipality_id' => $municipality->id, 'photo_attachment_id' => $photo->id]);
        $professional = $this->service->saveProfessionalProfile($owner, $owner->id, []);
        $cases = [
            fn () => DB::table('user_profiles')->insert(['user_id' => $owner->id]),
            fn () => DB::table('professional_profiles')->insert(['user_id' => $owner->id]),
            fn () => DB::table('user_profiles')->insert(['user_id' => $other->id, 'username' => 'UNICO']),
            fn () => DB::table('user_profiles')->insert(['user_id' => DB::table('users')->max('id') + 1000]),
            fn () => DB::table('professional_profiles')->where('id', $professional->id)->update(['municipality_id' => -1]),
            fn () => DB::table('user_profiles')->where('id', $profile->id)->update(['photo_attachment_id' => DB::table('attachments')->max('id') + 1000]),
            fn () => $municipality->delete(),
            fn () => $photo->delete(),
            fn () => $owner->delete(),
            fn () => DB::table('professional_profiles')->where('id', $professional->id)->update(['verification_status' => 'verified']),
            fn () => DB::table('professional_profiles')->where('id', $professional->id)->update(['verified_at' => now()]),
            fn () => DB::table('professional_profiles')->where('id', $professional->id)->update(['verification_status' => 'invented']),
        ];
        foreach ($cases as $operation) {
            try {
                $operation();
                $this->fail('MySQL permitió violar una restricción.');
            } catch (QueryException $error) {
                $this->assertContains((int) $error->errorInfo[1], [1062, 1451, 1452, 3819, 1265, 1264]);
            }
        }
    }

    public function test_mysql_pivot_protects_unique_pairs_references_and_cascade_cleanup(): void
    {
        $owner = $this->user();
        $specialty = $this->specialty();
        $profile = $this->service->syncSpecialties($owner, $owner->id, [$specialty->id]);
        foreach ([
            fn () => $profile->specialties()->attach($specialty->id),
            fn () => $profile->specialties()->attach($specialty->id + 1000),
            fn () => DB::table('professional_profile_specialties')->insert(['professional_profile_id' => $profile->id + 1000, 'legal_specialty_id' => $specialty->id]),
            fn () => $specialty->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('La tabla pivote perdió integridad.');
            } catch (QueryException $error) {
                $this->assertContains((int) $error->errorInfo[1], [1062, 1451, 1452]);
            }
        }
        $profile->delete();
        $this->assertSame(0, DB::table('professional_profile_specialties')->where('professional_profile_id', $profile->id)->count());
        $this->assertTrue($specialty->fresh()->exists);
    }

    public function test_profile_and_specialties_roll_back_together_when_database_write_fails(): void
    {
        $owner = $this->user();
        $a = $this->specialty('old');
        $b = $this->specialty('new');
        $profile = $this->service->saveProfessionalProfile($owner, $owner->id, ['headline' => 'Original', 'specialty_ids' => [$a->id]]);
        ProfessionalProfile::saved(fn () => DB::table('legal_specialties')->where('id', $b->id)->delete());
        try {
            $this->service->saveProfessionalProfile($owner, $owner->id, ['headline' => 'No persistir', 'specialty_ids' => [$b->id]]);
            $this->fail('Se esperaba un fallo de integridad.');
        } catch (ProfileException $error) {
            $this->assertStringNotContainsString('SQL', $error->getMessage());
            $this->assertSame('Original', $profile->fresh()->headline);
            $this->assertSame([$a->id], $profile->specialties()->pluck('legal_specialties.id')->all());
            $this->assertNotNull($b->fresh());
        }
    }
}
