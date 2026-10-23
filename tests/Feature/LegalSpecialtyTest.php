<?php

namespace Tests\Feature;

use App\Models\LegalSpecialty;
use Database\Seeders\LegalSpecialtySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegalSpecialtyTest extends TestCase
{
    private function withinTransaction(callable $callback): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            $callback();
        } finally {
            DB::rollBack();
        }
    }

    public function test_seeder_creates_twenty_specialties(): void
    {
        $this->withinTransaction(function (): void {
            $this->seed(LegalSpecialtySeeder::class);

            $this->assertSame(
                20,
                LegalSpecialty::count()
            );

            $this->assertSame(
                20,
                LegalSpecialty::active()->count()
            );

            $this->assertDatabaseHas('legal_specialties', [
                'slug' => 'derecho-laboral',
                'name' => 'Derecho laboral',
                'sort_order' => 4,
            ]);
        });
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->withinTransaction(function (): void {
            $this->seed(LegalSpecialtySeeder::class);

            $specialty = LegalSpecialty::where(
                'slug',
                'derecho-laboral'
            )->firstOrFail();

            $originalId = $specialty->id;

            $this->seed(LegalSpecialtySeeder::class);

            $this->assertSame(
                20,
                LegalSpecialty::count()
            );

            $this->assertSame(
                $originalId,
                LegalSpecialty::where(
                    'slug',
                    'derecho-laboral'
                )->firstOrFail()->id
            );
        });
    }

    public function test_slug_must_be_unique(): void
    {
        $this->withinTransaction(function (): void {
            LegalSpecialty::create([
                'name' => 'Derecho laboral',
                'slug' => 'derecho-laboral',
            ]);

            try {
                LegalSpecialty::create([
                    'name' => 'Otra especialidad',
                    'slug' => 'derecho-laboral',
                ]);

                $this->fail(
                    'MySQL permitió un slug duplicado.'
                );
            } catch (QueryException $exception) {
                $this->assertSame(
                    1062,
                    (int) $exception->errorInfo[1]
                );
            }
        });
    }

    public function test_active_scope_excludes_inactive_specialties(): void
    {
        $this->withinTransaction(function (): void {
            $this->seed(LegalSpecialtySeeder::class);

            $specialty = LegalSpecialty::where(
                'slug',
                'derecho-penal'
            )->firstOrFail();

            $specialty->update([
                'is_active' => false,
            ]);

            $this->assertSame(
                19,
                LegalSpecialty::active()->count()
            );

            $this->assertFalse(
                $specialty->fresh()->is_active
            );

            $this->assertSame(
                20,
                LegalSpecialty::count()
            );
        });
    }

    public function test_ordered_scope_returns_expected_order(): void
    {
        $this->withinTransaction(function (): void {
            $this->seed(LegalSpecialtySeeder::class);

            $names = LegalSpecialty::active()
                ->ordered()
                ->pluck('name')
                ->all();

            $this->assertSame(
                'Derecho civil',
                $names[0]
            );

            $this->assertSame(
                'Derecho familiar',
                $names[1]
            );

            $this->assertSame(
                'Amparo',
                $names[19]
            );
        });
    }

    public function test_seeder_preserves_inactive_status(): void
    {
        $this->withinTransaction(function (): void {
            $this->seed(LegalSpecialtySeeder::class);

            $specialty = LegalSpecialty::where(
                'slug',
                'derecho-civil'
            )->firstOrFail();

            $specialty->update([
                'is_active' => false,
            ]);

            $originalId = $specialty->id;

            $this->seed(LegalSpecialtySeeder::class);

            $updated = LegalSpecialty::where(
                'slug',
                'derecho-civil'
            )->firstOrFail();

            $this->assertSame(
                $originalId,
                $updated->id
            );

            $this->assertFalse(
                $updated->is_active
            );
        });
    }
}
