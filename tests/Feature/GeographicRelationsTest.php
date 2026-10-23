<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\State;
use App\Models\Municipality;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Illuminate\Database\QueryException;

class GeographicRelationsTest extends TestCase
{
    public function test_country_state_and_municipality_relationships(): void
    {
        // Garantizar que no utilizamos la base de desarrollo.
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            // Crear un país temporal.
            $country = Country::create([
                'name' => 'México',
                'iso_code' => 'MX',
            ]);

            // Crear un estado asociado al país.
            $state = State::create([
                'country_id' => $country->id,
                'name' => 'Nuevo León',
                'code' => 'NL',
            ]);

            // Crear un municipio asociado al estado.
            $municipality = Municipality::create([
                'state_id' => $state->id,
                'name' => 'Monterrey',
                'code' => '001',
            ]);

            // Comprobar las relaciones.
            $this->assertTrue(
                $country->states->contains($state)
            );

            $this->assertTrue(
                $state->country->is($country)
            );

            $this->assertTrue(
                $state->municipalities->contains($municipality)
            );

            $this->assertTrue(
                $municipality->state->is($state)
            );

            // Comprobar la jerarquía completa.
            $this->assertSame(
                'México',
                $municipality->state->country->name
            );
        } finally {
            // Revertir los datos de prueba.
            DB::rollBack();
        }
    }

    public function test_state_cannot_reference_nonexistent_country(): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            // Eliminaríamos cualquier ambigüedad usando un ID
            // que no exista en la base de pruebas.
            $nonexistentCountryId = DB::table('countries')
                ->max('id') + 1000;

            // MySQL debe rechazar esta operación.
            $this->expectException(QueryException::class);

            State::create([
                'country_id' => $nonexistentCountryId,
                'name' => 'Estado inválido',
                'code' => 'INVALID',
            ]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_state_code_must_be_unique_within_country(): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            $country = Country::create([
                'name' => 'México',
                'iso_code' => 'MX',
            ]);

            State::create([
                'country_id' => $country->id,
                'name' => 'Nuevo León',
                'code' => 'NL',
            ]);

            // Intentamos repetir el mismo código en el mismo país.
            try {
                State::create([
                    'country_id' => $country->id,
                    'name' => 'Otro estado',
                    'code' => 'NL',
                ]);

                $this->fail(
                    'MySQL permitió registrar un código de estado duplicado.'
                );
            } catch (QueryException $exception) {
                // MySQL utiliza el código 23000 para violaciones
                // de integridad, incluyendo restricciones UNIQUE.
                $this->assertSame('23000', (string) $exception->getCode());
            }

            // El mismo código debe permitirse en otro país.
            $otherCountry = Country::create([
                'name' => 'País de prueba',
                'iso_code' => 'ZZ',
            ]);

            $otherState = State::create([
                'country_id' => $otherCountry->id,
                'name' => 'Estado de prueba',
                'code' => 'NL',
            ]);

            $this->assertTrue($otherState->exists);
        } finally {
            DB::rollBack();
        }
    }

    public function test_municipality_cannot_reference_nonexistent_state(): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            $nonexistentStateId = (DB::table('states')->max('id') ?? 0) + 1000;

            try {
                Municipality::create([
                    'state_id' => $nonexistentStateId,
                    'name' => 'Municipio inválido',
                    'code' => '999',
                ]);

                $this->fail(
                    'MySQL permitió registrar un municipio con un estado inexistente.'
                );
            } catch (QueryException $exception) {
                // Error de integridad referencial.
                $this->assertSame(
                    '23000',
                    (string) $exception->getCode()
                );

                // MySQL: 1452 = Cannot add or update a child row.
                $this->assertSame(
                    1452,
                    (int) $exception->errorInfo[1]
                );
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_country_cannot_be_deleted_while_it_has_states(): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            $country = Country::create([
                'name' => 'País de prueba',
                'iso_code' => 'ZZ',
            ]);

            $state = State::create([
                'country_id' => $country->id,
                'name' => 'Estado de prueba',
                'code' => 'TEST',
            ]);

            try {
                $country->delete();

                $this->fail(
                    'MySQL permitió eliminar un país que tiene estados.'
                );
            } catch (QueryException $exception) {
                // SQLSTATE: violación de integridad.
                $this->assertSame(
                    '23000',
                    (string) $exception->getCode()
                );

                // MySQL 1451: no se puede eliminar un registro padre.
                $this->assertSame(
                    1451,
                    (int) $exception->errorInfo[1]
                );
            }

            // Confirmar que ambos registros siguen existiendo.
            $this->assertDatabaseHas('countries', [
                'id' => $country->id,
            ]);

            $this->assertDatabaseHas('states', [
                'id' => $state->id,
                'country_id' => $country->id,
            ]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_state_cannot_be_deleted_while_it_has_municipalities(): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            $country = Country::create([
                'name' => 'País de prueba',
                'iso_code' => 'ZZ',
            ]);

            $state = State::create([
                'country_id' => $country->id,
                'name' => 'Estado de prueba',
                'code' => 'TEST',
            ]);

            $municipality = Municipality::create([
                'state_id' => $state->id,
                'name' => 'Municipio de prueba',
                'code' => '001',
            ]);

            try {
                $state->delete();

                $this->fail(
                    'MySQL permitió eliminar un estado con municipios asociados.'
                );
            } catch (QueryException $exception) {
                $this->assertSame(
                    '23000',
                    (string) $exception->getCode()
                );

                // MySQL 1451: registro padre con dependencias.
                $this->assertSame(
                    1451,
                    (int) $exception->errorInfo[1]
                );
            }

            // Los registros deben continuar existiendo.
            $this->assertDatabaseHas('states', [
                'id' => $state->id,
                'country_id' => $country->id,
            ]);

            $this->assertDatabaseHas('municipalities', [
                'id' => $municipality->id,
                'state_id' => $state->id,
            ]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_municipality_code_must_be_unique_within_state(): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            $country = Country::create([
                'name' => 'País de prueba',
                'iso_code' => 'ZZ',
            ]);

            $firstState = State::create([
                'country_id' => $country->id,
                'name' => 'Estado A',
                'code' => 'EA',
            ]);

            $secondState = State::create([
                'country_id' => $country->id,
                'name' => 'Estado B',
                'code' => 'EB',
            ]);

            Municipality::create([
                'state_id' => $firstState->id,
                'name' => 'Municipio original',
                'code' => '001',
            ]);

            // El mismo código dentro del mismo estado debe rechazarse.
            try {
                Municipality::create([
                    'state_id' => $firstState->id,
                    'name' => 'Municipio duplicado',
                    'code' => '001',
                ]);

                $this->fail(
                    'MySQL permitió un código municipal duplicado.'
                );
            } catch (QueryException $exception) {
                $this->assertSame(
                    '23000',
                    (string) $exception->getCode()
                );

                // MySQL 1062: Duplicate entry.
                $this->assertSame(
                    1062,
                    (int) $exception->errorInfo[1]
                );
            }

            // El código sí debe permitirse en otro estado.
            $municipality = Municipality::create([
                'state_id' => $secondState->id,
                'name' => 'Municipio de otro estado',
                'code' => '001',
            ]);

            $this->assertTrue($municipality->exists);
            $this->assertSame(
                $secondState->id,
                $municipality->state_id
            );
        } finally {
            DB::rollBack();
        }
    }
}
