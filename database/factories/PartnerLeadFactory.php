<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\PartnerLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerLead>
 */
class PartnerLeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_account_id' => null,
            'google_place_id' => 'place_'.fake()->unique()->uuid(),
            'name' => fake()->company().' Billiard',
            'address' => fake()->address(),
            'latitude' => fake()->latitude(-7, -6),
            'longitude' => fake()->longitude(107, 108),
            'google_rating' => fake()->randomFloat(1, 3, 5),
            'google_rating_count' => fake()->numberBetween(1, 500),
            'status' => LeadStatus::New,
            'notes' => null,
        ];
    }
}
