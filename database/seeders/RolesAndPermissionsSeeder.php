<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $guard = 'api';
        // Define roles
        $roles = ['admin', 'reader', 'author', 'user', 'superadmin'];
        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);
        }

        // Define permissions
        $permissions = [
            'create books',
            'edit books',
            'delete books',
            'view books',
            'manage users',
        ];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate([
                'name' => $perm,
                'guard_name' => $guard,
            ]);
        }

        // Assign permissions to roles as needed
        $rolePermissions = [
            'admin' => ['create books', 'edit books', 'delete books', 'view books', 'manage users'],
            'author' => ['create books', 'edit books', 'view books'],
            'reader' => ['view books'],
            'user' => ['create books', 'edit books', 'view books'],
            'superadmin' => ['create books', 'edit books', 'delete books', 'view books', 'manage users'],
        ];

        foreach ($rolePermissions as $roleName => $perms) {
            $role = Role::where(['name' => $roleName, 'guard_name' => $guard])->first();
            if ($role) {
                $role->syncPermissions($perms);
            }
        }

        echo "Roles and permissions seeded successfully!\n";
    }
}
