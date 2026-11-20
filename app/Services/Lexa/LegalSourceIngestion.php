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
            'is_verified' => ['prohibited'],
        ])->validate();
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
        $chunks = $this->fragment($text);
        $identity = LexaLegalSource::where('instrument_key', $data['instrument_key'])->first();
        if ($identity && ($identity->norm_type !== $data['norm_type'] || $identity->instrument !== $data['instrument'])) {
            $this->reject('El identificador pertenece a otro instrumento o tipo de norma.');
        }
        $hash = hash('sha256', $text);
        $provenance = ['input_sha256' => $inputHash, 'extraction_note' => $data['extraction_note'], 'imported_at' => now()->toIso8601String(), 'format' => 'text/plain; charset=UTF-8', 'parser' => 'articles-v1'];
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
                if (($source->provenance['input_sha256'] ?? null) !== $provenance['input_sha256'] || ($source->provenance['extraction_note'] ?? null) !== $provenance['extraction_note']) {
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

    private function fragment(string $text): array
    {
        // Audit line starts before cutting ranges: an unrecognised heading must
        // never become part of the previous article. Horizontal whitespace only:
        // a reference on one line cannot borrow its separator from another line.
        $heading = '/^[\t ]*Artículo[\t ]+([0-9]+(?:o|º|°)?(?:[\t ]+(?:Bis|Ter|Quáter))?)[\t ]*[.\-–:][\t ]*/miu';
        foreach (explode("\n", $text) as $line) {
            // A line starting with the article label and a reference is ambiguous
            // if it is not supported. Inline prose ("conforme al artículo ...")
            // is not a candidate. Reject ambiguity rather than manufacture a cite.
            $candidate = preg_match('/^[\t ]*Art[ií]culo(?=[\t 0-9])[\t ]*(?:[0-9]|Único\b|Primero\b|Segundo\b|Tercero\b|[IVXLCDM]+\b)/iu', $line);
            if ($candidate && ! preg_match($heading, $line)) {
                $this->reject('Encabezado de artículo no soportado o ambiguo; revisa el documento completo.');
            }
        }
        preg_match_all($heading, $text, $matches, PREG_OFFSET_CAPTURE);
        if (count($matches[0]) === 0 || trim(substr($text, 0, $matches[0][0][1])) !== '') {
            $this->reject('El texto debe comenzar con un artículo numerado. Preámbulos y transitorios requieren edición separada y revisión manual.');
        }
        $out = [];
        $seen = [];
        foreach ($matches[0] as $i => [$marker, $offset]) {
            $ref = 'Artículo '.$matches[1][$i][0];
            if (isset($seen[mb_strtolower($ref)])) {
                $this->reject('Referencia de artículo duplicada o ambigua.');
            }
            $seen[mb_strtolower($ref)] = true;
            $end = $matches[0][$i + 1][1] ?? strlen($text);
            $content = substr($text, $offset, $end - $offset);
            if (preg_match('/^\s*(?:TRANSITORIOS|Artículo\s+(?![0-9]))/miu', $content)) {
                $this->reject('Referencias no soportadas: se requiere revisión manual.');
            }
            for ($part = 1; $content !== ''; $part++) {
                $piece = mb_strcut($content, 0, 12000, 'UTF-8');
                $out[] = ['article_ref' => $ref, 'content' => $piece, 'content_hash' => hash('sha256', $piece), 'ordinal' => count($out) + 1, 'part' => $part];
                $content = substr($content, strlen($piece));
            }
        }

        return $out;
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
            foreach ($source->chunks()->orderBy('ordinal')->get() as $chunk) {
                if (hash('sha256', $chunk->content) !== $chunk->content_hash) {
                    $this->reject('Integridad de fragmento incorrecta.');
                }
                $text .= $chunk->content;
            }
            if (hash('sha256', $text) !== $expectedHash) {
                $this->reject('Los fragmentos no reconstruyen el documento.');
            }
            $expectedChunks = $this->fragment($text);
            $actualChunks = $source->chunks()->orderBy('ordinal')->get();
            if (count($expectedChunks) !== $actualChunks->count()) {
                $this->reject('Fragmentación incorrecta.');
            }
            foreach ($expectedChunks as $index => $expected) {
                foreach ($expected as $field => $value) {
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
