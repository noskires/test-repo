<?php

namespace Database\Factories;

use App\Enums\PriceListItemType;
use App\Models\PriceListItem;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceListItem>
 */
class PriceListItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'code' => fake()->unique()->bothify('ITEM-####'),
            'description' => fake()->words(3, true),
            'type' => PriceListItemType::Material,
            'unit_of_measure' => fake()->randomElement(['pc', 'set', 'lot', 'hour', 'visit']),
            'unit_price_centavos' => fake()->numberBetween(1_000, 10_000_000),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the item is a scope-of-work (service) item.
     */
    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PriceListItemType::Service,
        ]);
    }

    /**
     * Indicate that the item has been deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
