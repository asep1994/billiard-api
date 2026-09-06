<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 week', '+2 weeks');
        $end = (clone $start)->modify('+'.fake()->randomElement([1, 2, 3]).' hours');
        $hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;

        return [
            'vendor_id' => Vendor::factory(),
            'venue_id' => Venue::factory(),
            'billiard_table_id' => BilliardTable::factory(),
            'customer_id' => Customer::factory(),
            'user_id' => null,
            'start_time' => $start,
            'end_time' => $end,
            'status' => BookingStatus::Pending,
            'payment_status' => PaymentStatus::Unpaid,
            'total_price' => $hours * fake()->randomFloat(2, 25000, 150000),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Indicate that the booking is confirmed and paid.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
        ]);
    }

    /**
     * Indicate that the booking was cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled,
        ]);
    }
}
