<?php

namespace Tests\Feature\Customer;

use App\Models\Booking;
use App\Models\CustomerAccount;
use App\Notifications\Customer\BookingConfirmed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_notifications(): void
    {
        $this->getJson('/api/v1/customer/notifications')->assertUnauthorized();
    }

    public function test_customer_can_list_their_own_notifications_with_an_unread_count(): void
    {
        $account = CustomerAccount::factory()->create();
        $booking = Booking::factory()->create();
        $account->notify(new BookingConfirmed($booking));

        Sanctum::actingAs($account);

        $this->getJson('/api/v1/customer/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.type', 'booking_confirmed')
            ->assertJsonPath('data.0.booking_id', $booking->id);
    }

    public function test_notifications_list_is_scoped_to_the_authenticated_customer(): void
    {
        $account = CustomerAccount::factory()->create();
        $otherAccount = CustomerAccount::factory()->create();
        $otherAccount->notify(new BookingConfirmed(Booking::factory()->create()));

        Sanctum::actingAs($account);

        $this->getJson('/api/v1/customer/notifications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_customer_can_mark_their_own_notification_as_read(): void
    {
        $account = CustomerAccount::factory()->create();
        $account->notify(new BookingConfirmed(Booking::factory()->create()));

        Sanctum::actingAs($account);
        $notificationId = $account->notifications()->first()->id;

        $this->postJson("/api/v1/customer/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notificationId);

        $this->assertNotNull($account->notifications()->first()->read_at);
    }

    public function test_customer_cannot_mark_another_customers_notification_as_read(): void
    {
        $account = CustomerAccount::factory()->create();
        $account->notify(new BookingConfirmed(Booking::factory()->create()));
        $notificationId = $account->notifications()->first()->id;

        Sanctum::actingAs(CustomerAccount::factory()->create());

        $this->postJson("/api/v1/customer/notifications/{$notificationId}/read")->assertNotFound();
    }

    public function test_mark_all_as_read(): void
    {
        $account = CustomerAccount::factory()->create();
        $account->notify(new BookingConfirmed(Booking::factory()->create()));
        $account->notify(new BookingConfirmed(Booking::factory()->create()));

        Sanctum::actingAs($account);

        $this->postJson('/api/v1/customer/notifications/read-all')->assertNoContent();

        $this->assertSame(0, $account->fresh()->unreadNotifications()->count());
    }
}
