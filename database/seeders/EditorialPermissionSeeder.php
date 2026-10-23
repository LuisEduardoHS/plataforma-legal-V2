<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class EditorialPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['articles', 'resources'] as $prefix) {
            foreach (['create', 'update.own', 'publish.own', 'manage'] as $action) {
                Permission::firstOrCreate(['name' => $prefix.'.'.$action, 'guard_name' => 'web']);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
