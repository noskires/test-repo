<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Demo accounts only exist in local development (they need the sample master data).
     */
    public function run(): void
    {
        if (app()->environment('local')) {
            $this->call(DemoAccountsSeeder::class);
        }
    }
}
