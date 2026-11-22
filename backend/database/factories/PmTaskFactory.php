<?php

namespace Database\Factories;

use App\Enums\PmStatus;
use App\Models\PmTask;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PmTask>
 */
class PmTaskFactory extends Factory
{
    /**
     * Define the model's default state. site_id, equipment_id and eic_employee_id are external IDs; seed the directory fake to match.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => 'SITE-'.fake()->numerify('####'),
            'site_name_snapshot' => fake()->city().' Site',
            'equipment_id' => 'EQ-'.fake()->unique()->numerify('#####'),
            'equipment_name_snapshot' => fake()->words(2, true),
            'equipment_category_id' => 'CAT-'.fake()->numerify('###'),
            'equipment_category_snapshot' => fake()->word(),
            'vendor_id' => Vendor::factory(),
            'eic_employee_id' => 'EMP-'.fake()->numerify('####'),
            'status' => PmStatus::Scheduled,
            'due_from_date' => '2026-01-01',
            'due_date' => '2026-02-01',
        ];
    }

    /**
     * Indicate that the task was Completed with the given Work Done Date.
     */
    public function completed(CarbonImmutable $workDoneDate): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PmStatus::Completed,
            'work_done_date' => $workDoneDate->toDateString(),
        ]);
    }
}
