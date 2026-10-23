<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    public function test_user_can_be_assigned_a_role(): void
    {
        // Verificar que utilizamos la base exclusiva de pruebas.
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            // Preparar los roles y permisos.
            $this->seed(RolePermissionSeeder::class);

            // Crear un usuario temporal.
            $user = User::factory()->create();

            // Asignarle el rol de moderador.
            $user->assignRole('moderator');

            // Comprobar los permisos efectivos.
            $this->assertTrue($user->hasRole('moderator'));
            $this->assertTrue($user->can('posts.moderate'));
            $this->assertFalse($user->can('roles.manage'));

            // Obtener el ID real del rol, sin asumir que es 2.
            $moderatorRole = Role::findByName('moderator', 'web');

            // Comprobar la relación guardada en MySQL.
            $this->assertDatabaseHas('model_has_roles', [
                'role_id' => $moderatorRole->id,
                'model_type' => $user->getMorphClass(),
                'model_id' => $user->id,
            ]);
        } finally {
            // Deshacer los registros temporales.
            DB::rollBack();
        }
    }

    public function test_regular_user_has_limited_permissions(): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            $this->seed(RolePermissionSeeder::class);

            $user = User::factory()->create();
            $user->assignRole('user');

            $this->assertTrue($user->hasRole('user'));

            // Acciones permitidas.
            $this->assertTrue($user->can('posts.create'));
            $this->assertTrue($user->can('comments.create'));
            $this->assertTrue($user->can('reports.create'));

            // Acciones restringidas.
            $this->assertFalse($user->can('posts.moderate'));
            $this->assertFalse($user->can('users.suspend'));
            $this->assertFalse($user->can('roles.manage'));
            $this->assertFalse($user->can('settings.manage'));
        } finally {
            DB::rollBack();
        }
    }

    public function test_admin_has_all_defined_permissions(): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            $this->seed(RolePermissionSeeder::class);

            $admin = User::factory()->create();
            $admin->assignRole('admin');

            $this->assertTrue($admin->hasRole('admin'));

            $permissions = [
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
            ];

            foreach ($permissions as $permission) {
                $this->assertTrue(
                    $admin->can($permission),
                    "El administrador debería tener el permiso: {$permission}"
                );
            }

            // Verificar que el rol tenga exactamente 12 permisos.
            $this->assertCount(
                12,
                $admin->getAllPermissions()
            );

            // No debe recibir permisos inexistentes.
            $this->assertFalse(
                $admin->can('system.unknown')
            );
        } finally {
            DB::rollBack();
        }
    }

    public function test_role_permission_seeder_is_idempotent(): void
    {
        $this->assertSame(
            'plataforma_legal_v2_test',
            DB::connection()->getDatabaseName()
        );

        DB::beginTransaction();

        try {
            // Ejecutar el seeder dos veces.
            $this->seed(RolePermissionSeeder::class);
            $this->seed(RolePermissionSeeder::class);

            // Verificar que no se duplicaron registros.
            $this->assertDatabaseCount('roles', 3);
            $this->assertDatabaseCount('permissions', 12);
            $this->assertDatabaseCount('role_has_permissions', 26);

            // Comprobar que las relaciones siguen siendo correctas.
            $this->assertDatabaseHas('roles', [
                'name' => 'admin',
                'guard_name' => 'web',
            ]);

            $this->assertDatabaseHas('permissions', [
                'name' => 'roles.manage',
                'guard_name' => 'web',
            ]);
        } finally {
            DB::rollBack();
        }
    }
}