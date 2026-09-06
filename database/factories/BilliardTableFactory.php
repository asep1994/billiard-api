<?php

namespace Database\Factories;

use App\Enums\TableStatus;
use App\Enums\TableType;
use App\Models\BilliardTable;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BilliardTable>
 */
class BilliardTableFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venue_id' => Venue::factory(),
            'name' => 'Table '.fake()->unique()->numberBetween(1, 500),
            'type' => fake()->randomElement(TableType::cases()),
            'hourly_rate' => fake()->randomFloat(2, 25000, 150000),
            'status' => TableStatus::Available,
        ];
    }

    /**
     * Indicate that the table is under maintenance.
     */
    public function maintenance(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TableStatus::Maintenance,
        ]);
    }
}
