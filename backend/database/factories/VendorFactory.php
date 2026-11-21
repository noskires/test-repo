<?php

namespace Database\Factories;

use App\Enums\VendorStatus;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contact_person' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'contact_number' => fake()->phoneNumber(),
            'status' => VendorStatus::Active,
        ];
    }

    /**
     * Indicate that the vendor has been deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VendorStatus::Inactive,
        ]);
    }
}
