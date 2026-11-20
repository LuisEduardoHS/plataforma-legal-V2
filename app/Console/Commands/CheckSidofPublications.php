<?php

namespace App\Console\Commands;

use App\Exceptions\LexaUnavailableException;
use App\Services\Lexa\Sources\SidofPublicationTracker;
use App\Services\Lexa\Sources\SidofReformDetector;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CheckSidofPublications extends Command
{
    protected $signature = 'lexa:sources:sidof {date : Fecha de publicación DOF en formato YYYY-MM-DD} {--dry-run : Modo de sólo lectura sin escrituras} {--record : Registrar publicaciones candidatas en lexa_sidof_publications} {--json : Imprimir resultado en JSON}';

    protected $description = 'Consulta publicaciones del DOF en SIDOF para una fecha explícita y detecta posibles reformas (sólo lectura salvo --record).';

    public function handle(SidofReformDetector $detector, SidofPublicationTracker $tracker): int
    {
        $record = (bool) $this->option('record');
        $dryRun = (bool) $this->option('dry-run');

        if ($record && $dryRun) {
            $this->error('No es posible combinar --dry-run y --record en la misma ejecución.');

            return self::FAILURE;
        }

        try {
            $report = $detector->inspectDate((string) $this->argument('date'), dryRun: ! $record);
        } catch (ValidationException $error) {
            $this->error(collect($error->errors())->flatten()->first() ?? 'Fecha inválida.');

            return self::FAILURE;
        } catch (LexaUnavailableException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if ($record) {
            $tracking = $tracker->syncFromReport($report);
            $report['writes_performed'] = ($tracking['created'] + $tracking['existing']) > 0;
            $report['tracking'] = $tracking;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $modeLabel = $record ? 'registro persistente' : 'dry-run, sin escrituras';
        $this->info("SIDOF {$report['date']} ({$report['endpoint']}) — {$report['total_publications']} publicaciones ({$modeLabel}).");
        $this->line("Posibles coincidencias: {$report['summary']['possible_match']} | Revisión humana: {$report['summary']['needs_human_review']} | Sin coincidencia: {$report['summary']['unmatched']}");

        if (isset($report['tracking'])) {
            $t = $report['tracking'];
            $this->line("Seguimiento: nuevas={$t['created']} | existentes={$t['existing']} | pendientes={$t['pending_review']}");
        }

        foreach (array_slice($report['publications'], 0, 15) as $pub) {
            $keys = $pub['matched_instrument_keys'] !== [] ? ' ['.implode(', ', $pub['matched_instrument_keys']).']' : '';
            $this->line(" - [{$pub['edition']}|{$pub['classification']}] #{$pub['cod_nota']}{$keys}: {$pub['title']}");
        }

        return self::SUCCESS;
    }
}
