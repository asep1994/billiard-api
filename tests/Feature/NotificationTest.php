<?php

namespace Tests\Feature;

use App\Models\BilliardTable;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendorContext(): array
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);

        return compact('vendor', 'venue', 'table', 'customer');
    }

    public function test_creating_a_booking_notifies_other_vendor_admins_but_not_the_creator(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $creator = User::factory()->vendorAdmin($vendor)->create();
        $otherAdmin = User::factory()->vendorAdmin($vendor)->create();

        Sanctum::actingAs($creator);

        $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ])->assertCreated();

        $this->assertSame(0, $creator->fresh()->notifications()->count());
        $this->assertSame(1, $otherAdmin->fresh()->notifications()->count());
        $this->assertSame('booking_created', $otherAdmin->fresh()->notifications()->first()->data['type']);
    }

    public function test_a_user_can_list_their_own_notifications_with_an_unread_count(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $admin = User::factory()->vendorAdmin($vendor)->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ])->assertCreated();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', 'booking_created');
    }

    public function test_a_user_can_mark_their_own_notification_as_read(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $admin = User::factory()->vendorAdmin($vendor)->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());
        $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        Sanctum::actingAs($admin);
        $notificationId = $admin->notifications()->first()->id;

        $this->postJson("/api/v1/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notificationId);

        $this->assertNotNull($admin->notifications()->first()->read_at);
    }

    public function test_a_user_cannot_mark_another_users_notification_as_read(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $admin = User::factory()->vendorAdmin($vendor)->create();
        $otherStaff = User::factory()->staff($vendor)->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());
        $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        $notificationId = $admin->notifications()->first()->id;

        Sanctum::actingAs($otherStaff);

        $this->postJson("/api/v1/notifications/{$notificationId}/read")->assertNotFound();
    }

    public function test_mark_all_as_read(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $admin = User::factory()->vendorAdmin($vendor)->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());
        $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/notifications/read-all')->assertNoContent();

        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
    }
}
