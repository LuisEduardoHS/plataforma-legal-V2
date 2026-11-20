<?php

namespace App\Console\Commands;

use App\Services\Lexa\LegalSourceIngestion;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class VerifyLegalSource extends Command
{
    protected $signature = 'lexa:sources:verify {source} {--hash=} {--reviewer=} {--note=} {--apply}';

    protected $description = 'Activa una versión cotejada por un revisor de confianza con acceso a consola.';

    public function handle(LegalSourceIngestion $ingestion): int
    {
        if (! $this->option('apply') || ! ctype_digit((string) $this->argument('source'))) {
            $this->error('Sin cambios. La revisión humana requiere ID, hash, reviewer, note y --apply.');

            return self::FAILURE;
        }
        try {
            $source = $ingestion->verify((int) $this->argument('source'), (string) $this->option('hash'), (string) $this->option('reviewer'), (string) $this->option('note'));
        } catch (ValidationException) {
            $this->error('Verificación rechazada: revisa integridad, fechas y precedencia de versión.');

            return self::FAILURE;
        }
        $this->info('Versión verificada: '.$source->id.'. Versiones anteriores conservadas.');

        return self::SUCCESS;
    }
}
