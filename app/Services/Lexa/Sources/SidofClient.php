<?php

namespace App\Services\Lexa\Sources;

use App\Exceptions\LexaUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SidofClient
{
    private const EDITIONS = [
        'NotasMatutinas' => 'MAT',
        'NotasVespertinas' => 'VES',
        'NotasExtraordinarias' => 'EXT',
    ];

    /**
     * Fetch and normalize DOF publications from SIDOF for an explicit YYYY-MM-DD date.
     *
     * @return array{date: string, endpoint: string, message_code: int, publications: array<int, array<string, mixed>>}
     */
    public function fetchByDate(string $isoDate): array
    {
        $date = $this->parseDate($isoDate);
        $baseUrl = rtrim((string) config('services.sidof.base_url', 'https://sidof.segob.gob.mx'), '/');
        $allowedHosts = array_map('strtolower', (array) config('services.sidof.allowed_hosts', ['sidof.segob.gob.mx', 'www.dof.gob.mx', 'dof.gob.mx']));
        $this->assertAllowedUrl($baseUrl, $allowedHosts);

        $timeout = max(1, min(60, (int) config('services.sidof.timeout', 15)));
        $connectTimeout = max(1, min(15, (int) config('services.sidof.connect_timeout', 5)));
        $maxBytes = max(1024, (int) config('services.sidof.max_response_bytes', 2_000_000));

        $sidofDate = $date->format('d-m-Y');
        $endpoint = "/dof/sidof/notas/{$sidofDate}";
        $started = hrtime(true);

        try {
            $response = Http::baseUrl($baseUrl)
                ->accept('application/json, text/plain, */*')
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->get($endpoint);
        } catch (ConnectionException $error) {
            $this->logFailure($sidofDate, null, $started, $error);

            throw new LexaUnavailableException('El servicio SIDOF del DOF no respondió a tiempo.');
        } catch (Throwable $error) {
            $this->logFailure($sidofDate, null, $started, $error);

            throw new LexaUnavailableException('No fue posible conectar con el servicio SIDOF del DOF.');
        }

        if ($response->failed()) {
            $this->logFailure($sidofDate, $response->status(), $started);

            throw new LexaUnavailableException('El servicio SIDOF del DOF devolvió un error temporal.');
        }

        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            $this->logFailure($sidofDate, $response->status(), $started);

            throw new LexaUnavailableException('La respuesta de SIDOF excede el límite permitido.');
        }

        $data = $response->json();
        if (! is_array($data) || (int) ($data['messageCode'] ?? 0) !== 200) {
            $this->logFailure($sidofDate, $response->status(), $started);

            throw new LexaUnavailableException('La respuesta de SIDOF no tiene un formato JSON válido.');
        }

        $hasEditionArray = false;
        foreach (array_keys(self::EDITIONS) as $editionKey) {
            if (array_key_exists($editionKey, $data)) {
                if (! is_array($data[$editionKey])) {
                    $this->logFailure($sidofDate, $response->status(), $started);

                    throw new LexaUnavailableException('La respuesta de SIDOF no tiene la estructura esperada de notas.');
                }
                $hasEditionArray = true;
            }
        }

        if (! $hasEditionArray) {
            $this->logFailure($sidofDate, $response->status(), $started);

            throw new LexaUnavailableException('La respuesta de SIDOF no contiene colecciones de notas válidas.');
        }

        $publications = [];
        $seen = [];

        foreach (self::EDITIONS as $key => $editionCode) {
            $items = $data[$key] ?? null;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $normalized = $this->normalizeNote($item, $editionCode, $date, $baseUrl, $allowedHosts);
                if ($normalized === null) {
                    continue;
                }
                if (isset($seen[$normalized['cod_nota']])) {
                    continue;
                }
                $seen[$normalized['cod_nota']] = true;
                $publications[] = $normalized;
            }
        }

        return [
            'date' => $date->toDateString(),
            'endpoint' => $endpoint,
            'message_code' => (int) $data['messageCode'],
            'publications' => $publications,
        ];
    }

    private function parseDate(string $isoDate): CarbonImmutable
    {
        $trimmed = trim($isoDate);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            throw ValidationException::withMessages(['date' => 'La fecha debe tener el formato YYYY-MM-DD.']);
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $trimmed);
        if (! $parsed || $parsed->format('Y-m-d') !== $trimmed || $parsed->isFuture()) {
            throw ValidationException::withMessages(['date' => 'La fecha consultada no es válida o es futura.']);
        }

        return $parsed;
    }

    /**
     * @param  array<int, string>  $allowedHosts
     */
    private function assertAllowedUrl(string $url, array $allowedHosts): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https'
            || ! in_array($host, $allowedHosts, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new LexaUnavailableException('El host configurado para SIDOF no está permitido.');
        }
    }

    /**
     * @param  array<int, string>  $allowedHosts
     * @return array<string, mixed>|null
     */
    private function normalizeNote(mixed $item, string $editionCode, CarbonImmutable $date, string $baseUrl, array $allowedHosts): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $codNota = $item['codNota'] ?? null;
        if (! is_int($codNota) && ! (is_string($codNota) && ctype_digit($codNota))) {
            return null;
        }
        $codNota = (int) $codNota;
        if ($codNota <= 0) {
            return null;
        }

        $title = trim((string) ($item['titulo'] ?? ''));
        if ($title === '') {
            return null;
        }

        $officialUrl = "{$baseUrl}/notas/{$codNota}";
        if (is_string($item['url'] ?? null) && trim($item['url']) !== '') {
            $candidateUrl = trim($item['url']);
            $parts = parse_url($candidateUrl);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (($parts['scheme'] ?? '') !== 'https' || ! in_array($host, $allowedHosts, true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
                return null;
            }
            $officialUrl = $candidateUrl;
        }

        $publicationType = $this->classifyPublicationType($title);

        return [
            'cod_nota' => $codNota,
            'cod_diario' => isset($item['codDiario']) && is_numeric($item['codDiario']) ? (int) $item['codDiario'] : null,
            'edition' => $editionCode,
            'section' => is_string($item['codSeccion'] ?? null) ? trim($item['codSeccion']) : null,
            'published_at' => $date->toDateString(),
            'title' => mb_substr($title, 0, 500),
            'publication_type' => $publicationType,
            'agency_scope' => is_string($item['nombreCodOrgaUno'] ?? null) ? trim($item['nombreCodOrgaUno']) : null,
            'agency' => is_string($item['codOrgaDos'] ?? null) ? trim($item['codOrgaDos']) : null,
            'page_start' => isset($item['pagina']) && is_numeric($item['pagina']) ? (int) $item['pagina'] : null,
            'page_end' => isset($item['paginaHasta']) && is_numeric($item['paginaHasta']) ? (int) $item['paginaHasta'] : null,
            'has_html' => ($item['existeHtml'] ?? 'N') === 'S',
            'has_doc' => ($item['existeDoc'] ?? 'N') === 'S',
            'official_url' => $officialUrl,
        ];
    }

    private function classifyPublicationType(string $title): string
    {
        if (preg_match('/\b(?:decreto|ley|c[óo]digo|reglamento)\b/iu', $title)) {
            if (preg_match('/\b(?:reforma|reforman|adiciona|adicionan|deroga|derogan|abroga|expide|homologaci[óo]n)\b/iu', $title)) {
                return 'decree_or_reform';
            }

            return 'decree';
        }

        if (preg_match('/\b(?:acuerdo|lineamientos|reglas|resoluci[óo]n|circular)\b/iu', $title)) {
            return 'agreement_or_rule';
        }

        if (preg_match('/\b(?:aviso|convocatoria|declaratoria|notificaci[óo]n|tipo de cambio|tasa|extracto)\b/iu', $title)) {
            return 'notice';
        }

        return 'other';
    }

    private function logFailure(string $sidofDate, ?int $status, int $startedNs, ?Throwable $error = null): void
    {
        Log::warning('lexa.sidof.request_failed', [
            'provider' => 'sidof',
            'date' => $sidofDate,
            'status' => $status,
            'latency_ms' => (int) round((hrtime(true) - $startedNs) / 1_000_000),
            'exception_class' => $error ? get_class($error) : null,
        ]);
    }
}
