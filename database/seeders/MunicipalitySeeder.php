<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MunicipalitySeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path(
            'data/geography/municipalities-mx.json'
        );

        if (!is_file($path)) {
            throw new RuntimeException(
                'No se encontró el catálogo nacional de municipios.'
            );
        }

        $municipalities = json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($municipalities) ||
            count($municipalities) === 0) {
            throw new RuntimeException(
                'El catálogo de municipios está vacío o es inválido.'
            );
        }

        $country = Country::where('iso_code', 'MX')->firstOrFail();

        $states = State::where('country_id', $country->id)
            ->pluck('id', 'code')
            ->all();

        if (count($states) !== 32) {
            throw new RuntimeException(
                'Deben existir las 32 entidades federativas de México.'
            );
        }

        $records = [];
        $seen = [];
        $now = now();

        foreach ($municipalities as $municipality) {
            if (
                !is_array($municipality) ||
                !isset(
                    $municipality['state_code'],
                    $municipality['code'],
                    $municipality['name']
                )
            ) {
                throw new RuntimeException(
                    'Se encontró un registro municipal incompleto.'
                );
            }

            $stateCode = $municipality['state_code'];
            $code = $municipality['code'];
            $name = trim($municipality['name']);

            if (
                !is_string($stateCode) ||
                !preg_match('/^\d{2}$/', $stateCode) ||
                !is_string($code) ||
                !preg_match('/^\d{3}$/', $code) ||
                !is_string($municipality['name']) ||
                $name === '' ||
                !isset($states[$stateCode])
            ) {
                throw new RuntimeException(
                    'Se encontró un municipio con datos inválidos.'
                );
            }

            $key = $stateCode . '-' . $code;

            if (isset($seen[$key])) {
                throw new RuntimeException(
                    "Código municipal duplicado: {$key}"
                );
            }

            $seen[$key] = true;

            $records[] = [
                'state_id' => $states[$stateCode],
                'code' => $code,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Evitar importaciones parciales si ocurre algún error.
        DB::transaction(function () use ($records) {
            foreach (array_chunk($records, 300) as $chunk) {
                DB::table('municipalities')->upsert(
                    $chunk,
                    ['state_id', 'code'],
                    ['name', 'updated_at']
                );
            }
        });

        $this->command?->info(
            count($records) . ' municipios procesados correctamente.'
        );
    }
}
