<?php

namespace Tests\Feature;

use App\Models\LexaLegalSource;
use App\Models\LexaSidofPublication;
use App\Models\User;
use App\Policies\LexaSidofPublicationPolicy;
use App\Services\Lexa\Sources\SidofPublicationTracker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SidofPublicationReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Config::set('services.sidof', [
            'base_url' => 'https://sidof.segob.gob.mx',
            'timeout' => 10,
            'connect_timeout' => 5,
            'max_response_bytes' => 2_097_152,
            'allowed_hosts' => ['sidof.segob.gob.mx', 'www.dof.gob.mx', 'dof.gob.mx'],
        ]);
    }

    public function test_record_command_is_idempotent_deduplicates_and_does_not_activate_legal_sources(): void
    {
        $source = LexaLegalSource::forceCreate([
            'instrument_key' => 'lfpdppp-2025',
            'version_key' => 'v1',
            'instrument' => 'Ley Federal de Protección de Datos Personales en Posesión de los Particulares',
            'short_name' => 'LFPDPPP',
            'norm_type' => 'federal_law',
            'jurisdiction_scope' => 'federal',
            'source_name' => 'Cámara de Diputados',
            'source_url' => 'https://www.diputados.gob.mx/LeyesBiblio/index.htm',
            'published_at' => '2025-03-20',
            'consulted_at' => '2025-03-20',
            'valid_from' => '2025-03-20',
            'document_hash' => hash('sha256', 'dummy-lfpdppp'),
            'is_verified' => false,
        ]);

        Http::fake([
            'https://sidof.segob.gob.mx/dof/sidof/notas/14-11-2025' => Http::response([
                'messageCode' => 200,
                'response' => 'OK',
                'NotasMatutinas' => [
                    [
                        'codNota' => 6001,
                        'titulo' => 'DECRETO por el que se reforma la Ley Federal de Protección de Datos Personales en Posesión de los Particulares.',
                        'codSeccion' => 'PRIMERA',
                        'fecha' => '14-11-2025',
                    ],
                    [
                        'codNota' => 6002,
                        'titulo' => 'DECRETO por el que se reforma la Ley General de Salud.',
                        'codSeccion' => 'PRIMERA',
                        'fecha' => '14-11-2025',
                    ],
                    [
                        'codNota' => 6003,
                        'titulo' => 'Aviso de registro constitutivo de asociación religiosa.',
                        'codSeccion' => 'UNICA',
                        'fecha' => '14-11-2025',
                    ],
                ],
                'NotasVespertinas' => [
                    [
                        // Duplicate codNota in second edition
                        'codNota' => 6001,
                        'titulo' => 'DECRETO duplicado.',
                        'fecha' => '14-11-2025',
                    ],
                ],
                'NotasExtraordinarias' => [],
            ], 200),
        ]);

        $this->artisan('lexa:sources:sidof', [
            'date' => '2025-11-14',
            '--record' => true,
            '--json' => true,
        ])->assertExitCode(0);

        // Only candidate publications (possible_match + needs_human_review) are stored; unmatched notice 6003 is skipped.
        $this->assertSame(2, LexaSidofPublication::query()->count());

        $first = LexaSidofPublication::query()->where('cod_nota', 6001)->firstOrFail();
        $this->assertSame('possible_match', $first->classification);
        $this->assertSame(['lfpdppp-2025'], $first->matched_instrument_keys);
        $this->assertSame(LexaSidofPublication::STATUS_PENDING_REVIEW, $first->review_status);
        $this->assertSame(1, $first->detection_count);
        $this->assertFalse($first->confirmed_reform);

        // Re-running --record on the same date must be idempotent without duplicating rows.
        $this->artisan('lexa:sources:sidof', [
            'date' => '2025-11-14',
            '--record' => true,
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertSame(2, LexaSidofPublication::query()->count());
        $first->refresh();
        $this->assertSame(2, $first->detection_count);

        // Existing legal source remains untouched and unverified.
        $source->refresh();
        $this->assertFalse($source->is_verified);
        $this->assertSame(1, DB::table('lexa_legal_sources')->count());
        $this->assertSame(0, DB::table('lexa_legal_chunks')->count());
    }

    public function test_conflicting_dry_run_and_record_flags_are_rejected(): void
    {
        $this->artisan('lexa:sources:sidof', [
            'date' => '2025-11-14',
            '--dry-run' => true,
            '--record' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, LexaSidofPublication::query()->count());
    }

    public function test_sidof_failure_does_not_write_partial_records(): void
    {
        Http::fake(function () {
            throw new ConnectionException('SIDOF timeout');
        });

        $this->artisan('lexa:sources:sidof', [
            'date' => '2025-11-14',
            '--record' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, LexaSidofPublication::query()->count());
    }

    public function test_incomplete_or_untrusted_candidate_records_are_skipped_safely(): void
    {
        $tracker = app(SidofPublicationTracker::class);

        $summary = $tracker->syncFromReport([
            'publications' => [
                [
                    'cod_nota' => 0,
                    'title' => 'Inválido codNota 0',
                    'published_at' => '2025-11-14',
                    'edition' => 'MAT',
                    'official_url' => 'https://sidof.segob.gob.mx/notas/0',
                    'publication_type' => 'decree',
                    'classification' => 'needs_human_review',
                ],
                [
                    'cod_nota' => 7001,
                    'title' => '',
                    'published_at' => '2025-11-14',
                    'edition' => 'MAT',
                    'official_url' => 'https://sidof.segob.gob.mx/notas/7001',
                    'publication_type' => 'decree',
                    'classification' => 'needs_human_review',
                ],
                [
                    'cod_nota' => 7002,
                    'title' => 'URL externa no permitida',
                    'published_at' => '2025-11-14',
                    'edition' => 'MAT',
                    'official_url' => 'https://evil.example.test/notas/7002',
                    'publication_type' => 'decree',
                    'classification' => 'needs_human_review',
                ],
                [
                    'cod_nota' => 7003,
                    'title' => 'DECRETO válido para análisis jurídico.',
                    'published_at' => '2025-11-14',
                    'edition' => 'MAT',
                    'official_url' => 'https://sidof.segob.gob.mx/notas/7003',
                    'publication_type' => 'decree_or_reform',
                    'classification' => 'needs_human_review',
                    'matched_instrument_keys' => [],
                ],
            ],
        ]);

        $this->assertSame(1, $summary['created']);
        $this->assertSame(3, $summary['skipped_incomplete']);
        $this->assertSame(1, LexaSidofPublication::query()->count());
    }

    public function test_only_authorized_reviewer_can_transition_status_and_human_decision_is_preserved(): void
    {
        Permission::findOrCreate(LexaSidofPublicationPolicy::PERMISSION_REVIEW, 'web');

        $regularUser = User::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(LexaSidofPublicationPolicy::PERMISSION_REVIEW);

        $tracker = app(SidofPublicationTracker::class);
        $tracker->syncFromReport([
            'publications' => [
                [
                    'cod_nota' => 8001,
                    'title' => 'DECRETO por el que se reforman diversas disposiciones.',
                    'published_at' => '2025-11-14',
                    'edition' => 'MAT',
                    'official_url' => 'https://sidof.segob.gob.mx/notas/8001',
                    'publication_type' => 'decree_or_reform',
                    'classification' => 'needs_human_review',
                    'matched_instrument_keys' => [],
                ],
            ],
        ]);

        $publication = LexaSidofPublication::query()->where('cod_nota', 8001)->firstOrFail();

        // Conventional user cannot change review status.
        try {
            $tracker->transitionReviewStatus($regularUser, $publication, LexaSidofPublication::STATUS_RELEVANT, 'Intento no autorizado');
            $this->fail('Expected AuthorizationException for regular user.');
        } catch (AuthorizationException) {
            $publication->refresh();
            $this->assertSame(LexaSidofPublication::STATUS_PENDING_REVIEW, $publication->review_status);
        }

        // Invalid status is rejected for authorized reviewer.
        try {
            $tracker->transitionReviewStatus($reviewer, $publication, 'confirmed_reform');
            $this->fail('Expected ValidationException for unsupported review status.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('review_status', $e->errors());
        }

        // Authorized reviewer transitions status and leaves audit trail.
        $updated = $tracker->transitionReviewStatus(
            $reviewer,
            $publication,
            LexaSidofPublication::STATUS_REQUIRES_LEGAL_ANALYSIS,
            'Verificar transitorios en texto completo oficial.'
        );

        $this->assertSame(LexaSidofPublication::STATUS_REQUIRES_LEGAL_ANALYSIS, $updated->review_status);
        $this->assertSame($reviewer->id, $updated->reviewed_by_user_id);
        $this->assertNotNull($updated->reviewed_at);
        $this->assertFalse($updated->confirmed_reform);
        $this->assertSame(1, $updated->reviewEvents()->count());

        $event = $updated->reviewEvents()->firstOrFail();
        $this->assertSame(LexaSidofPublication::STATUS_PENDING_REVIEW, $event->from_status);
        $this->assertSame(LexaSidofPublication::STATUS_REQUIRES_LEGAL_ANALYSIS, $event->to_status);
        $this->assertSame($reviewer->id, $event->user_id);

        // Re-syncing the same codNota must NOT overwrite the human-reviewed status.
        $tracker->syncFromReport([
            'publications' => [
                [
                    'cod_nota' => 8001,
                    'title' => 'DECRETO por el que se reforman diversas disposiciones.',
                    'published_at' => '2025-11-14',
                    'edition' => 'MAT',
                    'official_url' => 'https://sidof.segob.gob.mx/notas/8001',
                    'publication_type' => 'decree_or_reform',
                    'classification' => 'possible_match',
                    'matched_instrument_keys' => ['lfpdppp-2025'],
                ],
            ],
        ]);

        $updated->refresh();
        $this->assertSame(LexaSidofPublication::STATUS_REQUIRES_LEGAL_ANALYSIS, $updated->review_status);
        $this->assertSame(2, $updated->detection_count);
    }
}
