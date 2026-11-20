<?php

namespace App\Console\Commands;

use App\Services\Lexa\LegalSourceIngestion;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;

class ImportLegalSource extends Command
{
    protected $signature = 'lexa:sources:import {manifest} {text} {--apply}';

    protected $description = 'Importa una versión federal local, siempre sin verificar; requiere --apply.';

    public function handle(LegalSourceIngestion $ingestion): int
    {
        if (! $this->option('apply')) {
            $this->error('Sin escrituras. Revisa el manifiesto y utiliza --apply para importar sin verificar.');

            return self::FAILURE;
        }
        $paths = [];
        foreach (['manifest' => 32000, 'text' => 2000000] as $argument => $maximum) {
            $path = (string) $this->argument($argument);
            if (str_contains($path, '://') || ! ($resolved = realpath($path)) || ! is_file($resolved) || ! is_readable($resolved) || filesize($resolved) > $maximum) {
                $this->error('Se requieren archivos locales legibles dentro del límite de tamaño.');

                return self::FAILURE;
            }
            $paths[$argument] = $resolved;
        }
        try {
            $metadata = json_decode(file_get_contents($paths['manifest']), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($metadata)) {
                throw new JsonException;
            }
            $source = $ingestion->import($metadata, file_get_contents($paths['text']));
        } catch (ValidationException|JsonException) {
            $this->error('Importación rechazada: revisa metadatos, procedencia, versión y referencias.');

            return self::FAILURE;
        }
        $this->info('Fuente '.$source->id.'; SHA-256 '.$source->document_hash.'; verificada: '.($source->is_verified ? 'sí (ya existente)' : 'no').'.');

        return self::SUCCESS;
    }
}
