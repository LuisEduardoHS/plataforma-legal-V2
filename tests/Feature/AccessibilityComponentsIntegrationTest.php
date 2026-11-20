<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TestingIsolation;
use Tests\TestCase;

class AccessibilityComponentsIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // HTTP boot before fixtures; the external PHPUnit bootstrap also guards the whole suite.
        $this->get(route('login'))->assertOk();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(TestingIsolation::database(), DB::selectOne('SELECT DATABASE() db')->db);
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('cache.default'));
        $this->assertStringStartsWith(str_replace('\\', '/', storage_path()).'/', str_replace('\\', '/', Storage::disk('attachments')->path('')));
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function actor(array $permissions = []): User
    {
        $user = User::factory()->create()->refresh();
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        return $user;
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $element) {
            $id = $element->getAttribute('id');
            $this->assertNotContains($id, $ids, 'Duplicate ID '.$id);
            $ids[] = $id;
        }
        foreach ($xpath->query('//*[@aria-describedby or @aria-labelledby or @for]') as $element) {
            foreach (['aria-describedby', 'aria-labelledby', 'for'] as $attribute) {
                foreach (preg_split('/\s+/', trim($element->getAttribute($attribute)), -1, PREG_SPLIT_NO_EMPTY) as $id) {
                    $this->assertContains($id, $ids, 'Missing association '.$id);
                }
            }
        }

        return $xpath;
    }

    private function control(DOMXPath $xpath, string $id): DOMElement
    {
        $node = $xpath->query('//*[@id="'.$id.'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    public function test_shared_field_renders_unique_attributes_helpers_errors_and_safe_private_values(): void
    {
        session()->flashInput(['name' => '<b>Nombre</b>', 'password' => 'NEVER-RENDER-SECRET', 'photo' => 'NEVER-RENDER-PATH']);
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['name' => 'Corrige el nombre.']));
        view()->share('errors', $errors);
        $html = Blade::render('<p id="name-help">Ayuda</p><x-ui.input name="name" id="person-name" label="Nombre" aria-describedby="name-help" /><x-ui.input name="password" type="password" label="Clave" /><x-ui.input name="photo" type="file" label="Foto" />', compact('errors'));
        $xpath = $this->dom($html);
        $name = $this->control($xpath, 'person-name');
        $this->assertSame('true', $name->getAttribute('aria-invalid'));
        $this->assertSame('name-help person-name-error', $name->getAttribute('aria-describedby'));
        $this->assertSame('<b>Nombre</b>', $name->getAttribute('value'));
        $this->assertStringNotContainsString('<b>Nombre</b>', $html);
        $this->assertStringNotContainsString('NEVER-RENDER', $html);
        $this->assertFalse($this->control($xpath, 'password')->hasAttribute('value'));
        $this->assertFalse($this->control($xpath, 'photo')->hasAttribute('value'));
        // DOM normalizes duplicate attributes; also check the original rendered opening tag.
        preg_match('/<input[^>]*id="person-name"[^>]*>/', $html, $tag);
        $this->assertSame(1, preg_match_all('/\bid="/', $tag[0]));
        $this->assertSame(1, preg_match_all('/\baria-describedby="/', $tag[0]));
    }

    public function test_shared_field_handles_array_input_and_inactive_repeated_form_without_false_errors(): void
    {
        session()->flashInput(['reason' => ['invalid']]);
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['reason' => 'Motivo inválido.']));
        view()->share('errors', $errors);
        $html = Blade::render('<x-ui.input name="reason" id="first" label="Uno" multiline /><x-ui.input name="reason" id="second" label="Dos" multiline :error-active="false" :restore-old="false" />', compact('errors'));
        $xpath = $this->dom($html);
        $this->assertSame('true', $this->control($xpath, 'first')->getAttribute('aria-invalid'));
        $this->assertSame('false', $this->control($xpath, 'second')->getAttribute('aria-invalid'));
        $this->assertFalse($this->control($xpath, 'second')->hasAttribute('aria-describedby'));
        $this->assertSame('', $this->control($xpath, 'second')->textContent);
    }

    public static function kinds(): array
    {
        return [['articles', ['summary', 'body']], ['resources', ['description', 'resource_type', 'external_url']]];
    }

    #[DataProvider('kinds')]
    public function test_editorial_http_validation_links_real_controls_and_array_errors(string $prefix, array $specific): void
    {
        $this->actingAs($this->actor([$prefix.'.create']));
        $errors = array_fill_keys(['title', 'content_category_id', 'tag_ids.0', 'specialty_ids', 'attachment_ids.0', 'uploads.0', ...$specific], 'Corrige este campo.');
        $response = $this->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag($errors)), '_old_input' => ['title' => 'Borrador recuperado']])->get(route($prefix.'.create'))->assertOk();
        $xpath = $this->dom($response->getContent());
        foreach ($xpath->query('//*[@data-validation-summary]//a') as $link) {
            $target = $this->control($xpath, substr($link->getAttribute('href'), 1));
            $this->assertSame('true', $target->getAttribute('aria-invalid'));
            $this->assertNotSame('', $target->getAttribute('aria-describedby'));
        }
        $this->assertSame(count($errors), $xpath->query('//*[@data-validation-summary]//a')->length);
        $this->assertSame('Borrador recuperado', $this->control($xpath, 'editorial-title')->getAttribute('value'));
        $this->assertFalse($this->control($xpath, 'editorial-uploads')->hasAttribute('value'));
    }

    public static function operations(): array
    {
        return [['state', ['status' => 'suspended'], 'state-reason', 'permission-reason'], ['permission', ['permission' => 'articles.create', 'action' => 'revoke'], 'permission-reason', 'state-reason']];
    }

    #[DataProvider('operations')]
    public function test_operations_post_error_stays_in_its_form_and_preserves_version(string $route, array $fields, string $active, string $inactive): void
    {
        $actor = $this->actor(['operations.accounts', 'operations.permissions.editorial']);
        Permission::firstOrCreate(['name' => 'articles.create', 'guard_name' => 'web']);
        $target = $this->actor();
        $this->actingAs($actor);
        $version = $this->get(route('operations.user', $target->id))->assertOk()->viewData('account')['version'];
        $this->post(route('operations.'.$route, $target->id), $fields + ['version' => $version, 'reason' => 'breve'])->assertSessionHasErrors('reason')->assertRedirect(route('operations.user', $target->id));
        $xpath = $this->dom($this->get(route('operations.user', $target->id))->assertOk()->getContent());
        $this->assertSame('true', $this->control($xpath, $active)->getAttribute('aria-invalid'));
        $this->assertSame('breve', $this->control($xpath, $active)->textContent);
        $this->assertSame('false', $this->control($xpath, $inactive)->getAttribute('aria-invalid'));
        $this->assertSame('', $this->control($xpath, $inactive)->textContent);
        $this->assertSame(1, $xpath->query('//*[@data-validation-summary]//a[@href="#'.$active.'"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-validation-summary]//a[@href="#'.$inactive.'"]')->length);
        foreach ($xpath->query('//form[input[@name="version"]]') as $form) {
            $this->assertSame($version, $xpath->query('.//input[@name="version"]', $form)->item(0)->getAttribute('value'));
            $this->assertSame(1, $xpath->query('.//textarea[@name="reason"]', $form)->length);
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        }
        $this->assertSame('active', $target->fresh()->status);
        $this->assertDatabaseCount('operations_events', 0);
    }

    public function test_message_edit_http_error_preserves_text_version_and_access_boundaries(): void
    {
        $a = $this->actor();
        $b = $this->actor();
        $conversation = app(ConversationService::class)->start($a, $b->id);
        $message = app(MessageService::class)->send($a, $conversation->id, ['content' => 'Texto original', 'client_token' => (string) Str::uuid()]);
        $this->actingAs($a);
        $url = route('messages.edit', ['conversation' => $conversation->id, 'message' => $message->id]);
        $this->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['content' => 'Contenido demasiado extenso.'])), '_old_input' => ['content' => '<script>texto</script>', 'version' => $message->version]])->get($url)->assertOk();
        $xpath = $this->dom($this->get($url)->assertOk()->getContent());
        $control = $this->control($xpath, 'message-edit-content');
        $this->assertSame('true', $control->getAttribute('aria-invalid'));
        $this->assertSame('message-edit-content-error', $control->getAttribute('aria-describedby'));
        $this->assertSame('<script>texto</script>', $control->textContent);
        $this->assertSame(0, $xpath->query('//textarea/script')->length);
        $this->assertSame((string) $message->version, $xpath->query('//input[@name="version"]')->item(0)->getAttribute('value'));
        $this->actingAs($b)->get($url)->assertNotFound();
    }

    public function test_operations_malformed_version_does_not_crash_or_replace_it_with_a_fresh_token(): void
    {
        $actor = $this->actor(['operations.accounts']);
        $target = $this->actor();
        $this->actingAs($actor)->post(route('operations.state', $target->id), ['status' => 'suspended', 'version' => ['bad'], 'reason' => 'Motivo de prueba suficiente.'])->assertSessionHasErrors('version');
        $xpath = $this->dom($this->get(route('operations.user', $target->id))->assertOk()->getContent());
        $this->assertSame('', $xpath->query('//form[input[@name="version"]]//input[@name="version"]')->item(0)->getAttribute('value'));
        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_settings_has_native_named_dialog_and_real_post_csrf_fallback(): void
    {
        $this->actingAs($this->actor());
        $xpath = $this->dom($this->get(route('settings'))->assertOk()->getContent());
        $dialog = $this->control($xpath, 'settings-logout');
        $this->assertSame('dialog', $dialog->tagName);
        $this->assertFalse($dialog->hasAttribute('open'));
        $this->assertSame(1, $xpath->query('//dialog//button[@data-dialog-initial and @type="button"]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-dialog-open]/parent::form[@method="POST" and @data-turbo="false"]/input[@name="_token"]')->length);
        $this->assertSame(1, $xpath->query('//dialog//form[@method="POST" and @data-turbo="false"]/input[@name="_token"]')->length);
        $this->assertSame(1, $xpath->query('//button[@aria-label="Borrar búsqueda de configuración"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-future-setting and not(@disabled)]')->length);
    }

    public function test_guest_and_private_layouts_have_first_skip_link_and_focusable_target(): void
    {
        foreach (['login', 'register', 'password.request'] as $route) {
            $xpath = $this->dom($this->get(route($route))->assertOk()->getContent());
            $this->assertSame('ui-skip-link', $xpath->query('//body/a[1]')->item(0)->getAttribute('class'));
            $this->assertSame('-1', $this->control($xpath, 'main-content')->getAttribute('tabindex'));
        }
        $this->actingAs($this->actor());
        foreach (['home', 'community', 'messages', 'help'] as $route) {
            $xpath = $this->dom($this->get(route($route))->assertOk()->getContent());
            $this->assertSame('#main-content', $xpath->query('//body/a[1]')->item(0)->getAttribute('href'));
        }
    }
}
