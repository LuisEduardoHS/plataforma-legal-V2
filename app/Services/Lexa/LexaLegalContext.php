<?php

namespace App\Services\Lexa;

use App\Exceptions\LexaUnavailableException;
use App\Models\State;
use App\Services\Lexa\Data\LexaCompletion;

class LexaLegalContext
{
    public function __construct(private LexaSourceRetriever $retriever) {}

    public function retrieve(string $query, ?int $stateId): array
    {
        $budget = max(100, min(12000, (int) config('lexa.limits.legal_context_chars', 6000))) - 2;
        $stateName = $stateId !== null ? State::findOrFail($stateId)->name : null;
        $sources = [];
        foreach ($this->retriever->retrieve($query, $stateId, 4) as $source) {
            // Legacy rows also need an official link and consistent jurisdiction.
            $url = parse_url($source['source_url']);
            if (($url['scheme'] ?? '') !== 'https' || ! in_array(strtolower($url['host'] ?? ''), ['www.diputados.gob.mx', 'dof.gob.mx', 'www.dof.gob.mx', 'sidof.segob.gob.mx', 'www.ordenjuridico.gob.mx'], true)
                || isset($url['user']) || isset($url['pass']) || isset($url['port'])
                || ($source['jurisdiction_scope'] === 'state' && ($stateId === null || $source['state_id'] !== $stateId))
                || ($source['jurisdiction_scope'] === 'federal' && $source['state_id'] !== null)) {
                continue;
            }
            $source['excerpt'] = mb_substr($source['excerpt'], 0, $budget);
            // Budget includes citation metadata and JSON escaping, not only the text.
            $size = mb_strlen(json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) + 1;
            if ($size > $budget || $source['excerpt'] === '') {
                continue;
            }
            $sources[] = $source;
            $budget -= $size;
        }

        return ['state_id' => $stateId, 'state_name' => $stateName, 'scope' => $stateId === null ? 'federal' : 'federal_and_state', 'retrieved_at' => now()->toIso8601String(), 'sources' => $sources];
    }

    public function citations(LexaCompletion $completion, array $snapshot): array
    {
        $allowed = [];
        foreach ($snapshot['sources'] as $source) {
            $allowed[$source['chunk_id']] = $source;
        }
        $citations = [];
        foreach ($completion->citations as $reference) {
            $id = is_array($reference) ? ($reference['chunk_id'] ?? null) : null;
            if (! is_int($id) || ! isset($allowed[$id])) {
                throw new LexaUnavailableException('La respuesta contiene referencias no verificables. No se guardó como respuesta confirmada.');
            }
            $citation = $allowed[$id];
            unset($citation['excerpt']);
            $citation['retrieved_at'] = $snapshot['retrieved_at'];
            $citations[$id] = $citation;
        }

        return array_values($citations);
    }
}
