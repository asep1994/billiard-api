<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $street = fake()->unique()->street();
        $name = $street.' Billiard';

        return [
            'vendor_id' => Vendor::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'address' => 'Jl. '.$street.' No. '.fake()->buildingNumber().', Bandung',
            'city' => 'Bandung',
            'phone' => fake()->phoneNumber(),
            'opening_time' => '10:00',
            'closing_time' => '23:00',
            'status' => Status::Active,
        ];
    }

    /**
     * Indicate that the venue is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Status::Inactive,
        ]);
    }
}
