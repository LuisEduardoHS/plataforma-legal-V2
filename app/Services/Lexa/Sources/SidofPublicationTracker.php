<?php

namespace App\Services\Lexa\Sources;

use App\Models\LexaSidofPublication;
use App\Models\LexaSidofPublicationReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SidofPublicationTracker
{
    /** @var array<int, string> */
    private const CANDIDATE_CLASSIFICATIONS = ['possible_match', 'needs_human_review'];

    /**
     * Persist potentially relevant SIDOF publications idempotently by cod_nota.
     *
     * @param  array<string, mixed>  $report
     * @return array{created: int, existing: int, pending_review: int, skipped_unmatched: int, skipped_incomplete: int}
     */
    public function syncFromReport(array $report, bool $includeUnmatched = false): array
    {
        $publications = is_array($report['publications'] ?? null) ? $report['publications'] : [];
        $allowedHosts = array_map('strtolower', (array) config('services.sidof.allowed_hosts', ['sidof.segob.gob.mx', 'www.dof.gob.mx', 'dof.gob.mx']));
        $now = CarbonImmutable::now();

        $created = 0;
        $existing = 0;
        $skippedUnmatched = 0;
        $skippedIncomplete = 0;

        DB::transaction(function () use (
            $publications,
            $includeUnmatched,
            $allowedHosts,
            $now,
            &$created,
            &$existing,
            &$skippedUnmatched,
            &$skippedIncomplete,
        ): void {
            foreach ($publications as $item) {
                if (! is_array($item)) {
                    $skippedIncomplete++;

                    continue;
                }

                $classification = (string) ($item['classification'] ?? '');
                if (! $includeUnmatched && ! in_array($classification, self::CANDIDATE_CLASSIFICATIONS, true)) {
                    $skippedUnmatched++;

                    continue;
                }

                $validated = $this->validateCandidate($item, $allowedHosts);
                if ($validated === null) {
                    $skippedIncomplete++;

                    continue;
                }

                $record = LexaSidofPublication::query()
                    ->where('cod_nota', $validated['cod_nota'])
                    ->lockForUpdate()
                    ->first();

                if ($record === null) {
                    $record = new LexaSidofPublication;
                    $record->cod_nota = $validated['cod_nota'];
                    $record->cod_diario = $validated['cod_diario'];
                    $record->published_at = $validated['published_at'];
                    $record->edition = $validated['edition'];
                    $record->section = $validated['section'];
                    $record->title = $validated['title'];
                    $record->official_url = $validated['official_url'];
                    $record->preliminary_type = $validated['preliminary_type'];
                    $record->classification = $validated['classification'];
                    $record->matched_instrument_keys = $validated['matched_instrument_keys'];
                    $record->agency_scope = $validated['agency_scope'];
                    $record->agency = $validated['agency'];
                    $record->review_status = LexaSidofPublication::STATUS_PENDING_REVIEW;
                    $record->first_detected_at = $now;
                    $record->last_detected_at = $now;
                    $record->detection_count = 1;
                    $record->confirmed_reform = false;
                    try {
                        $record->save();
                        $created++;

                        continue;
                    } catch (UniqueConstraintViolationException) {
                        $record = LexaSidofPublication::query()
                            ->where('cod_nota', $validated['cod_nota'])
                            ->lockForUpdate()
                            ->firstOrFail();
                    }
                }

                $record->last_detected_at = $now;
                $record->detection_count = (int) $record->detection_count + 1;

                if ($record->review_status === LexaSidofPublication::STATUS_PENDING_REVIEW) {
                    $record->classification = $validated['classification'];
                    $record->matched_instrument_keys = $validated['matched_instrument_keys'];
                    $record->preliminary_type = $validated['preliminary_type'];
                }

                $record->save();
                $existing++;
            }
        });

        $pendingReview = LexaSidofPublication::query()
            ->where('review_status', LexaSidofPublication::STATUS_PENDING_REVIEW)
            ->count();

        return [
            'created' => $created,
            'existing' => $existing,
            'pending_review' => $pendingReview,
            'skipped_unmatched' => $skippedUnmatched,
            'skipped_incomplete' => $skippedIncomplete,
        ];
    }

    /**
     * Transition human review status with authorization and audit trail.
     */
    public function transitionReviewStatus(
        User $actor,
        LexaSidofPublication $publication,
        string $toStatus,
        ?string $notes = null,
    ): LexaSidofPublication {
        Gate::forUser($actor)->authorize('review', $publication);

        if (! in_array($toStatus, LexaSidofPublication::REVIEW_STATUSES, true)) {
            throw ValidationException::withMessages([
                'review_status' => 'Estado de revisión inválido.',
            ]);
        }

        $cleanNotes = $notes !== null ? mb_substr(trim($notes), 0, 2000) : null;
        if ($cleanNotes === '') {
            $cleanNotes = null;
        }

        return DB::transaction(function () use ($actor, $publication, $toStatus, $cleanNotes): LexaSidofPublication {
            $locked = LexaSidofPublication::query()->lockForUpdate()->findOrFail($publication->id);
            $fromStatus = (string) $locked->review_status;

            $locked->review_status = $toStatus;
            $locked->reviewed_by_user_id = $actor->id;
            $locked->reviewed_at = CarbonImmutable::now();
            $locked->review_notes = $cleanNotes;
            // Human review of a SIDOF notice never automatically confirms a legal reform.
            $locked->confirmed_reform = false;
            $locked->save();

            $event = new LexaSidofPublicationReview;
            $event->lexa_sidof_publication_id = $locked->id;
            $event->user_id = $actor->id;
            $event->from_status = $fromStatus;
            $event->to_status = $toStatus;
            $event->notes = $cleanNotes;
            $event->save();

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $allowedHosts
     * @return array<string, mixed>|null
     */
    private function validateCandidate(array $item, array $allowedHosts): ?array
    {
        $codNota = $item['cod_nota'] ?? null;
        if (! is_int($codNota) && ! (is_string($codNota) && ctype_digit($codNota))) {
            return null;
        }
        $codNota = (int) $codNota;
        if ($codNota <= 0) {
            return null;
        }

        $title = trim((string) ($item['title'] ?? ''));
        $publishedAt = trim((string) ($item['published_at'] ?? ''));
        $edition = trim((string) ($item['edition'] ?? ''));
        $officialUrl = trim((string) ($item['official_url'] ?? ''));
        $preliminaryType = trim((string) ($item['publication_type'] ?? ''));
        $classification = trim((string) ($item['classification'] ?? ''));

        if ($title === '' || $publishedAt === '' || $edition === '' || $officialUrl === '' || $preliminaryType === '' || $classification === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $publishedAt)) {
            return null;
        }

        $urlParts = parse_url($officialUrl);
        $host = strtolower((string) ($urlParts['host'] ?? ''));
        if (($urlParts['scheme'] ?? '') !== 'https'
            || ! in_array($host, $allowedHosts, true)
            || isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['port'])) {
            return null;
        }

        $matchedKeys = is_array($item['matched_instrument_keys'] ?? null)
            ? array_values(array_filter(array_map('strval', $item['matched_instrument_keys'])))
            : [];

        return [
            'cod_nota' => $codNota,
            'cod_diario' => isset($item['cod_diario']) && is_numeric($item['cod_diario']) ? (int) $item['cod_diario'] : null,
            'published_at' => $publishedAt,
            'edition' => mb_substr($edition, 0, 10),
            'section' => is_string($item['section'] ?? null) && trim($item['section']) !== '' ? mb_substr(trim($item['section']), 0, 60) : null,
            'title' => mb_substr($title, 0, 500),
            'official_url' => mb_substr($officialUrl, 0, 500),
            'preliminary_type' => mb_substr($preliminaryType, 0, 40),
            'classification' => mb_substr($classification, 0, 40),
            'matched_instrument_keys' => $matchedKeys,
            'agency_scope' => is_string($item['agency_scope'] ?? null) && trim($item['agency_scope']) !== '' ? mb_substr(trim($item['agency_scope']), 0, 180) : null,
            'agency' => is_string($item['agency'] ?? null) && trim($item['agency']) !== '' ? mb_substr(trim($item['agency']), 0, 180) : null,
        ];
    }
}
