<?php

namespace Database\Factories;

use App\Models\Payout;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
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
            'amount' => fake()->randomFloat(2, 100000, 5000000),
            'note' => fake()->optional()->sentence(),
            'paid_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
