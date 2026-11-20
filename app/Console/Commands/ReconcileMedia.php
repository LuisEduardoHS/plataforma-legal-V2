<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReconcileMedia extends Command
{
    protected $signature = 'media:reconcile {--dry-run} {--limit=500} {--older-hours=24} {--enumerate}';

    protected $description = 'Diagnóstico acotado de adjuntos; nunca repara ni elimina filas u objetos.';

    public function handle(): int
    {
        if (! $this->option('dry-run') || ! ctype_digit((string) $this->option('limit')) || ! ctype_digit((string) $this->option('older-hours'))) {
            $this->error('Utiliza --dry-run y límites enteros no negativos.');

            return self::FAILURE;
        }
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $cutoff = now()->subHours(min(87600, (int) $this->option('older-hours')));
        $counts = [];
        $checked = 0;
        $issue = function (string $code, string $identifier) use (&$counts) {
            $counts[$code] = ($counts[$code] ?? 0) + 1;
            $this->line($code.' '.$identifier);
        };
        foreach (DB::table('attachments')->orderBy('id')->limit($limit)->get() as $row) {
            $checked++;
            $id = 'attachment:'.$row->id;
            if ($row->status === 'pending_upload' && $row->updated_at < $cutoff->toDateTimeString()) {
                $issue('stale_reservation', $id);
            }
            if ($row->status === 'pending_deletion') {
                $issue('deletion_pending', $id);
            }
            $references = 0;
            foreach (['post_attachments', 'message_attachments', 'article_attachment', 'legal_resource_attachment', 'content_revision_attachments', 'verification_evidences'] as $table) {
                $references += DB::table($table)->where('attachment_id', $row->id)->exists() ? 1 : 0;
            }
            $references += DB::table('user_profiles')->where('photo_attachment_id', $row->id)->exists() ? 1 : 0;
            if ($references === 0 && $row->verification_retained_at === null) {
                $issue('unlinked_candidate_not_proven_orphan', $id);
            }
            if ($row->verification_retained_at !== null && $row->status !== 'available') {
                $issue('retained_nonavailable', $id);
            }
            if ($row->status === 'deleted' && ($references || $row->verification_retained_at !== null)) {
                $issue('deleted_but_referenced_or_retained', $id);
            }
            if ($row->size_bytes < 1 || ! preg_match('/^[a-f0-9]{64}$/', $row->sha256) || $row->visibility !== 'private') {
                $issue('invalid_metadata', $id);
            }
            try {
                $settings = config('filesystems.disks.'.$row->disk);
                if (! is_array($settings) || ! in_array($settings['driver'] ?? null, ['local', 's3'], true) || ($settings['visibility'] ?? null) !== 'private' || ($settings['serve'] ?? false) || isset($settings['url'])) {
                    $issue('unsafe_or_unknown_disk', $id);

                    continue;
                }
                if (($settings['driver'] ?? null) === 'local' && ! is_dir($settings['root'] ?? '')) {
                    $issue('local_root_missing', $id);

                    continue;
                }
                $disk = Storage::disk($row->disk);
                $exists = $disk->exists($row->path);
                if (! $exists && $row->status === 'available') {
                    $issue('available_object_missing', $id);
                }
                if (! $exists && $row->status === 'pending_deletion') {
                    $issue('pending_deletion_object_missing', $id);
                }
                if ($exists && $row->status === 'deleted') {
                    $issue('deleted_object_still_present', $id);
                }
                if ($exists && $disk->size($row->path) !== (int) $row->size_bytes) {
                    $issue('size_mismatch', $id);
                }
            } catch (Throwable) {
                $issue('storage_probe_failed', $id);
            }
        }
        if (isset($row) && DB::table('attachments')->where('id', '>', $row->id)->exists()) {
            $issue('records_truncated', 'database');
        }
        if ($this->option('enumerate')) {
            $name = config('attachments.disk');
            $settings = config('filesystems.disks.'.$name, []);
            // Enumeration is intentionally local only; do not accidentally list an entire remote bucket.
            if (($settings['driver'] ?? null) !== 'local' || ! is_dir($settings['root'] ?? '') || ($settings['visibility'] ?? null) !== 'private' || ($settings['serve'] ?? false) || isset($settings['url'])) {
                $issue('enumeration_not_supported', 'configured-disk');
            } else {
                try {
                    $seen = 0;
                    foreach (Storage::disk($name)->getDriver()->listContents('attachments', true) as $object) {
                        if (! $object->isFile()) {
                            continue;
                        }
                        if ($seen++ >= $limit) {
                            $issue('enumeration_truncated', 'configured-disk');
                            break;
                        }
                        if (! DB::table('attachments')->where('disk', $name)->where('path', $object->path())->exists()) {
                            $issue('object_without_known_row_candidate', 'object:'.substr(hash('sha256', $name.'|'.$object->path()), 0, 20));
                        }
                    }
                } catch (Throwable) {
                    $issue('enumeration_failed', 'configured-disk');
                }
            }
        }
        $this->line('diagnostic_only checked='.$checked.' limit='.$limit.' issues='.json_encode($counts));

        return self::SUCCESS;
    }
}
