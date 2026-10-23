<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    private array $userIds = [];

    private array $temporaryFiles = [];

    private AttachmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('plataforma_legal_v2_test', DB::connection()->getDatabaseName());
        $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        Storage::fake('attachments');
        $this->service = app(AttachmentService::class);
    }

    protected function tearDown(): void
    {
        try {
            Attachment::flushEventListeners();
            // Upload reservations must commit independently: remove only this test's rows.
            if ($this->userIds !== []) {
                DB::table('attachments')->whereIn('user_id', $this->userIds)->delete();
                DB::table('users')->whereIn('id', $this->userIds)->delete();
            }
            foreach ($this->temporaryFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $this->userIds[] = $user->id;

        return $user->refresh();
    }

    private function upload(string $name = 'consulta.txt', string $content = 'Documento legal de prueba.', string $browserMime = 'text/plain', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'back02-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $browserMime, $error, true);
    }

    public function test_store_records_verified_metadata_and_owner_and_downloads_original_content(): void
    {
        $user = $this->user();
        $content = 'Documento legal de prueba.';
        $disk = Storage::disk('attachments');
        $mock = Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('put')->once()
            ->withArgs(fn ($path, $stream, $options) => is_resource($stream) && $options === ['visibility' => 'private'])
            ->andReturnUsing(fn ($path, $stream, $options) => $disk->put($path, $stream, $options));
        Storage::set('attachments', $mock);
        $record = $this->service->store($user, $this->upload(browserMime: 'image/png'));

        $this->assertSame($user->id, $record->user_id);
        $this->assertTrue($record->user->is($user));
        $this->assertTrue($user->attachments()->firstOrFail()->is($record));
        $this->assertSame('consulta.txt', $record->original_name);
        $this->assertSame('text/plain', $record->mime_type);
        $this->assertSame('txt', $record->extension);
        $this->assertSame('document', $record->category);
        $this->assertSame(strlen($content), $record->size_bytes);
        $this->assertSame(hash('sha256', $content), $record->sha256);
        $this->assertSame('private', $record->visibility);
        $this->assertSame(AttachmentStatus::Available, $record->status);
        $this->assertSame(1, Attachment::available()->whereKey($record->id)->count());
        Storage::disk('attachments')->assertExists($record->path);
        $this->assertFalse(config('filesystems.disks.attachments.serve'));

        $metadata = $this->service->metadata($user, $record->id);
        foreach (['disk', 'path', 'storage_name', 'sha256'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $metadata);
        }

        $response = $this->service->download($user, $record->id);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        ob_start();
        $response->sendContent();
        $this->assertSame($content, ob_get_clean());
    }

    public function test_storage_names_are_unique_even_for_identical_content_and_untrusted_names(): void
    {
        $user = $this->user();
        $a = $this->service->store($user, $this->upload('../../consulta.txt'));
        $b = $this->service->store($user, $this->upload('C:\\fakepath\\consulta.txt'));
        $this->assertNotSame($a->path, $b->path);
        $this->assertNotSame($a->storage_name, $b->storage_name);
        $this->assertSame($a->sha256, $b->sha256);
        $this->assertSame('consulta.txt', $a->original_name);
        $this->assertSame('consulta.txt', $b->original_name);
        $this->assertMatchesRegularExpression('#^attachments/\d{4}/\d{2}/[a-f0-9-]{36}\.txt$#', $a->path);
    }

    public static function invalidUploads(): array
    {
        return [
            'HTML disguised as text' => ['legal.txt', '<!DOCTYPE html><html><script>alert(1)</script></html>'],
            'SVG' => ['legal.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'PHP executable' => ['legal.php', '<?php echo "unsafe";'],
            'wrong extension' => ['legal.pdf', 'Only plain text, not a PDF.'],
            'spoofed image' => ['legal.png', 'Only plain text, not an image.'],
            'double extension' => ['legal.txt.exe', 'Only plain text.'],
            'extension missing' => ['legal', 'Only plain text.'],
            'empty file' => ['legal.txt', ''],
            'archive' => ['legal.zip', "PK\x03\x04archive"],
        ];
    }

    #[DataProvider('invalidUploads')]
    public function test_rejects_disallowed_content_and_extension_without_persisting_files(string $name, string $content): void
    {
        $user = $this->user();
        try {
            $this->service->store($user, $this->upload($name, $content));
            $this->fail('Se admitió un archivo inválido.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('file', $error->errors());
        }
        $this->assertSame(0, $user->attachments()->count());
        $this->assertSame([], Storage::disk('attachments')->allFiles());
    }

    public function test_rejects_size_limit_and_failed_http_upload(): void
    {
        $user = $this->user();
        config(['attachments.max_bytes.document' => 4]);
        foreach ([$this->upload(), $this->upload(error: UPLOAD_ERR_PARTIAL)] as $file) {
            try {
                $this->service->store($user, $file);
                $this->fail('La validación debía fallar.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('file', $error->errors());
            }
        }
        $this->assertSame(0, $user->attachments()->count());
    }

    public function test_real_png_and_pdf_are_classified_by_content(): void
    {
        $user = $this->user();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $image = $this->service->store($user, $this->upload('imagen.png', $png));
        $pdf = $this->service->store($user, $this->upload('recurso.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"));
        $this->assertSame('image', $image->category);
        $this->assertSame('image/png', $image->mime_type);
        $this->assertSame('document', $pdf->category);
        $this->assertSame('application/pdf', $pdf->mime_type);
    }

    public function test_other_users_cannot_read_download_or_delete_private_files(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $record = $this->service->store($owner, $this->upload());
        foreach (['metadata', 'download', 'requestDeletion', 'purge'] as $method) {
            try {
                $this->service->$method($other, $record->id);
                $this->fail('Se permitió acceso ajeno: '.$method);
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(AttachmentStatus::Available, $record->fresh()->status);
    }

    public static function categorySamples(): array
    {
        return [
            'image' => ['image', 'png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')],
            'video' => ['video', 'mp4', pack('N', 24).'ftypisom'.pack('N', 0).'isommp42'],
            'document' => ['document', 'txt', 'Documento de prueba.'],
            'other' => ['other', 'mp3', hex2bin('fffb9064').str_repeat(chr(0), 413)],
        ];
    }

    #[DataProvider('categorySamples')]
    public function test_category_specific_limit_accepts_boundary_and_rejects_larger_content(string $category, string $extension, string $content): void
    {
        $owner = $this->user();
        config(['attachments.max_bytes.'.$category => strlen($content)]);
        $record = $this->service->store($owner, $this->upload('recurso.'.$extension, $content));
        $this->assertSame($category, $record->category);
        $this->assertSame(strlen($content), $record->size_bytes);
        config(['attachments.max_bytes.'.$category => strlen($content) - 1]);
        try {
            $this->service->store($owner, $this->upload('grande.'.$extension, $content));
            $this->fail('Se excedió el límite de la categoría.');
        } catch (ValidationException) {
            $this->assertSame(1, $owner->attachments()->count());
        }
    }

    public function test_missing_actor_or_attachment_is_denied_and_available_file_cannot_be_purged_directly(): void
    {
        $owner = $this->user();
        $record = $this->service->store($owner, $this->upload());
        foreach ([[new User, $record->id], [$owner, -1]] as [$actor, $id]) {
            try {
                $this->service->metadata($actor, $id);
                $this->fail('Se esperaba acceso denegado.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        try {
            $this->service->purge($owner, $record->id);
            $this->fail('Se borró un archivo sin solicitar su eliminación.');
        } catch (AttachmentException) {
            Storage::disk('attachments')->assertExists($record->path);
            $this->assertSame(AttachmentStatus::Available, $record->fresh()->status);
        }
    }

    public static function inactiveStates(): array
    {
        return [['suspended'], ['disabled'], ['pending_deletion']];
    }

    #[DataProvider('inactiveStates')]
    public function test_persisted_account_state_blocks_all_operations_even_with_a_stale_actor(string $status): void
    {
        $owner = $this->user();
        $record = $this->service->store($owner, $this->upload());
        DB::table('users')->where('id', $owner->id)->update(['status' => $status]);
        $this->assertTrue($owner->isActive());
        foreach (['store', 'metadata', 'download', 'requestDeletion', 'purge'] as $method) {
            try {
                $this->service->$method($owner, $method === 'store' ? $this->upload() : $record->id);
                $this->fail('Una cuenta inactiva obtuvo acceso.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_deletion_blocks_reads_then_physically_deletes_idempotently_and_keeps_audit_metadata(): void
    {
        $owner = $this->user();
        $record = $this->service->store($owner, $this->upload());
        $this->service->requestDeletion($owner, $record->id);
        $this->service->requestDeletion($owner, $record->id);
        $this->assertSame(AttachmentStatus::PendingDeletion, $record->fresh()->status);
        $this->assertNotNull($record->fresh()->deletion_requested_at);
        Storage::disk('attachments')->assertExists($record->path);
        foreach (['metadata', 'download'] as $method) {
            try {
                $this->service->$method($owner, $record->id);
                $this->fail('Se recuperó un archivo pendiente de eliminación.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $this->service->purge($owner, $record->id);
        $this->service->purge($owner, $record->id);
        Storage::disk('attachments')->assertMissing($record->path);
        $this->assertSame(AttachmentStatus::Deleted, $record->fresh()->status);
        $this->assertNotNull($record->fresh()->deleted_at);
        $this->assertSame('consulta.txt', $record->fresh()->original_name);
    }

    public function test_failed_storage_write_leaves_no_object_and_a_traceable_record(): void
    {
        $owner = $this->user();
        $disk = Storage::disk('attachments');
        $mock = Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('put')->once()->andReturn(false);
        Storage::set('attachments', $mock);
        $this->expectException(AttachmentException::class);
        try {
            $this->service->store($owner, $this->upload());
        } finally {
            $this->assertSame([], $disk->allFiles());
            $this->assertSame(AttachmentStatus::Deleted, $owner->attachments()->firstOrFail()->status);
        }
    }

    public function test_failed_metadata_update_cleans_up_the_stored_object(): void
    {
        $owner = $this->user();
        Attachment::updating(function (Attachment $record): void {
            if ($record->status === AttachmentStatus::Available) {
                throw new RuntimeException('Injected metadata failure /internal/path');
            }
        });
        try {
            $this->service->store($owner, $this->upload());
            $this->fail('Se esperaba un fallo de persistencia.');
        } catch (AttachmentException $error) {
            $this->assertStringNotContainsString('/internal/path', $error->getMessage());
        }
        $this->assertSame([], Storage::disk('attachments')->allFiles());
        $this->assertSame(AttachmentStatus::Deleted, $owner->attachments()->firstOrFail()->status);
    }

    public function test_partial_storage_write_is_removed_after_failure(): void
    {
        $owner = $this->user();
        $disk = Storage::disk('attachments');
        $mock = Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('put')->once()->andReturnUsing(function ($path) use ($disk): bool {
            $disk->put($path, 'partial content', ['visibility' => 'private']);

            return false;
        });
        Storage::set('attachments', $mock);
        $this->expectException(AttachmentException::class);
        try {
            $this->service->store($owner, $this->upload());
        } finally {
            $this->assertSame([], $disk->allFiles());
            $this->assertSame(AttachmentStatus::Deleted, $owner->attachments()->firstOrFail()->status);
        }
    }

    public function test_failed_reservation_does_not_write_a_file(): void
    {
        $owner = $this->user();
        Attachment::creating(fn () => throw new RuntimeException('Injected reservation failure'));
        $this->expectException(AttachmentException::class);
        try {
            $this->service->store($owner, $this->upload());
        } finally {
            $this->assertSame([], Storage::disk('attachments')->allFiles());
            $this->assertSame(0, $owner->attachments()->count());
        }
    }

    public function test_failed_cleanup_keeps_a_reservation_for_retry(): void
    {
        $owner = $this->user();
        $disk = Storage::disk('attachments');
        $mock = Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('put')->once()->andReturn(false);
        $mock->shouldReceive('delete')->once()->andThrow(new RuntimeException('Injected cleanup failure'));
        Storage::set('attachments', $mock);
        try {
            $this->service->store($owner, $this->upload());
            $this->fail('Se esperaba un fallo.');
        } catch (AttachmentException) {
            $record = $owner->attachments()->firstOrFail();
            $this->assertSame(AttachmentStatus::PendingUpload, $record->status);
        }
        Storage::set('attachments', $disk);
        $this->service->requestDeletion($owner, $record->id);
        $this->service->purge($owner, $record->id);
        $this->assertSame(AttachmentStatus::Deleted, $record->fresh()->status);
    }

    public function test_failed_physical_deletion_stays_pending_and_can_be_retried(): void
    {
        $owner = $this->user();
        $record = $this->service->store($owner, $this->upload());
        $this->service->requestDeletion($owner, $record->id);
        $disk = Storage::disk('attachments');
        $mock = Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('delete')->once()->andReturn(false);
        Storage::set('attachments', $mock);
        try {
            $this->service->purge($owner, $record->id);
            $this->fail('Se esperaba un fallo de borrado.');
        } catch (AttachmentException) {
            $this->assertSame(AttachmentStatus::PendingDeletion, $record->fresh()->status);
            $disk->assertExists($record->path);
        }
        Storage::set('attachments', $disk);
        $this->service->purge($owner, $record->id);
        $disk->assertMissing($record->path);
    }

    public function test_missing_storage_object_yields_a_sanitized_error_and_can_be_purged(): void
    {
        $owner = $this->user();
        $record = $this->service->store($owner, $this->upload());
        Storage::disk('attachments')->delete($record->path);
        try {
            $this->service->download($owner, $record->id);
            $this->fail('Se esperaba un fallo de lectura.');
        } catch (AttachmentException $error) {
            $this->assertStringNotContainsString($record->path, $error->getMessage());
        }
        $this->service->requestDeletion($owner, $record->id);
        $this->service->purge($owner, $record->id);
        $this->assertSame(AttachmentStatus::Deleted, $record->fresh()->status);
    }

    public function test_mysql_failure_after_physical_deletion_keeps_pending_state_for_retry(): void
    {
        $owner = $this->user();
        $record = $this->service->store($owner, $this->upload());
        $this->service->requestDeletion($owner, $record->id);
        Attachment::updating(function (Attachment $attachment): void {
            if ($attachment->status === AttachmentStatus::Deleted) {
                // MySQL must reject this invalid unsigned size during the final update.
                $attachment->size_bytes = -1;
            }
        });
        try {
            $this->service->purge($owner, $record->id);
            $this->fail('Se esperaba el rechazo de MySQL.');
        } catch (AttachmentException $error) {
            $this->assertStringNotContainsString('SQL', $error->getMessage());
            $this->assertSame(AttachmentStatus::PendingDeletion, $record->fresh()->status);
            $this->assertNull($record->fresh()->deleted_at);
            Storage::disk('attachments')->assertMissing($record->path);
        }
        Attachment::flushEventListeners();
        $this->service->purge($owner, $record->id);
        $this->assertSame(AttachmentStatus::Deleted, $record->fresh()->status);
    }

    public function test_logical_deletion_failure_returns_no_internal_details_and_preserves_availability(): void
    {
        $owner = $this->user();
        $record = $this->service->store($owner, $this->upload());
        Attachment::updating(fn () => throw new RuntimeException('Internal /secret/path'));
        try {
            $this->service->requestDeletion($owner, $record->id);
            $this->fail('Se esperaba un fallo.');
        } catch (AttachmentException $error) {
            $this->assertStringNotContainsString('/secret/path', $error->getMessage());
            $this->assertSame(AttachmentStatus::Available, $record->fresh()->status);
            Storage::disk('attachments')->assertExists($record->path);
        }
    }

    public function test_rejects_public_disk_configuration_and_ambient_transactions(): void
    {
        $owner = $this->user();
        config(['attachments.disk' => 'public']);
        try {
            $this->service->store($owner, $this->upload());
            $this->fail('Se admitió un disco público.');
        } catch (AttachmentException) {
            $this->assertSame(0, $owner->attachments()->count());
        }
        config(['attachments.disk' => 'attachments']);
        DB::beginTransaction();
        try {
            $this->expectException(AttachmentException::class);
            $this->service->store($owner, $this->upload());
        } finally {
            DB::rollBack();
        }
    }

    public function test_mysql_enforces_unique_storage_keys_and_owner_integrity(): void
    {
        $owner = $this->user();
        $record = $this->service->store($owner, $this->upload());
        foreach (['duplicate_path', 'duplicate_name', 'missing_owner', 'delete_owner'] as $scenario) {
            try {
                if ($scenario === 'delete_owner') {
                    $owner->delete();
                } else {
                    $copy = $record->replicate();
                    if ($scenario === 'duplicate_path') {
                        $copy->storage_name = 'another-name.txt';
                    } elseif ($scenario === 'duplicate_name') {
                        $copy->path = 'attachments/another-path.txt';
                    } else {
                        $copy->storage_name = 'missing-owner.txt';
                        $copy->path = 'attachments/missing-owner.txt';
                        $copy->user_id = DB::table('users')->max('id') + 1000;
                    }
                    $copy->save();
                }
                $this->fail('MySQL no protegió la integridad: '.$scenario);
            } catch (QueryException $error) {
                $this->assertSame('23000', (string) $error->getCode());
                $this->assertSame(match ($scenario) {
                    'delete_owner' => 1451, 'missing_owner' => 1452, default => 1062,
                }, (int) $error->errorInfo[1]);
            }
        }
    }

    public function test_catalogs_and_roles_remain_compatible_without_private_admin_bypass(): void
    {
        $owner = $this->user();
        $admin = $this->user();
        $record = $this->service->store($owner, $this->upload());
        DB::beginTransaction();
        try {
            $this->seed(DatabaseSeeder::class);
            $admin->assignRole('admin');
            $this->assertTrue($admin->can('users.manage'));
            $this->assertSame(8, DB::table('content_categories')->count());
            $this->assertSame(12, DB::table('tags')->count());
            $this->assertSame(20, DB::table('legal_specialties')->count());
            $this->expectException(AuthorizationException::class);
            $this->service->metadata($admin, $record->id);
        } finally {
            DB::rollBack();
        }
    }
}
