<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class VerificationPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['verification.review', 'verification.evidence.read', 'verification.decide', 'verification.revoke', 'verification.audit'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
