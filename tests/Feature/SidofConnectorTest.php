<?php

namespace Tests\Feature;

use App\Exceptions\LexaUnavailableException;
use App\Models\LexaLegalSource;
use App\Services\Lexa\Sources\SidofClient;
use App\Services\Lexa\Sources\SidofReformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SidofConnectorTest extends TestCase
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

    public function test_it_fetches_and_normalizes_multiple_editions_and_deduplicates_cod_nota(): void
    {
        Http::fake([
            'https://sidof.segob.gob.mx/dof/sidof/notas/14-11-2025' => Http::response([
                'messageCode' => 200,
                'response' => 'OK',
                'NotasMatutinas' => [
                    [
                        'codNota' => 5773101,
                        'titulo' => 'DECRETO por el que se reforman diversas disposiciones de la Ley Federal de Protección de Datos Personales en Posesión de los Particulares.',
                        'codSeccion' => 'PRIMERA',
                        'fecha' => '14-11-2025',
                        'codDiario' => 123456,
                        'existeHtml' => 'S',
                        'existeDoc' => 'S',
                        'existeImagen' => 'N',
                        'tipoNota' => 'null',
                        'estado' => 2,
                        'pagina' => 1,
                        'paginaHasta' => 4,
                        'nombreCodOrgaUno' => 'PODER EJECUTIVO',
                        'codOrgaUno' => 'PE',
                        'codOrgaDos' => 'SECRETARIA DE GOBERNACION',
                        'orden' => 1,
                    ],
                    [
                        // Index/cover item without titulo must be discarded safely
                        'codNota' => 5441022,
                        'codSeccion' => 1,
                        'fecha' => '14-11-2025',
                        'orden' => 0,
                    ],
                ],
                'NotasVespertinas' => [
                    [
                        // Duplicate codNota should be deduplicated
                        'codNota' => 5773101,
                        'titulo' => 'DECRETO duplicado',
                        'codSeccion' => 1,
                        'fecha' => '14-11-2025',
                    ],
                    [
                        'codNota' => 5773102,
                        'titulo' => 'ACUERDO por el que se emiten los lineamientos técnicos.',
                        'codSeccion' => 2,
                        'fecha' => '14-11-2025',
                        'codDiario' => 123457,
                        'existeHtml' => 'S',
                        'existeDoc' => 'N',
                        'nombreCodOrgaUno' => 'SECRETARÍA DE ECONOMÍA',
                    ],
                    [
                        'codNota' => 5773199,
                        'titulo' => 'Nota con URL no permitida.',
                        'url' => 'http://evil.example.test/phish',
                    ],
                ],
                'NotasExtraordinarias' => [
                    [
                        'codNota' => 5773103,
                        'titulo' => 'AVISO de consulta pública.',
                        'codSeccion' => 3,
                        'fecha' => '14-11-2025',
                        'codDiario' => 123458,
                        'existeHtml' => 'N',
                        'existeDoc' => 'N',
                    ],
                ],
            ], 200),
        ]);

        $client = app(SidofClient::class);
        $result = $client->fetchByDate('2025-11-14');

        $this->assertSame('2025-11-14', $result['date']);
        $this->assertSame(200, $result['message_code']);
        $this->assertCount(3, $result['publications']);

        $first = $result['publications'][0];
        $this->assertSame(5773101, $first['cod_nota']);
        $this->assertSame('MAT', $first['edition']);
        $this->assertSame('decree_or_reform', $first['publication_type']);
        $this->assertSame('https://sidof.segob.gob.mx/notas/5773101', $first['official_url']);

        $second = $result['publications'][1];
        $this->assertSame(5773102, $second['cod_nota']);
        $this->assertSame('VES', $second['edition']);
        $this->assertSame('agreement_or_rule', $second['publication_type']);
        $this->assertTrue($second['has_html']);
        $this->assertFalse($second['has_doc']);
        $this->assertSame('https://sidof.segob.gob.mx/notas/5773102', $second['official_url']);

        $third = $result['publications'][2];
        $this->assertSame(5773103, $third['cod_nota']);
        $this->assertSame('EXT', $third['edition']);
        $this->assertSame('notice', $third['publication_type']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://sidof.segob.gob.mx/dof/sidof/notas/14-11-2025'
                && $request->header('Accept') === ['application/json, text/plain, */*'];
        });
    }

    public function test_it_rejects_invalid_and_future_dates(): void
    {
        $client = app(SidofClient::class);

        try {
            $client->fetchByDate('14-11-2025');
            $this->fail('Expected ValidationException for non-ISO date.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }

        $futureDate = now()->addDays(5)->format('Y-m-d');

        try {
            $client->fetchByDate($futureDate);
            $this->fail('Expected ValidationException for future date.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }
    }

    public function test_it_handles_empty_sidof_response(): void
    {
        Http::fake([
            'https://sidof.segob.gob.mx/dof/sidof/notas/01-01-2025' => Http::response([
                'messageCode' => 200,
                'response' => 'OK',
                'NotasMatutinas' => [],
                'NotasVespertinas' => [],
                'NotasExtraordinarias' => [],
            ], 200),
        ]);

        $result = app(SidofClient::class)->fetchByDate('2025-01-01');

        $this->assertSame(200, $result['message_code']);
        $this->assertSame([], $result['publications']);
    }

    public function test_it_throws_controlled_exception_on_malformed_json(): void
    {
        Http::fake([
            'https://sidof.segob.gob.mx/dof/sidof/notas/14-11-2025' => Http::response('{not-valid-json', 200),
        ]);

        $this->expectException(LexaUnavailableException::class);

        app(SidofClient::class)->fetchByDate('2025-11-14');
    }

    public function test_it_rejects_http_200_with_unexpected_structure_instead_of_returning_empty_list(): void
    {
        Http::fake([
            'https://sidof.segob.gob.mx/dof/sidof/notas/14-11-2025' => Http::response([
                'messageCode' => 200,
                'response' => 'OK',
                'unexpectedField' => [],
            ], 200),
        ]);

        $this->expectException(LexaUnavailableException::class);

        app(SidofClient::class)->fetchByDate('2025-11-14');
    }

    public function test_it_throws_controlled_exception_on_connection_timeout(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(LexaUnavailableException::class);

        app(SidofClient::class)->fetchByDate('2025-11-14');
    }

    public function test_it_throws_controlled_exception_on_http_error_status(): void
    {
        Http::fake([
            'https://sidof.segob.gob.mx/dof/sidof/notas/14-11-2025' => Http::response(['error' => 'Server Error'], 503),
        ]);

        $this->expectException(LexaUnavailableException::class);

        app(SidofClient::class)->fetchByDate('2025-11-14');
    }

    public function test_it_rejects_disallowed_base_url_host(): void
    {
        Config::set('services.sidof.base_url', 'https://untrusted.example.com');

        $this->expectException(LexaUnavailableException::class);

        app(SidofClient::class)->fetchByDate('2025-11-14');
    }

    public function test_detector_classifies_possible_match_needs_human_review_and_unmatched_conservatively(): void
    {
        LexaLegalSource::forceCreate([
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
                        'codNota' => 1001,
                        'titulo' => 'DECRETO por el que se reforma la Ley Federal de Protección de Datos Personales en Posesión de los Particulares.',
                        'fecha' => '14-11-2025',
                    ],
                    [
                        'codNota' => 1002,
                        'titulo' => 'DECRETO por el que se reforman diversas disposiciones de la Ley General de Salud.',
                        'fecha' => '14-11-2025',
                    ],
                    [
                        'codNota' => 1003,
                        'titulo' => 'AVISO de inicio de vigencia de norma oficial.',
                        'fecha' => '14-11-2025',
                    ],
                ],
                'NotasVespertinas' => [],
                'NotasExtraordinarias' => [],
            ], 200),
        ]);

        $report = app(SidofReformDetector::class)->inspectDate('2025-11-14');

        $this->assertTrue($report['dry_run']);
        $this->assertFalse($report['writes_performed']);
        $this->assertSame(3, $report['total_publications']);
        $this->assertSame(1, $report['summary']['possible_match']);
        $this->assertSame(1, $report['summary']['needs_human_review']);
        $this->assertSame(1, $report['summary']['unmatched']);

        $match = $report['publications'][0];
        $this->assertSame('possible_match', $match['classification']);
        $this->assertSame(['lfpdppp-2025'], $match['matched_instrument_keys']);
        $this->assertTrue($match['requires_human_review']);
        $this->assertFalse($match['confirmed_reform']);
        $this->assertFalse($match['effective_date_confirmed']);
    }

    public function test_artisan_command_runs_in_dry_run_mode_without_modifying_database(): void
    {
        LexaLegalSource::forceCreate([
            'instrument_key' => 'lgmasc-2024',
            'version_key' => 'v1',
            'instrument' => 'Ley General de Mecanismos Alternativos de Solución de Controversias',
            'short_name' => 'LGMASC',
            'norm_type' => 'federal_law',
            'jurisdiction_scope' => 'federal',
            'source_name' => 'Cámara de Diputados',
            'source_url' => 'https://www.diputados.gob.mx/LeyesBiblio/index.htm',
            'published_at' => '2024-01-26',
            'consulted_at' => '2024-01-26',
            'valid_from' => '2024-01-26',
            'document_hash' => hash('sha256', 'dummy-lgmasc'),
            'is_verified' => false,
        ]);

        Http::fake([
            'https://sidof.segob.gob.mx/dof/sidof/notas/26-01-2024' => Http::response([
                'messageCode' => 200,
                'response' => 'OK',
                'NotasMatutinas' => [
                    [
                        'codNota' => 2001,
                        'titulo' => 'DECRETO por el que se expide la Ley General de Mecanismos Alternativos de Solución de Controversias.',
                        'fecha' => '26-01-2024',
                    ],
                ],
                'NotasVespertinas' => [],
                'NotasExtraordinarias' => [],
            ], 200),
        ]);

        $sourcesBefore = DB::table('lexa_legal_sources')->count();
        $chunksBefore = DB::table('lexa_legal_chunks')->count();

        $this->artisan('lexa:sources:sidof', [
            'date' => '2024-01-26',
            '--dry-run' => true,
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertSame($sourcesBefore, DB::table('lexa_legal_sources')->count());
        $this->assertSame($chunksBefore, DB::table('lexa_legal_chunks')->count());
    }
}
