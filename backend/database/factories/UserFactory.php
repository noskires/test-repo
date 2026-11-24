<?php

namespace Database\Factories;

use App\Enums\UserAccess;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'employee_id' => null,
            'vendor_id' => null,
            'access' => UserAccess::Internal,
        ];
    }

    /**
     * Indicate that the user is waiting for an administrator to classify them (issue 46).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'vendor_id' => null,
            'access' => UserAccess::Pending,
        ]);
    }

    /**
     * Indicate that the user is staff of the given Vendor (or a new one).
     */
    public function vendorStaff(?Vendor $vendor = null): static
    {
        return $this->state(fn (array $attributes) => [
            'vendor_id' => $vendor ?? Vendor::factory(),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
