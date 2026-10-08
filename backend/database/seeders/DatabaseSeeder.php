<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            EgyptGeographySeeder::class,
            ReferenceDataSeeder::class,
            DocumentSequenceSeeder::class,
        ]);

        if (app()->environment(['local', 'testing'])) {
            $this->call([
                DemoOrganizationSeeder::class,
            ]);
        }
    }
}
