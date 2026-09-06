<?php

namespace Database\Seeders;

use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Database\Seeder;

class VenueSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Vendor::all()->each(function (Vendor $vendor): void {
            Venue::factory()
                ->count(random_int(1, 2))
                ->create(['vendor_id' => $vendor->id]);
        });
    }
}
