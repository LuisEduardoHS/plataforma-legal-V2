<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            VerificationPermissionSeeder::class,
            EditorialPermissionSeeder::class,

            CountrySeeder::class,
            StateSeeder::class,
            MunicipalitySeeder::class,

            LegalSpecialtySeeder::class,
            ContentCategorySeeder::class,
            TagSeeder::class,
        ]);
    }
}
