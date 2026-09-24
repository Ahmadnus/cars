<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * Syncs the permission catalogue and the system roles into the database.
 *
 * Safe to re-run: permissions are matched on name and roles on their machine
 * key, so adding a permission to the catalogue and re-seeding picks it up
 * without disturbing existing grants.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permissions::rows() as $row) {
            Permission::updateOrCreate(['name' => $row['name']], $row);
        }

        $permissionIds = Permission::pluck('id', 'name');

        foreach (Permissions::ROLE_DEFAULTS as $name => $grants) {
            $role = Role::updateOrCreate(
                ['name' => $name],
                [
                    'label_ar' => Permissions::ROLE_LABELS[$name] ?? $name,
                    'is_system' => true,
                ],
            );

            // "*" means the role is unbounded; the super-admin flag on the user
            // short-circuits every gate anyway, but the grants are stored too so
            // the roles screen shows the truth.
            $names = $grants === ['*'] ? Permissions::all() : $grants;

            $role->permissions()->sync(
                collect($names)->map(fn (string $p) => $permissionIds[$p] ?? null)->filter()->all()
            );
        }
    }
}
