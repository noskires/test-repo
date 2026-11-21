<?php

namespace Database\Factories;

use App\Models\SiteSetting;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteSetting>
 */
class SiteSettingFactory extends Factory
{
    /**
     * Define the model's default state. site_id is an external Site ID; seed the directory fake to match.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => 'SITE-'.fake()->unique()->numerify('####'),
            'site_name_snapshot' => fake()->city().' Site',
            'vendor_id' => Vendor::factory(),
            'eic_employee_id' => 'EMP-'.fake()->numerify('####'),
        ];
    }
}
