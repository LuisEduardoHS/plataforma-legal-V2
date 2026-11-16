<?php

namespace App\Console\Commands;

use App\Services\Operations\AccountOperations;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class BootstrapOperations extends Command
{
    protected $signature = 'operations:bootstrap {user} {--capability=*} {--reason=} {--apply} {--confirm-user=}';

    protected $description = 'Simula o aprovisiona una única vez al primer operador; requiere acceso confiable a consola.';

    public function handle(AccountOperations $operations): int
    {
        $id = (string) $this->argument('user');
        if (! ctype_digit($id) || strlen($id) > 18 || (int) $id < 1
            || ($this->option('apply') && $this->option('confirm-user') !== $id)) {
            $this->error('Indica un ID válido y confirma el mismo ID con --confirm-user para aplicar.');

            return self::FAILURE;
        }
        try {
            $result = $operations->bootstrap((int) $id, $this->option('capability'), (string) $this->option('reason'), (bool) $this->option('apply'));
        } catch (ValidationException) {
            $this->error('La selección no cumple los requisitos del aprovisionamiento inicial. No se concedieron permisos.');

            return self::FAILURE;
        }
        $this->info($result['applied'] ? 'Operador inicial aprovisionado y auditado.' : 'Simulación: no se guardaron cambios.');
        $this->line('Cuenta '.$result['id'].'; capacidades: '.implode(', ', $result['capabilities']));

        return self::SUCCESS;
    }
}
