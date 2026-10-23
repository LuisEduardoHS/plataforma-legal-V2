<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $rolePermissions = [
            'user' => [
                'posts.create',
                'posts.update.own',
                'posts.delete.own',
                'comments.create',
                'reports.create',
            ],
            'moderator' => [
                'posts.create',
                'posts.update.own',
                'posts.delete.own',
                'posts.moderate',
                'comments.create',
                'comments.moderate',
                'reports.create',
                'reports.review',
                'users.suspend',
            ],
            'admin' => [
                'posts.create',
                'posts.update.own',
                'posts.delete.own',
                'posts.moderate',
                'comments.create',
                'comments.moderate',
                'reports.create',
                'reports.review',
                'users.suspend',
                'users.manage',
                'roles.manage',
                'settings.manage',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            foreach ($permissions as $permissionName) {
                Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);
            }

            $role->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
