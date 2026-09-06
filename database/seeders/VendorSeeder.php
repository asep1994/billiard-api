<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $demoVendor = Vendor::factory()->create([
            'name' => 'Demo Billiard',
            'slug' => 'demo-billiard',
            'email' => 'demo@billiard.test',
        ]);

        User::factory()->vendorAdmin($demoVendor)->create([
            'name' => 'Demo Vendor Admin',
            'email' => 'admin@demo.test',
        ]);

        User::factory()->staff($demoVendor)->create([
            'name' => 'Demo Staff',
            'email' => 'staff@demo.test',
        ]);

        Vendor::factory()
            ->count(3)
            ->create()
            ->each(function (Vendor $vendor): void {
                User::factory()->vendorAdmin($vendor)->create([
                    'email' => 'admin@'.$vendor->slug.'.test',
                ]);

                User::factory()->staff($vendor)->count(2)->create();
            });
    }
}
