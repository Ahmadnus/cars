<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Order matters: permissions and reference data must exist before users,
     * and users before any demo record that records who created it.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            ReferenceDataSeeder::class,
            OrganizationSeeder::class,
            UserSeeder::class,
        ]);

        // Demo records are development-only; production installs seed the
        // structural data above and create their own administrator.
        if (! app()->isProduction()) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
