<?php

namespace Database\Factories;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
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
            'user_id' => null,
            'action' => ActivityAction::Created,
            'subject_type' => 'booking',
            'subject_id' => fake()->numberBetween(1, 1000),
            'description' => fake()->sentence(),
        ];
    }
}
