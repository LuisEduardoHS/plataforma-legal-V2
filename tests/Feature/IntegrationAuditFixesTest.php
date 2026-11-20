<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Country;
use App\Models\LegalSpecialty;
use App\Models\Municipality;
use App\Models\ProfessionalProfile;
use App\Models\State;
use App\Models\Tag;
use App\Models\User;
use App\Services\Community\CommunityInteractionService;
use App\Services\Community\PostService;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Services\Profiles\ProfileService;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IntegrationAuditFixesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Storage::fake('attachments');
        Event::listen('eloquent.creating: '.Attachment::class, function () {
            $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() db')->db);
            $root = str_replace('\\', '/', Storage::disk(config('attachments.disk'))->path(''));
            $this->assertStringStartsWith(str_replace('\\', '/', storage_path('framework/testing/disks/attachments')).'/', $root);
        });
    }

    protected function tearDown(): void
    {
        try {
            Event::forget(QueryExecuted::class);
            Event::forget('eloquent.creating: '.User::class);
            Event::forget('eloquent.creating: '.Attachment::class);
            Event::forget('eloquent.updating: '.ProfessionalProfile::class);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function member(): User
    {
        $user = User::factory()->create()->refresh();
        foreach (['posts.create', 'posts.update.own', 'posts.delete.own', 'comments.create'] as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        return $user;
    }

    private function document(User $owner): Attachment
    {
        $content = 'Archivo sintético de regresión, exclusivo de testing.';
        $name = Str::uuid().'.txt';
        $file = new Attachment;
        $file->forceFill(['user_id' => $owner->id, 'original_name' => 'prueba.txt', 'storage_name' => $name,
            'path' => 'attachments/'.$name, 'disk' => 'attachments', 'mime_type' => 'text/plain', 'extension' => 'txt',
            'category' => 'document', 'visibility' => 'private', 'status' => 'available',
            'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content)])->save();
        Storage::disk('attachments')->put($file->path, $content);

        return $file;
    }

    public static function sqlCases(): array
    {
        $cases = [];
        foreach (['registration-validation', 'registration-insert', 'login', 'persisted-account'] as $stage) {
            foreach ([true, false] as $debug) {
                foreach ([true, false] as $json) {
                    $cases[$stage.'-'.(int) $debug.'-'.(int) $json] = [$stage, $debug, $json];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('sqlCases')]
    public function test_sql_failures_are_generic_in_authentication_and_persisted_account_checks(string $stage, bool $debug, bool $json): void
    {
        config(['app.debug' => $debug]);
        $actor = User::factory()->create();
        $failure = fn () => new QueryException('mysql', 'select private_sql where email = ?',
            ['private-binding', 'secret@example.test', 'private-password', 'private-token'], new \PDOException('private-diagnostic'));
        $logs = [];
        Log::listen(function ($event) use (&$logs) {
            $logs[] = [$event->message, $event->context];
        });
        if ($stage === 'registration-insert') {
            Role::create(['name' => 'user', 'guard_name' => 'web']);
            Event::listen('eloquent.creating: '.User::class, fn () => throw $failure());
        } else {
            Event::listen(QueryExecuted::class, function ($event) use ($failure) {
                if (str_contains($event->sql, 'users')) {
                    throw $failure();
                }
            });
        }
        $headers = $json ? ['Accept' => 'application/json'] : [];
        if ($stage === 'persisted-account') {
            $response = $this->actingAs($actor)->get('/configuracion', $headers);
        } elseif ($stage === 'login') {
            $response = $this->post('/iniciar-sesion', ['email' => $actor->email, 'password' => 'wrong-password'], $headers);
        } else {
            $response = $this->post('/registro', ['name' => 'Nueva cuenta', 'email' => 'new@example.test',
                'password' => 'Registration-password-2026!', 'password_confirmation' => 'Registration-password-2026!'], $headers);
        }
        $response->assertStatus(503);
        foreach (['private_sql', 'private-binding', 'secret@example.test', 'private-password', 'private-token', 'private-diagnostic', 'Registration-password-2026!', 'stack trace'] as $secret) {
            $response->assertDontSee($secret);
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNotEmpty(array_filter($logs, fn ($log) => $log[0] === 'database.operation_failed'));
        $this->assertStringNotContainsString('private-binding', json_encode($logs));
    }

    public function test_ordinary_validation_and_programming_exceptions_keep_their_meaning(): void
    {
        $this->postJson('/registro', ['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
        config(['app.debug' => true]);
        Role::create(['name' => 'user', 'guard_name' => 'web']);
        Event::listen('eloquent.creating: '.User::class, fn () => throw new \RuntimeException('programming-regression-marker'));
        $this->postJson('/registro', ['name' => 'Prueba', 'email' => 'normal@example.test', 'password' => 'Registration-password-2026!',
            'password_confirmation' => 'Registration-password-2026!'])->assertStatus(500)->assertSee('programming-regression-marker');
    }

    public static function validationStages(): array
    {
        return [['form'], ['service']];
    }

    #[DataProvider('validationStages')]
    public function test_empty_professional_specialties_survive_both_validation_layers(string $stage): void
    {
        $actor = $this->member();
        $specialty = LegalSpecialty::create(['name' => 'Especialidad sintética', 'slug' => (string) Str::uuid()]);
        $historic = LegalSpecialty::create(['name' => 'Especialidad histórica', 'slug' => (string) Str::uuid()]);
        $ids = [$specialty->id, $historic->id];
        $profile = app(ProfileService::class)->saveProfessionalProfile($actor, $actor->id, ['headline' => 'Original', 'specialty_ids' => $ids]);
        $historic->update(['is_active' => false]);
        $payload = ['visibility' => 'private', 'contact_visible' => '0', 'update_specialties' => '1', 'headline' => 'Original'];
        $payload += $stage === 'form' ? ['years_experience' => 81] : ['license_number' => '01234567'];
        $this->actingAs($actor);
        if ($stage === 'service') {
            $payload['headline'] = 'Cambio que debe revertirse';
            Event::listen('eloquent.updating: '.ProfessionalProfile::class, fn () => throw new QueryException('mysql', 'UPDATE synthetic_profile_failure', [], new \PDOException('synthetic failure')));
            $payload['profile_version'] = $this->get('/perfil/profesional/editar')->assertOk()->viewData('editorVersion');
            $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
            $pending = $this->postJson(route('profile.professional.prepare'), $payload)->assertOk()->json('pending_change');
            $payload = ['pending_change' => $pending, 'confirm_material' => '1'];
        }
        $this->actingAs($actor)->from('/perfil/profesional/editar')->put('/perfil/profesional', $payload)
            ->assertRedirect('/perfil/profesional/editar')->assertSessionHasErrors()
            ->assertSessionHasInput('specialty_ids', [])->assertSessionHasInput('update_specialties');
        $this->assertNull(session()->getOldInput('license_number'));
        Event::forget('eloquent.updating: '.ProfessionalProfile::class);
        $this->assertSame('Original', $profile->fresh()->headline);
        $this->assertSame($ids, $profile->fresh()->specialties->pluck('id')->all());
        $html = $this->get('/perfil/profesional/editar')->assertOk()->assertSee('Guardar datos y aplicar selección')->getContent();
        $this->assertSame(0, $this->dom($html)->query('//input[@name="specialty_ids[]" and @checked]')->length);
        // Absence of apply intention preserves historical relationships.
        $this->put('/perfil/profesional', ['visibility' => 'private', 'contact_visible' => '0', 'headline' => 'Sin cambio de especialidades'])->assertRedirect('/perfil');
        $this->assertSame($ids, $profile->fresh()->specialties->pluck('id')->all());
    }

    public static function specialtyIntentions(): array
    {
        return [['1'], ['0']];
    }

    #[DataProvider('specialtyIntentions')]
    public function test_professional_domain_validation_flashes_the_normalized_original_request(string $intent): void
    {
        $actor = $this->member();
        $specialty = LegalSpecialty::create(['name' => 'Especialidad de regresión', 'slug' => (string) Str::uuid()]);
        $profile = app(ProfileService::class)->saveProfessionalProfile($actor, $actor->id, ['headline' => 'Original', 'specialty_ids' => [$specialty->id]]);
        $country = Country::create(['name' => 'País de prueba', 'iso_code' => 'ZZ']);
        $state = State::create(['country_id' => $country->id, 'name' => 'Estado de prueba', 'code' => '01']);
        $municipality = Municipality::create(['state_id' => $state->id, 'name' => 'Municipio de prueba', 'code' => '001']);
        $armed = true;
        Event::listen(QueryExecuted::class, function ($event) use (&$armed, $municipality) {
            // The FormRequest sees the row; the service's independent exists
            // validation then observes its removal. Only this testing fixture.
            if ($armed && str_contains($event->sql, 'count(*)') && str_contains($event->sql, 'municipalities')) {
                $armed = false;
                DB::table('municipalities')->where('id', $municipality->id)->delete();
            }
        });
        $this->actingAs($actor)->from('/perfil/profesional/editar')->put('/perfil/profesional', [
            'headline' => 'Cambio inválido', 'visibility' => 'private', 'contact_visible' => '0', 'update_specialties' => $intent, 'specialty_ids' => [],
            'municipality_id' => $municipality->id,
        ])->assertRedirect('/perfil/profesional/editar')->assertSessionHasErrors('municipality_id')
            ->assertSessionHasInput('specialty_ids', [])->assertSessionHasInput('update_specialties', $intent);
        $this->assertFalse($armed);
        $this->assertSame('Original', $profile->fresh()->headline);
        $this->assertSame([$specialty->id], $profile->fresh()->specialties->pluck('id')->all());
        $html = $this->get('/perfil/profesional/editar')->assertOk()->getContent();
        $this->assertSame($intent, $this->dom($html)->evaluate('string(//form[@id="profile-edit-form"]//button[@name="update_specialties"][1]/@value)'));
    }

    #[DataProvider('validationStages')]
    public function test_empty_community_lists_keep_original_version_and_do_not_delete_files(string $stage): void
    {
        $actor = $this->member();
        $tag = Tag::create(['name' => 'Etiqueta sintética', 'slug' => (string) Str::uuid()]);
        $file = $this->document($actor);
        $post = app(PostService::class)->create($actor, ['content' => 'Original', 'tag_ids' => [$tag->id], 'attachment_ids' => [$file->id]]);
        $payload = ['content' => $stage === 'form' ? str_repeat('x', 10001) : ' ', 'visibility' => 'private',
            'replace_tags' => '1', 'replace_attachments' => '1', 'intent' => $stage === 'form' ? 'save' : 'publish', 'version' => $post->version];
        $url = '/comunidad/publicaciones/'.$post->id.'/editar';
        $this->actingAs($actor)->from($url)->put('/comunidad/publicaciones/'.$post->id, $payload)->assertRedirect($url)
            ->assertSessionHasErrors()->assertSessionHasInput('tag_ids', [])->assertSessionHasInput('attachment_ids', [])
            ->assertSessionHasInput('replace_tags', '1')->assertSessionHasInput('replace_attachments', '1')
            ->assertSessionHasInput('version', (string) $post->version);
        $this->assertSame($post->version, $post->fresh()->version);
        $this->assertSame('Original', $post->fresh()->content);
        $this->assertSame([$file->id], $post->fresh()->attachments->pluck('id')->all());
        Storage::disk('attachments')->assertExists($file->path);
        $html = $this->get($url)->assertOk()->getContent();
        $xpath = $this->dom($html);
        $this->assertSame(0, $xpath->query('//input[@name="tag_ids[]" and @checked]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="attachment_ids[]" and @checked]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="community-draft" and @aria-invalid="true"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="community-content-error"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-community-errors and @role="alert"]')->length);
    }

    public function test_settings_use_real_identity_links_and_disable_unimplemented_controls(): void
    {
        $actor = User::factory()->create(['name' => '<script>Cuenta real</script>', 'email' => 'own@example.test']);
        $response = $this->actingAs($actor)->get('/configuracion')->assertOk()->assertSee('&lt;script&gt;Cuenta real&lt;/script&gt;', false)
            ->assertSee('own@example.test')->assertSee(route('profile.edit'))->assertSee(route('profile.professional.edit'))
            ->assertSee(route('logout'))->assertDontSee('Juanito')->assertDontSee('juanitoperez')->assertDontSee('triggerToast')
            ->assertDontSee('2FA activado')->assertDontSee('Sesión de Android finalizada');
        $xpath = $this->dom($response->getContent());
        $this->assertGreaterThan(0, $xpath->query('//*[@data-future-setting and @disabled]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-future-setting and not(@disabled)]')->length);
        $this->post('/cerrar-sesion')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_attachment_domain_errors_keep_empty_groups_and_describe_the_file_control(): void
    {
        $actor = $this->member();
        $file = $this->document($actor);
        $tag = Tag::create(['name' => 'Etiqueta original', 'slug' => (string) Str::uuid()]);
        $post = app(PostService::class)->create($actor, ['content' => 'Original', 'tag_ids' => [$tag->id], 'attachment_ids' => [$file->id]]);
        $url = '/comunidad/publicaciones/'.$post->id.'/editar';
        $this->actingAs($actor)->from($url)->put('/comunidad/publicaciones/'.$post->id, [
            'content' => 'Texto válido', 'visibility' => 'private', 'intent' => 'save', 'version' => $post->version,
            'replace_tags' => '1', 'replace_attachments' => '1',
            'uploads' => [UploadedFile::fake()->createWithContent('false.png', 'Texto sintético que no es una imagen PNG.')],
        ])->assertRedirect($url)->assertSessionHasErrors('file')->assertSessionHasInput('tag_ids', [])
            ->assertSessionHasInput('attachment_ids', [])->assertSessionHasInput('version', (string) $post->version);
        $xpath = $this->dom($this->get($url)->assertOk()->getContent());
        $this->assertSame(1, $xpath->query('//*[@id="community-uploads" and @aria-invalid="true"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="community-uploads-error"]')->length);
        $this->assertSame(0, $xpath->query('//input[(@name="tag_ids[]" or @name="attachment_ids[]") and @checked]')->length);
        $this->assertSame($post->version, $post->fresh()->version);
        $this->assertSame(1, Attachment::where('user_id', $actor->id)->count());
        Storage::disk('attachments')->assertExists($file->path);
    }

    public function test_home_has_real_public_content_and_no_demo_activity_or_private_posts(): void
    {
        $actor = $this->member();
        $other = $this->member();
        $posts = app(PostService::class);
        $public = $posts->create($other, ['content' => 'Publicación pública real <script>escape</script>', 'visibility' => 'public']);
        $posts->publish($other, $public->id, $public->version);
        $private = $posts->create($other, ['content' => 'PRIVATE-HOME-MARKER', 'visibility' => 'private']);
        $posts->publish($other, $private->id, $private->version);
        $response = $this->actingAs($actor)->get('/inicio')->assertOk()->assertSee('Publicación pública real')
            ->assertSee('&lt;script&gt;escape&lt;/script&gt;', false)->assertDontSee('<script>escape</script>', false)
            ->assertDontSee('PRIVATE-HOME-MARKER')->assertDontSee('Ana Martínez')->assertDontSee('Baldemar')
            ->assertDontSee('#perfil-')->assertDontSee('#baldemar')->assertDontSee('14 respuestas')->assertDontSee('data-home-data')
            ->assertSee(route('community.show', $public->id))->assertSee(route('lexa'));
        $this->assertSame(1, $this->dom($response->getContent())->query('//article[@data-home-post="'.$public->id.'"]//h3')->length);
        $this->get('/lexa')->assertOk()->assertSee('Tus consultas se guardan en tu historial privado');
    }

    public function test_home_empty_state_does_not_fabricate_activity(): void
    {
        $this->actingAs(User::factory()->create())->get('/inicio')->assertOk()->assertSee('No hay publicaciones públicas disponibles')
            ->assertSee('Aún no hay artículos publicados')->assertDontSee('Esperando respuesta')->assertDontSee('Hace 2 días');
    }

    public function test_messaging_html_and_polling_keep_iso_utc_fallback_without_changing_storage(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 07:49:00', 'UTC'));
        $actor = $this->member();
        $other = $this->member();
        $chat = app(ConversationService::class)->start($actor, $other->id);
        $message = app(MessageService::class)->send($other, $chat->id, ['content' => 'Fecha sintética']);
        $this->actingAs($actor)->get('/mensajes/'.$chat->id)->assertOk()->assertSee('2026-11-15T07:49:00+00:00', false)
            ->assertSee('07:49 UTC')->assertSee('data-message-time="full"', false);
        $poll = $this->getJson('/mensajes/'.$chat->id.'/actualizaciones')->assertOk();
        $this->assertStringContainsString('2026-11-15 07:49:00 UTC', $poll->json('history_html'));
        $this->assertStringContainsString('data-message-time="compact"', $poll->json('inbox_html'));
        $this->assertSame('2026-11-15 07:49:00', $message->fresh()->sent_at->format('Y-m-d H:i:s'));
        $this->assertNull(DB::table('conversation_participants')->where('conversation_id', $chat->id)->where('user_id', $actor->id)->value('last_read_message_id'));
    }

    public function test_reply_errors_are_associated_only_with_the_submitted_form_and_have_unique_ids(): void
    {
        $actor = $this->member();
        $posts = app(PostService::class);
        $post = $posts->create($actor, ['content' => 'Publicación sintética', 'visibility' => 'public']);
        $posts->publish($actor, $post->id, $post->version);
        $root = app(CommunityInteractionService::class)->comment($actor, $post->id, ['content' => 'Raíz']);
        $this->actingAs($actor)->post('/comunidad/publicaciones/'.$post->id.'/comentarios', ['content' => '', 'parent_id' => $root->id])->assertRedirect();
        $xpath = $this->dom($this->get('/comunidad/publicaciones/'.$post->id)->assertOk()->getContent());
        $id = 'comment-content-'.$root->id;
        $this->assertSame(1, $xpath->query('//*[@id="'.$id.'" and @aria-invalid="true"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="'.$id.'-error"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="comment-content-root" and @aria-invalid]')->length);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $element) {
            $ids[] = $element->getAttribute('id');
        }
        $this->assertCount(count(array_unique($ids)), $ids);
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }
}
