<?php

namespace App\Services\Lexa\Sources;

use App\Models\LexaLegalSource;

final class SidofReformDetector
{
    public function __construct(private SidofClient $client) {}

    /**
     * Inspect SIDOF publications for a date in strict read-only mode without modifying MySQL.
     *
     * @param  array<int, array{instrument_key: string, instrument: string, short_name?: string}>|null  $knownInstruments
     * @return array<string, mixed>
     */
    public function inspectDate(string $isoDate, ?array $knownInstruments = null, bool $dryRun = true): array
    {
        $catalog = $knownInstruments ?? $this->loadKnownInstruments();
        $result = $this->client->fetchByDate($isoDate);

        $classified = [];
        $summary = [
            'possible_match' => 0,
            'needs_human_review' => 0,
            'unmatched' => 0,
        ];

        foreach ($result['publications'] as $publication) {
            $item = $this->classifyPublication($publication, $catalog);
            $summary[$item['classification']]++;
            $classified[] = $item;
        }

        return [
            'date' => $result['date'],
            'endpoint' => $result['endpoint'],
            'dry_run' => $dryRun,
            'writes_performed' => false,
            'total_publications' => count($classified),
            'summary' => $summary,
            'publications' => $classified,
        ];
    }

    /**
     * @return array<int, array{instrument_key: string, instrument: string, short_name: string}>
     */
    private function loadKnownInstruments(): array
    {
        return LexaLegalSource::query()
            ->where('jurisdiction_scope', 'federal')
            ->whereNotNull('instrument_key')
            ->select(['instrument_key', 'instrument', 'short_name'])
            ->distinct()
            ->get()
            ->map(fn (LexaLegalSource $row) => [
                'instrument_key' => (string) $row->instrument_key,
                'instrument' => (string) $row->instrument,
                'short_name' => (string) $row->short_name,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $publication
     * @param  array<int, array{instrument_key: string, instrument: string, short_name?: string}>  $catalog
     * @return array<string, mixed>
     */
    private function classifyPublication(array $publication, array $catalog): array
    {
        $normalizedTitle = $this->normalizeText((string) $publication['title']);
        $matchedKeys = [];

        foreach ($catalog as $entry) {
            $instrumentName = $this->normalizeText((string) $entry['instrument']);
            $shortName = $this->normalizeText((string) ($entry['short_name'] ?? ''));

            $matchesName = $instrumentName !== '' && str_contains($normalizedTitle, $instrumentName);
            $matchesShort = $shortName !== '' && mb_strlen($shortName) >= 3 && preg_match('/\b'.preg_quote($shortName, '/').'\b/u', $normalizedTitle) === 1;

            if ($matchesName || $matchesShort) {
                $matchedKeys[] = $entry['instrument_key'];
            }
        }

        $matchedKeys = array_values(array_unique($matchedKeys));
        $isLegislativeSignal = in_array($publication['publication_type'], ['decree_or_reform', 'decree'], true)
            || preg_match('/\b(?:homologaci[óo]n|declaratoria|c[óo]digo nacional|reforma)\b/iu', (string) $publication['title']) === 1;

        if ($matchedKeys !== []) {
            $classification = 'possible_match';
            $requiresHumanReview = true;
        } elseif ($isLegislativeSignal) {
            $classification = 'needs_human_review';
            $requiresHumanReview = true;
        } else {
            $classification = 'unmatched';
            $requiresHumanReview = false;
        }

        return $publication + [
            'classification' => $classification,
            'matched_instrument_keys' => $matchedKeys,
            'requires_human_review' => $requiresHumanReview,
            // Conservative flags: keyword/title matches never confirm a reform or entry into force.
            'confirmed_reform' => false,
            'effective_date_confirmed' => false,
        ];
    }

    private function normalizeText(string $value): string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');
        $ascii = strtr($lower, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return preg_replace('/\s+/', ' ', $ascii) ?? $ascii;
    }
}
