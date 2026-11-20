<?php

namespace App\Console\Commands;

use App\Exceptions\LexaUnavailableException;
use App\Services\Lexa\Sources\SidofReformDetector;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CheckSidofPublications extends Command
{
    protected $signature = 'lexa:sources:sidof {date : Fecha de publicación DOF en formato YYYY-MM-DD} {--dry-run : Modo de sólo lectura sin escrituras} {--json : Imprimir resultado en JSON}';

    protected $description = 'Consulta publicaciones del DOF en SIDOF para una fecha explícita y detecta posibles reformas (sólo lectura).';

    public function handle(SidofReformDetector $detector): int
    {
        try {
            $report = $detector->inspectDate((string) $this->argument('date'), dryRun: true);
        } catch (ValidationException $error) {
            $this->error(collect($error->errors())->flatten()->first() ?? 'Fecha inválida.');

            return self::FAILURE;
        } catch (LexaUnavailableException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("SIDOF {$report['date']} ({$report['endpoint']}) — {$report['total_publications']} publicaciones (dry-run, sin escrituras).");
        $this->line("Posibles coincidencias: {$report['summary']['possible_match']} | Revisión humana: {$report['summary']['needs_human_review']} | Sin coincidencia: {$report['summary']['unmatched']}");

        foreach (array_slice($report['publications'], 0, 15) as $pub) {
            $keys = $pub['matched_instrument_keys'] !== [] ? ' ['.implode(', ', $pub['matched_instrument_keys']).']' : '';
            $this->line(" - [{$pub['edition']}|{$pub['classification']}] #{$pub['cod_nota']}{$keys}: {$pub['title']}");
        }

        return self::SUCCESS;
    }
}
