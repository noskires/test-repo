<?php

namespace Database\Factories;

use App\Enums\ECallPriority;
use App\Enums\ECallStatus;
use App\Models\ECall;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ECall>
 */
class ECallFactory extends Factory
{
    /**
     * Define the model's default state. site_id and eic_employee_id are external IDs; seed the directory fake to match.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => 'SITE-'.fake()->numerify('####'),
            'site_name_snapshot' => fake()->city().' Site',
            'nature_of_issue' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'priority' => fake()->randomElement(ECallPriority::cases()),
            'status' => ECallStatus::ForVendorAssessment,
            'requestor_user_id' => User::factory(),
            'requestor_employee_id' => 'EMP-'.fake()->numerify('####'),
            'vendor_id' => Vendor::factory(),
            'eic_employee_id' => 'EMP-'.fake()->numerify('####'),
        ];
    }
}
