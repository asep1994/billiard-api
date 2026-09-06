<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Vendor::all()->each(function (Vendor $vendor): void {
            Customer::factory()
                ->count(10)
                ->create(['vendor_id' => $vendor->id]);
        });
    }
}
