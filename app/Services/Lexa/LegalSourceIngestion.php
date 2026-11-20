<?php

namespace App\Services\Lexa;

use App\Models\LexaLegalChunk;
use App\Models\LexaLegalSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LegalSourceIngestion
{
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }

    public function import(array $metadata, string $text): LexaLegalSource
    {
        $data = Validator::make($metadata, [
            'instrument_key' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{0,99}$/'],
            'version_key' => ['required', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}$/'],
            'instrument' => ['required', 'string', 'max:180'],
            'short_name' => ['required', 'string', 'max:40'],
            'norm_type' => ['required', 'in:constitution,federal_law,regulation'],
            'jurisdiction_scope' => ['required', 'in:federal'],
            'state_id' => ['prohibited'],
            'source_url' => ['required', 'url:https', 'max:500'],
            'published_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:consulted_at'],
            'last_reformed_at' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:published_at', 'before_or_equal:consulted_at'],
            'consulted_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'valid_from' => ['present', 'nullable', 'date_format:Y-m-d'],
            'valid_to' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'extraction_note' => ['required', 'string', 'max:2000'],
            'allow_transitorios' => ['sometimes', 'boolean'],
            'is_verified' => ['prohibited'],
        ])->validate();
        $allowTransitorios = (bool) ($data['allow_transitorios'] ?? false);
        unset($data['allow_transitorios']);
        $url = parse_url($data['source_url']);
        $host = strtolower($url['host'] ?? '');
        $publishers = [
            'www.diputados.gob.mx' => 'Cámara de Diputados',
            'www.dof.gob.mx' => 'Diario Oficial de la Federación',
            'dof.gob.mx' => 'Diario Oficial de la Federación',
            'sidof.segob.gob.mx' => 'Diario Oficial de la Federación',
            'www.ordenjuridico.gob.mx' => 'Orden Jurídico Nacional',
        ];
        if (! isset($publishers[$host]) || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['fragment'])) {
            $this->reject('Se requiere una URL HTTPS de un editor federal admitido, sin credenciales, puerto ni fragmento.');
        }
        if (strlen($text) > 2_000_000 || ! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0") || trim($text) === '') {
            $this->reject('Se requiere texto UTF-8 no vacío, máximo 2 MB.');
        }
        $inputHash = hash('sha256', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $chunkChars = max(200, min(12000, (int) config('lexa.limits.legal_chunk_chars', 1200)));
        ['chunks' => $chunks, 'normalized_text' => $normalizedText] = $this->fragment($text, $chunkChars, $allowTransitorios);
        $identity = LexaLegalSource::where('instrument_key', $data['instrument_key'])->first();
        if ($identity && ($identity->norm_type !== $data['norm_type'] || $identity->instrument !== $data['instrument'])) {
            $this->reject('El identificador pertenece a otro instrumento o tipo de norma.');
        }
        $hash = hash('sha256', $normalizedText);
        $provenance = ['input_sha256' => $inputHash, 'extraction_note' => $data['extraction_note'], 'imported_at' => now()->toIso8601String(), 'format' => 'text/plain; charset=UTF-8', 'parser' => 'articles-v2', 'chunk_chars' => $chunkChars, 'allow_transitorios' => $allowTransitorios];
        unset($data['extraction_note']);

        // Unique key arbitrates concurrent retries; no existing content is overwritten.
        return DB::transaction(function () use ($data, $hash, $provenance, $publishers, $host, $chunks) {
            $source = LexaLegalSource::unguarded(fn () => LexaLegalSource::query()->firstOrCreate(
                ['instrument_key' => $data['instrument_key'], 'version_key' => $data['version_key']],
                $data + ['document_hash' => $hash, 'source_name' => $publishers[$host], 'provenance' => $provenance, 'is_verified' => false],
            ));
            if (! $source->wasRecentlyCreated) {
                foreach ($data as $key => $value) {
                    $actual = $source->getRawOriginal($key);
                    if ((string) $actual !== (string) $value) {
                        $this->reject('La versión existente tiene metadatos diferentes; utiliza una versión nueva.');
                    }
                }
                if (($source->provenance['input_sha256'] ?? null) !== $provenance['input_sha256'] || ($source->provenance['extraction_note'] ?? null) !== $provenance['extraction_note'] || (bool) ($source->provenance['allow_transitorios'] ?? false) !== $provenance['allow_transitorios']) {
                    $this->reject('La procedencia de la versión existente es diferente.');
                }
                if ($source->document_hash !== $hash) {
                    $this->reject('La versión existente contiene otro texto; utiliza una versión nueva.');
                }

                return $source;
            }
            foreach ($chunks as $chunk) {
                LexaLegalChunk::unguarded(fn () => $source->chunks()->create($chunk));
            }

            return $source;
        });
    }

    /**
     * @return array{chunks: array<int, array<string, mixed>>, normalized_text: string}
     */
    private function fragment(string $text, int $chunkChars = 1200, bool $allowTransitorios = false): array
    {
        $chunkChars = max(200, min(12000, $chunkChars));
        // Audit line starts before cutting ranges: an unrecognised heading must
        // never become part of the previous article. Horizontal whitespace only:
        // a reference on one line cannot borrow its separator from another line.
        $heading = '/^[\t ]*Artículo[\t ]+([0-9]+(?:o|º|°)?(?:[\t ]+(?:Bis|Ter|Quáter))?)[\t ]*[.\-–:][\t ]*/iu';
        $candidate = '/^[\t ]*Art[ií]culo(?=[\t 0-9])[\t ]*(?:[0-9]|Único\b|Primero\b|Segundo\b|Tercero\b|[IVXLCDM]+\b)/iu';
        $transMarker = '/^[\t ]*(?:Art[ií]culos[\t ]+)?Transitorios?[\t ]*:?[\t ]*$/iu';
        $decretoHeader = '/^[\t ]*ART[IÍ]CULOS[\t ]+TRANSITORIOS[\t ]+DE[\t ]+DECRETOS[\t ]+DE[\t ]+REFORMA[\t ]*$/iu';
        $decretoDate = '/^[\t ]*Publicado[\t ]+en[\t ]+el[\t ]+Diario[\t ]+Oficial[\t ]+de[\t ]+la[\t ]+Federaci[óo]n[\t ]+el[\t ]+([0-9]{1,2})[\t ]+de[\t ]+([a-z]+)[\t ]+de[\t ]+([0-9]{4})[\t ]*$/iu';
        $ordinals = '(?:Único|Primero|Segundo|Tercero|Cuarto|Quinto|Sexto|Séptimo|Octavo|Noveno|Décimo(?:[\t ]+(?:Primero|Segundo|Tercero|Cuarto|Quinto|Sexto|Séptimo|Octavo|Noveno))?|Vigésimo(?:[\t ]+(?:Primero|Segundo|Tercero|Cuarto|Quinto|Sexto|Séptimo|Octavo|Noveno))?)';
        // Conservative transitory heading: requires explicit "Transitorio <Ordinal>", "Artículo <Ordinal>" or "<Ordinal>." / "<Ordinal>.-" with dot, dash or colon.
        $transHeading = '/^[\t ]*(?:Transitorio[\t ]+('.$ordinals.')(?:[\t ]*\(DOF[\t ]+[0-9]{2}-[0-9]{2}-[0-9]{4}\))?[\t ]*[.\-–:]|Art[ií]culo[\t ]+('.$ordinals.')[\t ]*[.\-–:]|('.$ordinals.')[\t ]*(?:\.\-|\.|–|:))[\t ]*/iu';
        $structTag = '/^[\t ]*(LIBRO|T[IÍ]TULO|CAP[IÍ]TULO|SECCI[ÓO]N)[\t ]+(?:[IVXLCDM]+|ÚNICO|ÚNICA|PRIMERO|PRIMERA|SEGUNDO|SEGUNDA|TERCERO|TERCERA|CUARTO|CUARTA|QUINTO|QUINTA|SEXTO|SEXTA|SÉPTIMO|SÉPTIMA|OCTAVO|OCTAVA|NOVENO|NOVENA|DÉCIMO|DÉCIMA|[0-9]+)[\t ]*$/iu';

        $lines = explode("\n", $text);
        $n = count($lines);
        $i = 0;

        $state = ['libro' => null, 'titulo' => null, 'capitulo' => null, 'seccion' => null];
        $mode = 'substantive';
        $transLabel = 'Transitorio';
        $articles = [];
        $current = null;
        $seen = [];

        while ($i < $n) {
            $line = $lines[$i];
            $trimmed = trim($line);

            if (preg_match($decretoHeader, $trimmed)) {
                if (! $allowTransitorios) {
                    $this->reject('Referencias no soportadas: se requiere revisión manual.');
                }
                if ($current === null && $articles === []) {
                    $this->reject('El texto debe comenzar con un artículo numerado. Preámbulos y transitorios requieren edición separada y revisión manual.');
                }
                if ($current !== null) {
                    $articles[] = $current;
                    $current = null;
                }
                $mode = 'reform_preamble';
                $transLabel = null;
                $i++;

                continue;
            }

            if ($mode === 'reform_preamble') {
                if (preg_match($decretoDate, $trimmed, $dm)) {
                    $months = ['enero' => '01', 'febrero' => '02', 'marzo' => '03', 'abril' => '04', 'mayo' => '05', 'junio' => '06', 'julio' => '07', 'agosto' => '08', 'septiembre' => '09', 'octubre' => '10', 'noviembre' => '11', 'diciembre' => '12'];
                    $mNum = $months[mb_strtolower($dm[2])] ?? null;
                    if ($mNum === null) {
                        $this->reject('Referencias no soportadas: se requiere revisión manual.');
                    }
                    $transLabel = sprintf('Transitorio (DOF %02d-%s-%s)', (int) $dm[1], $mNum, $dm[3]);
                } elseif (preg_match($transMarker, $trimmed)) {
                    if ($transLabel === null) {
                        $this->reject('Referencias no soportadas: se requiere revisión manual.');
                    }
                    $mode = 'transitorios';
                } elseif ($trimmed !== '' && ! preg_match('/^(?:DECRETO\b|Artículo\b|[….]+$)/iu', $trimmed)) {
                    $this->reject('Referencias no soportadas: se requiere revisión manual.');
                }
                $i++;

                continue;
            }

            if ($mode === 'transitorios' && preg_match('/^[\t ]*(?:Transitorio[\t ]+'.$ordinals.'[\t ]*\(DOF[\t ]+([0-9]{2}-[0-9]{2}-[0-9]{4})\)[\t ]*[.\-–:])/iu', $line, $inlineDof)) {
                $transLabel = 'Transitorio (DOF '.$inlineDof[1].')';
            }

            if (preg_match($transMarker, $trimmed)) {
                if (! $allowTransitorios) {
                    $this->reject('Referencias no soportadas: se requiere revisión manual.');
                }
                if ($current === null && $articles === []) {
                    $this->reject('El texto debe comenzar con un artículo numerado. Preámbulos y transitorios requieren edición separada y revisión manual.');
                }
                if ($current !== null) {
                    $articles[] = $current;
                    $current = null;
                }
                $mode = 'transitorios';
                $i++;

                continue;
            }

            if ($mode === 'substantive') {
                if (preg_match($structTag, $trimmed, $sm)) {
                    $kind = mb_strtolower($sm[1]);
                    $label = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
                    $j = $i + 1;
                    while ($j < $n && trim($lines[$j]) === '') {
                        $j++;
                    }
                    if ($j < $n) {
                        $nextTrim = trim($lines[$j]);
                        if (! preg_match($candidate, $nextTrim) && ! preg_match($structTag, $nextTrim) && ! preg_match($transMarker, $nextTrim) && ! preg_match('/^[\t ]*Art[ií]culo\b/iu', $nextTrim)) {
                            $label .= ' — '.(preg_replace('/\s+/', ' ', $nextTrim) ?? $nextTrim);
                            $i = $j;
                        }
                    }
                    if (str_starts_with($kind, 'libro')) {
                        $state = ['libro' => $label, 'titulo' => null, 'capitulo' => null, 'seccion' => null];
                    } elseif (str_starts_with($kind, 't')) {
                        $state['titulo'] = $label;
                        $state['capitulo'] = null;
                        $state['seccion'] = null;
                    } elseif (str_starts_with($kind, 'cap')) {
                        $state['capitulo'] = $label;
                        $state['seccion'] = null;
                    } else {
                        $state['seccion'] = $label;
                    }
                    $i++;
                    while ($i < $n && trim($lines[$i]) === '') {
                        $i++;
                    }

                    continue;
                }

                // A line starting with the article label and a reference is ambiguous
                // if it is not supported. Inline prose ("conforme al artículo ...")
                // is not a candidate. Reject ambiguity rather than manufacture a cite.
                if (preg_match($candidate, $line) && ! preg_match($heading, $line)) {
                    $this->reject('Encabezado de artículo no soportado o ambiguo; revisa el documento completo.');
                }

                if (preg_match($heading, $line, $m)) {
                    if ($current !== null) {
                        $articles[] = $current;
                    }
                    $ref = 'Artículo '.$m[1];
                    if (isset($seen[mb_strtolower($ref)])) {
                        $this->reject('Referencia de artículo duplicada o ambigua.');
                    }
                    $seen[mb_strtolower($ref)] = true;
                    $headingStr = implode(' / ', array_filter(array_values($state)));
                    $current = [
                        'ref' => $ref,
                        'heading' => $headingStr !== '' ? mb_substr($headingStr, 0, 200) : null,
                        'lines' => [$line],
                    ];
                    $i++;

                    continue;
                }

                if ($current === null) {
                    if ($trimmed !== '') {
                        $this->reject('El texto debe comenzar con un artículo numerado. Preámbulos y transitorios requieren edición separada y revisión manual.');
                    }
                    $i++;

                    continue;
                }

                if (preg_match('/^\s*(?:TRANSITORIOS|Artículo\s+(?![0-9]))/iu', $line)) {
                    $this->reject('Referencias no soportadas: se requiere revisión manual.');
                }

                $current['lines'][] = $line;
                $i++;
            } else {
                if (preg_match($transHeading, $line, $tm)) {
                    if ($current !== null) {
                        $articles[] = $current;
                    }
                    $rawOrd = $tm[1] !== '' ? $tm[1] : ($tm[2] !== '' ? $tm[2] : $tm[3]);
                    $ordName = mb_convert_case(preg_replace('/\s+/', ' ', trim($rawOrd)) ?? trim($rawOrd), MB_CASE_TITLE, 'UTF-8');
                    $ref = $transLabel === 'Transitorio'
                        ? 'Transitorio '.$ordName
                        : str_replace('Transitorio (', 'Transitorio '.$ordName.' (', $transLabel);
                    if (isset($seen[mb_strtolower($ref)])) {
                        $this->reject('Referencia de artículo duplicada o ambigua.');
                    }
                    $seen[mb_strtolower($ref)] = true;
                    $current = [
                        'ref' => $ref,
                        'heading' => $transLabel === 'Transitorio' ? 'Disposiciones Transitorias' : 'Transitorios de Reforma '.substr($transLabel, 12),
                        'lines' => [$line],
                    ];
                    $i++;

                    continue;
                }

                if (preg_match('/^[\t ]*(?:Art[ií]culo\b|'.$ordinals.'\b)/iu', $line)) {
                    $this->reject('Referencias no soportadas: se requiere revisión manual.');
                }

                if ($current === null) {
                    if ($trimmed !== '') {
                        $this->reject('Referencias no soportadas: se requiere revisión manual.');
                    }
                    $i++;

                    continue;
                }

                $current['lines'][] = $line;
                $i++;
            }
        }

        if ($current !== null) {
            $articles[] = $current;
        }

        if ($articles === []) {
            $this->reject('El texto debe comenzar con un artículo numerado. Preámbulos y transitorios requieren edición separada y revisión manual.');
        }

        if ($mode === 'transitorios' && ! str_starts_with($articles[count($articles) - 1]['ref'], 'Transitorio')) {
            $this->reject('Referencias no soportadas: se requiere revisión manual.');
        }

        $out = [];
        $normalizedPieces = [];
        $totalArticles = count($articles);
        foreach ($articles as $artIdx => $art) {
            $content = implode("\n", $art['lines']);
            if ($artIdx < $totalArticles - 1) {
                $content .= "\n";
            }
            $parts = $this->splitArticle($content, $chunkChars);
            foreach ($parts as $partIdx => $piece) {
                $out[] = [
                    'article_ref' => $art['ref'],
                    'heading' => $art['heading'],
                    'content' => $piece,
                    'content_hash' => hash('sha256', $piece),
                    'ordinal' => count($out) + 1,
                    'part' => $partIdx + 1,
                ];
                $normalizedPieces[] = $piece;
            }
        }

        return ['chunks' => $out, 'normalized_text' => implode('', $normalizedPieces)];
    }

    /**
     * @return array<int, string>
     */
    private function splitArticle(string $content, int $maxChars): array
    {
        if (mb_strlen($content) <= $maxChars && strlen($content) <= 12000) {
            return [$content];
        }

        $parts = [];
        $remaining = $content;
        $minBoundary = max(1, (int) ($maxChars * 0.25));
        while ($remaining !== '') {
            if (mb_strlen($remaining) <= $maxChars && strlen($remaining) <= 12000) {
                $parts[] = $remaining;
                break;
            }
            $window = mb_substr($remaining, 0, $maxChars);
            if (strlen($window) > 12000) {
                $window = mb_strcut($window, 0, 12000, 'UTF-8');
            }
            $cutPos = null;
            $lastNl = mb_strrpos($window, "\n");
            if ($lastNl !== false && $lastNl >= $minBoundary) {
                $cutPos = $lastNl + 1;
            } else {
                $lastDot = mb_strrpos($window, '. ');
                if ($lastDot !== false && $lastDot >= $minBoundary) {
                    $cutPos = $lastDot + 2;
                } else {
                    $lastSpace = mb_strrpos($window, ' ');
                    if ($lastSpace !== false && $lastSpace >= $minBoundary) {
                        $cutPos = $lastSpace + 1;
                    }
                }
            }
            $piece = $cutPos !== null ? mb_substr($window, 0, $cutPos) : $window;
            $parts[] = $piece;
            $remaining = substr($remaining, strlen($piece));
        }

        return $parts;
    }

    public function verify(int $id, string $expectedHash, string $reviewer, string $note): LexaLegalSource
    {
        Validator::make(['hash' => $expectedHash, 'reviewer' => $reviewer, 'note' => $note], [
            'hash' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'reviewer' => ['required', 'string', 'max:120'], 'note' => ['required', 'string', 'min:20', 'max:4000'],
        ])->validate();

        return DB::transaction(function () use ($id, $expectedHash, $reviewer, $note) {
            $candidate = LexaLegalSource::findOrFail($id);
            if (! $candidate->instrument_key) {
                $this->reject('No es una importación con procedencia.');
            }
            $versions = LexaLegalSource::where('instrument_key', $candidate->instrument_key)->orderBy('id')->lockForUpdate()->get();
            $source = $versions->firstWhere('id', $id);
            foreach ($versions as $version) {
                if ($version->instrument !== $source->instrument || $version->norm_type !== $source->norm_type || $version->jurisdiction_scope !== 'federal' || $version->state_id !== null) {
                    $this->reject('Identidad o jurisdicción inconsistente entre versiones.');
                }
            }
            if ($source->document_hash !== $expectedHash || $source->superseded_by || ! $source->valid_from || $source->valid_from->isFuture() || ($source->valid_to && $source->valid_to->isBefore(today()))) {
                $this->reject('Hash, versión o vigencia no permiten activar el documento.');
            }
            $text = '';
            $actualChunks = $source->chunks()->orderBy('ordinal')->get();
            foreach ($actualChunks as $chunk) {
                if (hash('sha256', $chunk->content) !== $chunk->content_hash) {
                    $this->reject('Integridad de fragmento incorrecta.');
                }
                $text .= $chunk->content;
            }
            if (hash('sha256', $text) !== $expectedHash) {
                $this->reject('Los fragmentos no reconstruyen el documento.');
            }
            $chunkChars = max(200, min(12000, (int) ($source->provenance['chunk_chars'] ?? (($source->provenance['parser'] ?? '') === 'articles-v1' ? 12000 : config('lexa.limits.legal_chunk_chars', 1200)))));
            $allowTransitorios = (bool) ($source->provenance['allow_transitorios'] ?? false);
            if (($source->provenance['parser'] ?? '') === 'articles-v2' && ($actualChunks->some(fn ($c) => $c->heading !== null) || $allowTransitorios)) {
                // Reconstruct with structural headings and transitorios delimiters so fragment() re-validates every line and boundary.
                $reconstructedInput = [];
                $lastHeading = null;
                $inTransitorios = false;
                $lastReformDof = null;
                foreach ($actualChunks as $c) {
                    if (str_starts_with((string) $c->article_ref, 'Transitorio')) {
                        if (preg_match('/\(DOF ([0-9]{2})-([0-9]{2})-([0-9]{4})\)$/', (string) $c->article_ref, $dm)) {
                            $dofKey = $dm[1].'-'.$dm[2].'-'.$dm[3];
                            if ($lastReformDof !== $dofKey) {
                                $months = ['01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril', '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto', '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'];
                                $reconstructedInput[] = "ARTÍCULOS TRANSITORIOS DE DECRETOS DE REFORMA\nPublicado en el Diario Oficial de la Federación el ".((int) $dm[1]).' de '.$months[$dm[2]].' de '.$dm[3]."\nTransitorios\n";
                                $lastReformDof = $dofKey;
                            }
                        } elseif (! $inTransitorios) {
                            $reconstructedInput[] = "Transitorios\n";
                            $inTransitorios = true;
                        }
                    } elseif ($c->heading !== null && $c->heading !== $lastHeading) {
                        foreach (explode(' / ', $c->heading) as $seg) {
                            $parts = explode(' — ', $seg, 2);
                            $reconstructedInput[] = $parts[0]."\n".(isset($parts[1]) ? $parts[1]."\n" : '');
                        }
                        $lastHeading = $c->heading;
                    }
                    $reconstructedInput[] = $c->content;
                }
                $expectedChunks = $this->fragment(implode('', $reconstructedInput), $chunkChars, $allowTransitorios)['chunks'];
            } else {
                $expectedChunks = $this->fragment($text, $chunkChars, $allowTransitorios)['chunks'];
            }
            if (count($expectedChunks) !== $actualChunks->count()) {
                $this->reject('Fragmentación incorrecta.');
            }
            foreach ($expectedChunks as $index => $expected) {
                foreach ($expected as $field => $value) {
                    if ($field === 'heading' && ($source->provenance['parser'] ?? '') !== 'articles-v2') {
                        continue;
                    }
                    if ((string) $actualChunks[$index]->$field !== (string) $value) {
                        $this->reject('Referencia o posición alterada.');
                    }
                }
            }
            if ($source->is_verified) {
                return $source;
            }
            foreach ($versions->where('is_verified', true) as $old) {
                // Older effective versions cannot silently displace a newer one.
                if ($old->valid_from > $source->valid_from || ($old->last_reformed_at && (! $source->last_reformed_at || $old->last_reformed_at > $source->last_reformed_at))) {
                    $this->reject('La versión revisada es anterior a la activa.');
                }
                $old->forceFill(['is_verified' => false, 'superseded_by' => $id])->save();
            }
            $source->forceFill(['is_verified' => true, 'verified_at' => now(), 'verified_by' => $reviewer, 'verification_note' => $note])->save();

            return $source;
        });
    }
}
