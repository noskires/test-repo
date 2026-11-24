<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. The sample master data (issue 03) and the demo accounts that need it only
     * exist in local development; other environments get their master data from pss:import-master-data.
     */
    public function run(): void
    {
        if (app()->environment('local')) {
            $this->call(MasterDataSampleSeeder::class);
            $this->call(DemoAccountsSeeder::class);
        }
    }
}
