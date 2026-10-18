<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Module demo seeders, run after the core DemoSeeder in this order. */
    private const DEMO_MODULES = [
        LeaveDemoSeeder::class,
        PayrollDemoSeeder::class,
        DocumentDemoSeeder::class,
        ReimbursementDemoSeeder::class,
        NotificationDemoSeeder::class,
    ];

    /**
     * `php artisan db:seed` installs roles/permissions and the demo company.
     * For a real deployment run only:  php artisan db:seed --class=RolesAndPermissionsSeeder
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(DemoSeeder::class);

        foreach (self::DEMO_MODULES as $seeder) {
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
