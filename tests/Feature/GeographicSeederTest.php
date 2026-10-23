<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Municipality;
use App\Models\State;
use Database\Seeders\CountrySeeder;
use Database\Seeders\MunicipalitySeeder;
use Database\Seeders\StateSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeographicSeederTest extends TestCase
{
    private function assertTestingDatabase(): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );
    }

    private function seedGeography(): void
    {
        $this->seed(CountrySeeder::class);
        $this->seed(StateSeeder::class);
        $this->seed(MunicipalitySeeder::class);
    }

    private function withinTransaction(callable $callback): void
    {
        $this->assertTestingDatabase();

        DB::beginTransaction();

        try {
            $callback();
        } finally {
            DB::rollBack();
        }
    }

    public function test_geographic_seeders_import_mexico_correctly(): void
    {
        $this->withinTransaction(function (): void {
            $this->seedGeography();

            $country = Country::where('iso_code', 'MX')
                ->firstOrFail();

            $this->assertSame('México', $country->name);

            $this->assertSame(
                32,
                $country->states()->count()
            );

            $this->assertSame(
                2478,
                Municipality::whereHas(
                    'state',
                    fn ($query) => $query->where(
                        'country_id',
                        $country->id
                    )
                )->count()
            );

            $nuevoLeon = $country->states()
                ->where('code', '19')
                ->firstOrFail();

            $this->assertSame(
                51,
                $nuevoLeon->municipalities()->count()
            );

            $monterrey = $nuevoLeon->municipalities()
                ->where('code', '039')
                ->firstOrFail();

            $this->assertSame('Monterrey', $monterrey->name);

            $this->assertTrue(
                $monterrey->state->country->is($country)
            );
        });
    }

    public function test_geographic_seeders_are_idempotent(): void
    {
        $this->withinTransaction(function (): void {
            $this->seedGeography();

            $country = Country::where('iso_code', 'MX')
                ->firstOrFail();

            $state = $country->states()
                ->where('code', '19')
                ->firstOrFail();

            $municipality = $state->municipalities()
                ->where('code', '039')
                ->firstOrFail();

            $originalIds = [
                'country' => $country->id,
                'state' => $state->id,
                'municipality' => $municipality->id,
            ];

            // Ejecutar nuevamente los mismos seeders.
            $this->seedGeography();

            // No deben existir registros duplicados.
            $this->assertSame(1, Country::count());
            $this->assertSame(32, State::count());
            $this->assertSame(2478, Municipality::count());

            $country = Country::where('iso_code', 'MX')
                ->firstOrFail();

            $state = $country->states()
                ->where('code', '19')
                ->firstOrFail();

            $municipality = $state->municipalities()
                ->where('code', '039')
                ->firstOrFail();

            // Los identificadores deben conservarse.
            $this->assertSame(
                $originalIds['country'],
                $country->id
            );

            $this->assertSame(
                $originalIds['state'],
                $state->id
            );

            $this->assertSame(
                $originalIds['municipality'],
                $municipality->id
            );
        });
    }

    public function test_all_mexican_states_have_valid_codes(): void
    {
        $this->withinTransaction(function (): void {
            $this->seedGeography();

            $country = Country::where('iso_code', 'MX')
                ->firstOrFail();

            $states = $country->states()
                ->orderBy('code')
                ->get();

            $expectedCodes = array_map(
                fn ($number) => str_pad(
                    (string) $number,
                    2,
                    '0',
                    STR_PAD_LEFT
                ),
                range(1, 32)
            );

            $this->assertSame(
                $expectedCodes,
                $states->pluck('code')->all()
            );

            $this->assertSame(
                32,
                $states->pluck('code')->unique()->count()
            );
        });
    }

    public function test_municipalities_have_valid_codes_and_states(): void
    {
        $this->withinTransaction(function (): void {
            $this->seedGeography();

            $municipalities = Municipality::with('state')
                ->get();

            $this->assertCount(2478, $municipalities);

            foreach ($municipalities as $municipality) {
                $this->assertMatchesRegularExpression(
                    '/^\d{3}$/',
                    $municipality->code
                );

                $this->assertNotNull($municipality->state);

                $this->assertNotEmpty(
                    trim($municipality->name)
                );
            }
        });
    }

    public function test_municipalities_have_unique_codes_per_state(): void
    {
        $this->withinTransaction(function (): void {
            $this->seedGeography();

            $total = Municipality::count();

            $uniquePairs = DB::table('municipalities')
                ->select('state_id', 'code')
                ->distinct()
                ->get()
                ->count();

            $this->assertSame(
                $total,
                $uniquePairs
            );
        });
    }

    public function test_geographic_seeders_preserve_existing_relationships(): void
    {
        $this->withinTransaction(function (): void {
            $this->seedGeography();

            $monterrey = Municipality::whereHas(
                'state',
                fn ($query) => $query->where('code', '19')
            )
                ->where('code', '039')
                ->firstOrFail();

            $originalMunicipalityId = $monterrey->id;
            $originalStateId = $monterrey->state_id;

            $this->seedGeography();

            $updated = Municipality::findOrFail(
                $originalMunicipalityId
            );

            $this->assertSame(
                $originalMunicipalityId,
                $updated->id
            );

            $this->assertSame(
                $originalStateId,
                $updated->state_id
            );

            $this->assertSame(
                'Monterrey',
                $updated->name
            );

            $this->assertSame(
                'Nuevo León',
                $updated->state->name
            );

            $this->assertSame(
                'México',
                $updated->state->country->name
            );
        });
    }
}
