<?php

namespace Database\Factories;

use App\Enums\PromotionType;
use App\Models\Promotion;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
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
            'code' => Str::upper(fake()->unique()->lexify('PROMO???')),
            'type' => PromotionType::Percentage,
            'value' => 10,
            'max_discount' => 50000,
            'starts_at' => null,
            'expires_at' => null,
            'usage_limit' => null,
            'times_used' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate a fixed-amount discount.
     */
    public function fixed(float $value = 20000): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PromotionType::Fixed,
            'value' => $value,
            'max_discount' => null,
        ]);
    }

    /**
     * Indicate the promotion is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Indicate the promotion has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subDay()]);
    }
}
