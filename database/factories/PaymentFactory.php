<?php

namespace Database\Factories;

use App\Enums\PaymentGatewayStatus;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'merchant_order_id' => 'BOOK-'.fake()->unique()->numerify('########'),
            'duitku_reference' => null,
            'payment_method' => fake()->randomElement(['VC', 'BT', 'M2', 'OV']),
            'amount' => fake()->randomFloat(2, 25000, 300000),
            'status' => PaymentGatewayStatus::Pending,
            'paid_at' => null,
        ];
    }

    /**
     * Indicate that the payment has been completed.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentGatewayStatus::Paid,
            'duitku_reference' => 'DTK'.Str::upper(Str::random(10)),
            'paid_at' => now(),
        ]);
    }

    /**
     * Indicate that the payment failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentGatewayStatus::Failed,
        ]);
    }
}
