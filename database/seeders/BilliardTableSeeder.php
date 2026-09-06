<?php

namespace Database\Seeders;

use App\Models\BilliardTable;
use App\Models\Venue;
use Illuminate\Database\Seeder;

class BilliardTableSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Venue::all()->each(function (Venue $venue): void {
            BilliardTable::factory()
                ->count(random_int(4, 6))
                ->create(['venue_id' => $venue->id]);
        });
    }
}
