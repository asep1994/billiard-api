<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Staff,
            'vendor_id' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is a super admin (not tied to any vendor).
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::SuperAdmin,
            'vendor_id' => null,
        ]);
    }

    /**
     * Indicate that the user is a vendor admin.
     */
    public function vendorAdmin(?Vendor $vendor = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::VendorAdmin,
            'vendor_id' => $vendor?->id ?? Vendor::factory(),
        ]);
    }

    /**
     * Indicate that the user is vendor staff.
     */
    public function staff(?Vendor $vendor = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Staff,
            'vendor_id' => $vendor?->id ?? Vendor::factory(),
        ]);
    }
}
