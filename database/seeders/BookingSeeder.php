<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class BookingSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Vendor::with(['venues.billiardTables', 'customers', 'users'])->get()->each(function (Vendor $vendor): void {
            $tables = $vendor->venues->flatMap->billiardTables;
            $customers = $vendor->customers;
            $staff = $vendor->users;

            if ($tables->isEmpty() || $customers->isEmpty()) {
                return;
            }

            Booking::factory()
                ->count(15)
                ->state(function () use ($vendor, $tables, $customers, $staff): array {
                    $table = $tables->random();

                    return [
                        'vendor_id' => $vendor->id,
                        'venue_id' => $table->venue_id,
                        'billiard_table_id' => $table->id,
                        'customer_id' => $customers->random()->id,
                        'user_id' => $staff->isNotEmpty() ? $staff->random()->id : null,
                    ];
                })
                ->create();
        });
    }
}
