<?php

namespace Tests\Feature;

use App\Enums\AttachmentStatus;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\Post;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\TestingIsolation;
use Tests\TestCase;

class MediaQuotaReconciliationTest extends TestCase
{
    private array $users = [];

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV') !== 'testing' || DB::selectOne('SELECT DATABASE() db')->db !== TestingIsolation::database()
            || config('cache.default') !== 'array' || config('session.driver') !== 'array'
            || str_starts_with(str_replace('\\', '/', storage_path()), str_replace('\\', '/', base_path()).'/')) {
            throw new \RuntimeException('Unsafe media testing environment');
        }
        Storage::fake('attachments');
        config(['attachments.quota.enabled' => true, 'attachments.quota.per_user_bytes' => 20]);
    }

    protected function tearDown(): void
    {
        try {
            Attachment::flushEventListeners();
            $posts = DB::table('posts')->whereIn('user_id', $this->users)->pluck('id');
            DB::table('post_attachments')->whereIn('post_id', $posts)->delete();
            DB::table('posts')->whereIn('id', $posts)->delete();
            DB::table('user_profiles')->whereIn('user_id', $this->users)->delete();
            DB::table('attachments')->whereIn('user_id', $this->users)->delete();
            DB::table('users')->whereIn('id', $this->users)->delete();
            foreach ($this->files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    private function user(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->users[] = $user->id;

        return $user;
    }

    private function upload(string $text = 'synthetic media data'): UploadedFile
    {
        $file = tempnam(storage_path('framework'), 'media-');
        $this->files[] = $file;
        file_put_contents($file, $text);

        return new UploadedFile($file, 'private-original-name.txt', 'text/plain', UPLOAD_ERR_OK, true);
    }

    private function store(User $user): Attachment
    {
        return app(AttachmentService::class)->store($user, $this->upload());
    }

    private function expectQuota(User $user): void
    {
        $before = Attachment::where('user_id', $user->id)->count();
        try {
            $this->store($user);
            $this->fail('Quota was bypassed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }
        $this->assertSame($before, Attachment::where('user_id', $user->id)->count());
    }

    public function test_available_pending_and_pending_deletion_use_quota_until_confirmed_purge(): void
    {
        $user = $this->user();
        $file = $this->store($user);
        $service = app(AttachmentService::class);
        $this->assertSame(AttachmentStatus::Available, $file->status);
        $this->expectQuota($user);
        $service->requestDeletion($user, $file->id);
        $service->requestDeletion($user, $file->id);
        $this->expectQuota($user);
        $service->purge($user, $file->id);
        $service->purge($user, $file->id);
        $this->assertSame(AttachmentStatus::Deleted, $file->fresh()->status);
        $this->assertSame(AttachmentStatus::Available, $this->store($user)->status);
    }

    public function test_pending_reservation_and_retained_evidence_are_counted_without_double_counting_links(): void
    {
        $user = $this->user();
        $file = $this->store($user);
        $file->forceFill(['status' => AttachmentStatus::PendingUpload])->save();
        $this->expectQuota($user);
        $file->forceFill(['status' => AttachmentStatus::Available, 'verification_retained_at' => now()])->save();
        $this->expectQuota($user);
        try {
            app(AttachmentService::class)->requestDeletion($user, $file->id);
            $this->fail('Retained evidence released');
        } catch (AttachmentException) {
            $this->assertSame(AttachmentStatus::Available, $file->fresh()->status);
        }
        // Multiple contexts still account for a single physical attachment.
        $post = new Post;
        $post->forceFill(['user_id' => $user->id, 'content' => 'Synthetic', 'status' => 'draft', 'visibility' => 'private'])->save();
        DB::table('post_attachments')->insert(['post_id' => $post->id, 'attachment_id' => $file->id, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_profiles')->insert(['user_id' => $user->id, 'photo_attachment_id' => $file->id, 'created_at' => now(), 'updated_at' => now()]);
        config(['attachments.quota.per_user_bytes' => 40]);
        $this->assertSame(AttachmentStatus::Available, $this->store($user)->status);
    }

    public function test_quota_is_per_owner_and_can_be_disabled_without_public_access(): void
    {
        $a = $this->user();
        $file = $this->store($a);
        $b = $this->user();
        $this->assertSame(AttachmentStatus::Available, $this->store($b)->status);
        try {
            app(AttachmentService::class)->download($b, $file->id);
            $this->fail('Cross-owner access');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        config(['attachments.quota.enabled' => false]);
        $this->assertSame(AttachmentStatus::Available, $this->store($a)->status);
    }

    public function test_failed_io_and_cleanup_keep_charge_until_retry_confirms_deletion(): void
    {
        $user = $this->user();
        $disk = Storage::disk('attachments');
        $mock = Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('put')->once()->andReturn(false);
        $mock->shouldReceive('delete')->once()->andReturn(false);
        Storage::set('attachments', $mock);
        try {
            $this->store($user);
            $this->fail('Expected storage failure');
        } catch (AttachmentException) {
            $this->assertTrue(true);
        }
        $file = Attachment::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(AttachmentStatus::PendingDeletion, $file->status);
        $this->expectQuota($user);
        Storage::set('attachments', $disk);
        app(AttachmentService::class)->purge($user, $file->id);
        $this->assertSame(AttachmentStatus::Available, $this->store($user)->status);
    }

    public function test_failed_reservation_does_not_leak_charge_or_write_object(): void
    {
        $user = $this->user();
        Attachment::creating(fn () => throw new \RuntimeException('synthetic DB failure'));
        try {
            $this->store($user);
            $this->fail('Expected DB failure');
        } catch (AttachmentException) {
            $this->assertSame(0, Attachment::where('user_id', $user->id)->count());
        }
        $this->assertSame([], Storage::disk('attachments')->allFiles());
        Attachment::flushEventListeners();
        $this->assertSame(AttachmentStatus::Available, $this->store($user)->status);
    }

    public function test_reconcile_is_read_only_redacts_names_and_handles_multiple_references(): void
    {
        $user = $this->user();
        $file = $this->store($user);
        $disk = Storage::disk('attachments');
        $file->forceFill(['status' => AttachmentStatus::PendingUpload, 'updated_at' => now()->subDays(2)])->save();
        $disk->put('attachments/synthetic-untracked-secret-name.txt', 'synthetic orphan');
        $before = DB::table('attachments')->where('id', $file->id)->first();
        $objects = $disk->allFiles();
        $this->assertSame(0, Artisan::call('media:reconcile', ['--dry-run' => true, '--enumerate' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('stale_reservation attachment:'.$file->id, $output);
        $this->assertStringContainsString('object_without_known_row_candidate object:', $output);
        $this->assertStringNotContainsString($file->original_name, $output);
        $this->assertStringNotContainsString($file->path, $output);
        $this->assertStringNotContainsString('secret-name', $output);
        $this->assertEquals($before, DB::table('attachments')->where('id', $file->id)->first());
        $this->assertSame($objects, $disk->allFiles());
        $this->assertSame(1, Artisan::call('media:reconcile'));
    }

    public function test_diagnostic_reports_missing_and_inconsistent_objects_without_reading_or_repairing_them(): void
    {
        $user = $this->user();
        config(['attachments.quota.enabled' => false]);
        $missing = $this->store($user);
        $mismatch = $this->store($user);
        $deleted = $this->store($user);
        $disk = Storage::disk('attachments');
        $disk->delete($missing->path);
        $disk->put($mismatch->path, 'different');
        $deleted->forceFill(['status' => AttachmentStatus::Deleted, 'deleted_at' => now()])->save();
        $before = DB::table('attachments')->where('user_id', $user->id)->orderBy('id')->get()->toJson();
        $mock = Mockery::mock($disk)->makePartial();
        $mock->shouldNotReceive('readStream');
        $mock->shouldNotReceive('get');
        $mock->shouldNotReceive('delete');
        $mock->shouldNotReceive('put');
        Storage::set('attachments', $mock);
        $this->assertSame(0, Artisan::call('media:reconcile', ['--dry-run' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('available_object_missing attachment:'.$missing->id, $out);
        $this->assertStringContainsString('size_mismatch attachment:'.$mismatch->id, $out);
        $this->assertStringContainsString('deleted_object_still_present attachment:'.$deleted->id, $out);
        $this->assertSame($before, DB::table('attachments')->where('user_id', $user->id)->orderBy('id')->get()->toJson());
    }

    public function test_diagnostic_does_not_create_a_missing_local_root(): void
    {
        $user = $this->user();
        $file = $this->store($user);
        $root = storage_path('framework/does-not-exist-'.bin2hex(random_bytes(8)));
        config(['filesystems.disks.absent' => ['driver' => 'local', 'root' => $root, 'visibility' => 'private', 'serve' => false]]);
        $file->forceFill(['disk' => 'absent'])->save();
        $this->assertSame(0, Artisan::call('media:reconcile', ['--dry-run' => true]));
        $this->assertStringContainsString('local_root_missing attachment:'.$file->id, Artisan::output());
        $this->assertDirectoryDoesNotExist($root);
    }

    public function test_two_real_processes_cannot_reserve_more_than_one_upload(): void
    {
        $bootstrap = base_path('tests/Support/media-worker-bootstrap.php');
        putenv('MEDIA_TEST_BOOTSTRAP='.$bootstrap);
        if (! $bootstrap || ! function_exists('proc_open')) {
            $this->fail('Concurrent test requires proc_open');
        }
        $user = $this->user();
        $upload = $this->upload();
        $worker = tempnam(storage_path('framework'), 'worker-');
        $this->files[] = $worker;
        file_put_contents($worker, <<<'PHP'
<?php
 define('MEDIA_PROBE',true);require getenv('MEDIA_TEST_BOOTSTRAP');
 if(app()->environment()!=='testing'||Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() db')->db!==\Tests\Support\TestingIsolation::database())exit(9);
 config(['filesystems.disks.attachments.root'=>$argv[3],'attachments.quota.enabled'=>true,'attachments.quota.per_user_bytes'=>20]);
 App\Models\Attachment::creating(fn()=>usleep(200000));
 file_put_contents($argv[4], 'ready');$until=microtime(true)+15;while(!is_file($argv[5])&&microtime(true)<$until)usleep(10000);if(!is_file($argv[5]))exit(8);
 try{$u=App\Models\User::findOrFail((int)$argv[1]);$f=new Illuminate\Http\UploadedFile($argv[2],'synthetic.txt','text/plain',UPLOAD_ERR_OK,true);app(App\Services\Attachments\AttachmentService::class)->store($u,$f);echo 'reserved';}
 catch(Illuminate\Validation\ValidationException){echo 'quota';}
 catch(Throwable $e){echo 'failed:'.get_class($e);}
PHP);
        $go = $worker.'.go';
        $this->files[] = $go;
        $processes = [];
        $pipes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $ready = $worker.'.ready'.$i;
                $this->files[] = $ready;
                $processes[$i] = proc_open([PHP_BINARY, $worker, (string) $user->id, $upload->getRealPath(), Storage::disk('attachments')->path(''), $ready, $go], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes[$i], base_path(), null, ['bypass_shell' => true, 'create_no_window' => true]);
                $this->assertIsResource($processes[$i]);
                fclose($pipes[$i][0]);
            }
            $until = microtime(true) + 12;
            while ((! is_file($worker.'.ready0') || ! is_file($worker.'.ready1')) && microtime(true) < $until) {
                usleep(10000);
            }
            $this->assertFileExists($worker.'.ready0');
            $this->assertFileExists($worker.'.ready1');
            file_put_contents($go, 'go');
            $results = [];
            foreach ($pipes as $pipe) {
                $results[] = trim(stream_get_contents($pipe[1]));
                $errors = stream_get_contents($pipe[2]);
                $this->assertSame('', trim($errors));
                fclose($pipe[1]);
                fclose($pipe[2]);
            }
            sort($results);
            $this->assertSame(['quota', 'reserved'], $results);
            $this->assertSame(1, Attachment::where('user_id', $user->id)->count());
            $this->assertSame(20, (int) Attachment::where('user_id', $user->id)->sum('size_bytes'));
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
        }
    }
}
