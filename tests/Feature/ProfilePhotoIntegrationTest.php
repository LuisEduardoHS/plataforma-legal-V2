<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Exceptions\ProfileException;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Profiles\ProfileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfilePhotoIntegrationTest extends TestCase
{
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp(); // Stops before fixtures unless MySQL testing is isolated.
        Storage::fake('attachments');
    }

    protected function tearDown(): void
    {
        try {
            // Upload reservations require real commits. Clean only this test's rows.
            DB::table('user_profiles')->whereIn('user_id', $this->userIds)->delete();
            DB::table('attachments')->whereIn('user_id', $this->userIds)->delete();
            DB::table('users')->whereIn('id', $this->userIds)->delete();
        } finally {
            parent::tearDown();
        }
    }

    private function user(): User
    {
        $user = User::factory()->create()->refresh();
        $this->userIds[] = $user->id;

        return $user;
    }

    private function image(string $name = 'foto.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aO2kAAAAASUVORK5CYII='));
    }

    public function test_upload_is_private_and_download_only_serves_the_current_owners_photo(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/perfil/fotografia', ['photo' => $this->image()])->assertRedirect(route('profile'));
        $photo = Attachment::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(AttachmentStatus::Available, $photo->status);
        $this->assertSame('private', $photo->visibility);
        $this->assertSame($photo->id, $user->profile->photo_attachment_id);
        $this->assertSame('private', $user->profile->visibility->value);
        Storage::disk('attachments')->assertExists($photo->path);
        $response = $this->get('/perfil/fotografia?attachment_id=999999')->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('attachments')->get($photo->path), $response->streamedContent());
        $this->get('/perfil')->assertSee('Descargar mi fotografía privada')->assertDontSee($photo->path)->assertDontSee($photo->storage_name);

        $other = $this->user();
        $this->actingAs($other)->get('/perfil/fotografia?attachment_id='.$photo->id)->assertNotFound();
        $this->putJson('/perfil', ['visibility' => 'public', 'photo_attachment_id' => $photo->id])->assertUnprocessable();
        $this->assertNull($other->profile);
    }

    public function test_replacement_and_removal_do_not_delete_a_potentially_retained_attachment(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/perfil/fotografia', ['photo' => $this->image()])->assertRedirect(route('profile'));
        $old = $user->profile()->firstOrFail()->photo;
        $this->post('/perfil/fotografia', ['photo' => $this->image('otra.png')])->assertRedirect(route('profile'));
        $this->assertNotSame($old->id, $user->profile()->firstOrFail()->photo_attachment_id);
        $this->assertSame(AttachmentStatus::Available, $old->fresh()->status);
        $this->delete('/perfil/fotografia')->assertRedirect(route('profile'));
        $this->delete('/perfil/fotografia')->assertRedirect(route('profile'));
        $this->assertNull($user->profile()->firstOrFail()->photo_attachment_id);
        $this->assertSame(2, Attachment::where('user_id', $user->id)->count());
        Storage::disk('attachments')->assertExists($old->path);
        $this->get('/perfil/fotografia')->assertNotFound();
    }

    public static function invalidFiles(): array
    {
        return [['archivo.txt', 'text'], ['archivo.png', 'text'], ['archivo.svg', '<svg></svg>'], ['archivo.jpg', 'png'], ['archivo.html', '<html></html>']];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_images_are_rejected_without_creating_an_attachment(string $name, string $content): void
    {
        $user = $this->user();
        $file = $content === 'png' ? $this->image($name) : UploadedFile::fake()->createWithContent($name, $content);
        $this->actingAs($user)->from('/perfil/editar')->post('/perfil/fotografia', ['photo' => $file])->assertRedirect('/perfil/editar')->assertSessionHasErrors();
        $this->assertSame(0, Attachment::where('user_id', $user->id)->count());
        $this->assertNull($user->profile);
    }

    public function test_oversized_photo_is_rejected(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/perfil/fotografia', ['photo' => $this->image()->size(10241)])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertSame(0, Attachment::where('user_id', $user->id)->count());
    }

    public function test_failed_profile_association_compensates_only_the_new_upload(): void
    {
        $user = $this->user();
        $photo = app(AttachmentService::class)->store($user, $this->image());
        app(ProfileService::class)->saveUserProfile($user, $user->id, ['photo_attachment_id' => $photo->id]);
        $oldId = $user->profile()->firstOrFail()->photo_attachment_id;
        $this->mock(ProfileService::class)->shouldReceive('saveUserProfile')->once()->andThrow(new ProfileException);
        $this->actingAs($user)->from('/perfil/editar')->post('/perfil/fotografia', ['photo' => $this->image()])->assertRedirect('/perfil/editar')->assertSessionHasErrors('photo');
        $this->assertSame($oldId, $user->profile()->firstOrFail()->photo_attachment_id);
        $this->assertSame(AttachmentStatus::Available, Attachment::findOrFail($oldId)->status);
        $this->assertSame(AttachmentStatus::PendingDeletion, Attachment::where('user_id', $user->id)->where('id', '!=', $oldId)->firstOrFail()->status);
    }

    public function test_unavailable_or_foreign_photos_cannot_be_downloaded_even_if_sql_forges_the_association(): void
    {
        $owner = $this->user();
        $this->actingAs($owner)->post('/perfil/fotografia', ['photo' => $this->image()])->assertRedirect(route('profile'));
        $photo = Attachment::where('user_id', $owner->id)->firstOrFail();
        $other = $this->user();
        $profile = app(ProfileService::class)->saveUserProfile($other, $other->id, []);
        DB::table('user_profiles')->where('id', $profile->id)->update(['photo_attachment_id' => $photo->id]);
        $this->actingAs($other)->get('/perfil/fotografia')->assertForbidden();
        $this->actingAs($owner);
        $photo->forceFill(['status' => AttachmentStatus::PendingDeletion])->save();
        $this->get('/perfil/fotografia')->assertForbidden();
        $this->get('/perfil')->assertOk()->assertDontSee('Descargar mi fotografía privada');
    }

    public function test_public_profiles_never_expose_private_photo_routes_or_storage_metadata(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/perfil/fotografia', ['photo' => $this->image()])->assertRedirect(route('profile'));
        app(ProfileService::class)->saveUserProfile($user, $user->id, ['visibility' => 'public']);
        $photo = Attachment::where('user_id', $user->id)->firstOrFail();
        $this->get('/usuarios/'.$user->id)->assertOk()->assertDontSee('/perfil/fotografia')->assertDontSee($photo->path)->assertDontSee('photo_attachment_id');
    }

    public function test_missing_storage_returns_a_generic_error_without_internal_paths(): void
    {
        config(['app.debug' => true]);
        $user = $this->user();
        $this->actingAs($user)->post('/perfil/fotografia', ['photo' => $this->image()])->assertRedirect(route('profile'));
        $photo = Attachment::where('user_id', $user->id)->firstOrFail();
        Storage::disk('attachments')->delete($photo->path);
        $this->get('/perfil/fotografia')->assertStatus(503)->assertSee('La fotografía no está disponible')->assertDontSee($photo->path)->assertDontSee('Stack trace');
    }

    public function test_photo_upload_rate_limit_is_shared_by_account_and_does_not_create_rows_on_rejection(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/perfil/fotografia')->assertUnprocessable();
        }
        $this->postJson('/perfil/fotografia', ['photo' => $this->image()])->assertStatus(429);
        $this->assertSame(0, Attachment::where('user_id', $user->id)->count());
    }
}
