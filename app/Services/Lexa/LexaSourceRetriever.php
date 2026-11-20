<?php

namespace App\Services\Lexa;

use App\Models\LexaLegalChunk;
use Illuminate\Support\Str;

class LexaSourceRetriever
{
    /**
     * Retrieve verified, currently valid Mexican legal chunks matching the query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retrieve(string $query, ?int $stateId = null, int $limit = 4): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        if ($clean === '') {
            return [];
        }

        $today = now()->toDateString();
        $boundedLimit = max(1, min($limit, 8));

        $rows = LexaLegalChunk::query()
            ->join('lexa_legal_sources as sources', 'sources.id', '=', 'lexa_legal_chunks.lexa_legal_source_id')
            ->leftJoin('states as source_states', 'source_states.id', '=', 'sources.state_id')
            ->where('sources.is_verified', true)
            ->whereNull('sources.superseded_by')
            ->where(function ($q) {
                $q->whereNull('sources.instrument_key')->orWhere(function ($imported) {
                    $imported->whereNotNull('sources.verified_at')->whereNotNull('sources.valid_from');
                });
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('sources.valid_from')->orWhere('sources.valid_from', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('sources.valid_to')->orWhere('sources.valid_to', '>=', $today);
            })
            ->where(function ($q) use ($stateId) {
                $q->where('sources.jurisdiction_scope', 'federal');
                if ($stateId !== null) {
                    $q->orWhere(function ($stateQuery) use ($stateId) {
                        $stateQuery->where('sources.jurisdiction_scope', 'state')
                            ->where('sources.state_id', $stateId);
                    });
                }
            })
            ->whereRaw('MATCH(lexa_legal_chunks.article_ref, lexa_legal_chunks.heading, lexa_legal_chunks.content) AGAINST (? IN NATURAL LANGUAGE MODE)', [$clean])
            ->select([
                'lexa_legal_chunks.id',
                'sources.id as source_id',
                'sources.published_at',
                'sources.consulted_at',
                'sources.valid_from',
                'sources.valid_to',
                'sources.verified_at',
                'lexa_legal_chunks.content_hash',
                'lexa_legal_chunks.article_ref',
                'lexa_legal_chunks.heading',
                'lexa_legal_chunks.content',
                'sources.instrument',
                'sources.short_name',
                'sources.norm_type',
                'sources.jurisdiction_scope',
                'sources.state_id',
                'source_states.name as state_name',
                'sources.source_name',
                'sources.source_url',
                'sources.last_reformed_at',
                'sources.version_key',
                'sources.document_hash',
                'lexa_legal_chunks.part',
            ])
            ->selectRaw('MATCH(lexa_legal_chunks.article_ref, lexa_legal_chunks.heading, lexa_legal_chunks.content) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance', [$clean])
            ->orderByDesc('relevance')
            ->limit($boundedLimit)
            ->get();

        $totals = [];
        foreach ($rows->groupBy('source_id') as $sourceId => $group) {
            $refs = $group->pluck('article_ref')->unique()->values()->all();
            $counts = LexaLegalChunk::where('lexa_legal_source_id', $sourceId)
                ->whereIn('article_ref', $refs)
                ->selectRaw('article_ref, count(*) as total')
                ->groupBy('article_ref')
                ->pluck('total', 'article_ref');
            foreach ($counts as $ref => $total) {
                $totals[$sourceId.':'.$ref] = (int) $total;
            }
        }

        return $rows->map(function ($row) use ($totals) {
            $totalParts = max(1, $totals[$row->source_id.':'.$row->article_ref] ?? 1);
            $excerpt = Str::limit($row->content, 1200, '');
            $isPartial = $totalParts > 1 || ($row->part !== null && (int) $row->part > 1) || mb_strlen($row->content) > mb_strlen($excerpt);

            return [
                'chunk_id' => (int) $row->id,
                'source_id' => (int) $row->source_id,
                'state_name' => $row->state_name,
                'chunk_hash' => $row->content_hash,
                'published_at' => $row->published_at,
                'consulted_at' => $row->consulted_at,
                'valid_from' => $row->valid_from,
                'valid_to' => $row->valid_to,
                'validity_confirmed' => $row->verified_at !== null && $row->valid_from !== null,
                'version_key' => $row->version_key,
                'document_hash' => $row->document_hash,
                'part' => $row->part !== null ? (int) $row->part : null,
                'total_parts' => $totalParts,
                'is_partial' => $isPartial,
                'is_transitory' => str_starts_with((string) $row->article_ref, 'Transitorio'),
                'instrument' => $row->instrument,
                'short_name' => $row->short_name,
                'norm_type' => $row->norm_type,
                'jurisdiction_scope' => $row->jurisdiction_scope,
                'state_id' => $row->state_id !== null ? (int) $row->state_id : null,
                'article_ref' => $row->article_ref,
                'heading' => $row->heading,
                'source_name' => $row->source_name,
                'source_url' => $row->source_url,
                'last_reformed_at' => $row->last_reformed_at,
                'excerpt' => $excerpt,
            ];
        })->all();
    }
}
