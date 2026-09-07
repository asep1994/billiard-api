<?php

namespace Tests\Feature\Customer;

use App\Enums\PromotionType;
use App\Enums\Status;
use App\Enums\TableStatus;
use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Promotion;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerBookingTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT_CODE = 'TESTMERCHANT';

    /**
     * @return array{vendor: Vendor, venue: Venue, table: BilliardTable}
     */
    private function makeVenueContext(): array
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id, 'status' => Status::Active]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id, 'status' => TableStatus::Available, 'hourly_rate' => 100000]);

        return compact('vendor', 'venue', 'table');
    }

    public function test_authenticated_customer_can_create_a_booking(): void
    {
        ['venue' => $venue, 'table' => $table] = $this->makeVenueContext();
        $account = CustomerAccount::factory()->create(['name' => 'Budi Santoso', 'phone' => '081234567890']);
        Sanctum::actingAs($account);

        $response = $this->postJson('/api/v1/customer/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_price', '200000.00')
            ->assertJsonPath('data.customer.name', 'Budi Santoso');

        $this->assertDatabaseHas('customers', [
            'vendor_id' => $venue->vendor_id,
            'phone' => '081234567890',
            'customer_account_id' => $account->id,
        ]);
    }

    public function test_customer_booking_includes_the_platform_service_fee_in_the_payable_amount(): void
    {
        config(['booking.service_fee' => 3000]);
        ['venue' => $venue, 'table' => $table] = $this->makeVenueContext();
        Sanctum::actingAs(CustomerAccount::factory()->create());

        $response = $this->postJson('/api/v1/customer/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        // Table is 100000/hr (see makeVenueContext) -> 200000 for 2h + 3000 fee.
        $response->assertCreated()
            ->assertJsonPath('data.total_price', '200000.00')
            ->assertJsonPath('data.service_fee', '3000.00')
            ->assertJsonPath('data.payable_amount', 203000);
    }

    public function test_utc_tagged_start_time_is_stored_as_the_correct_jakarta_wall_clock_hour(): void
    {
        // Regression: the Flutter app sends UTC-tagged ISO8601 instants
        // (e.g. "...T06:00:00.000Z" for 13:00 WIB). Eloquent's `datetime`
        // cast preserves whatever offset a value was parsed with instead of
        // converting to config('app.timezone') on save, so an untouched
        // value would land in the database as literal "06:00" instead of
        // the intended 13:00 - StoreCustomerBookingRequest must normalize
        // this before it reaches the model.
        ['venue' => $venue, 'table' => $table] = $this->makeVenueContext();
        Sanctum::actingAs(CustomerAccount::factory()->create());

        // 2027-01-01T06:00:00Z is 13:00 WIB (UTC+7).
        $response = $this->postJson('/api/v1/customer/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'start_time' => '2027-01-01T06:00:00.000Z',
            'end_time' => '2027-01-01T08:00:00.000Z',
        ]);

        $response->assertCreated();

        $booking = Booking::findOrFail($response->json('data.id'));
        $this->assertSame('13:00:00', Carbon::parse($booking->getRawOriginal('start_time'))->format('H:i:s'));
        $this->assertSame('15:00:00', Carbon::parse($booking->getRawOriginal('end_time'))->format('H:i:s'));
    }

    public function test_booking_reuses_the_same_per_vendor_customer_record_on_a_second_booking(): void
    {
        ['venue' => $venue, 'table' => $table] = $this->makeVenueContext();
        $secondTable = BilliardTable::factory()->create(['venue_id' => $venue->id, 'status' => TableStatus::Available]);
        $account = CustomerAccount::factory()->create(['phone' => '081234567890']);
        Sanctum::actingAs($account);

        $this->postJson('/api/v1/customer/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ])->assertCreated();

        $this->postJson('/api/v1/customer/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $secondTable->id,
            'start_time' => '2027-01-02 10:00:00',
            'end_time' => '2027-01-02 12:00:00',
        ])->assertCreated();

        $this->assertSame(1, Customer::where('vendor_id', $venue->vendor_id)->where('phone', '081234567890')->count());
    }

    public function test_customer_cannot_book_an_overlapping_time_range(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVenueContext();
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);
        Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        Sanctum::actingAs(CustomerAccount::factory()->create());

        $this->postJson('/api/v1/customer/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'start_time' => '2027-01-01 11:00:00',
            'end_time' => '2027-01-01 13:00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('billiard_table_id');
    }

    public function test_customer_cannot_book_a_time_in_the_past(): void
    {
        ['venue' => $venue, 'table' => $table] = $this->makeVenueContext();
        Sanctum::actingAs(CustomerAccount::factory()->create());

        $this->postJson('/api/v1/customer/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'start_time' => '2020-01-01 10:00:00',
            'end_time' => '2020-01-01 12:00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_time');
    }

    public function test_guest_cannot_create_a_booking(): void
    {
        ['venue' => $venue, 'table' => $table] = $this->makeVenueContext();

        $this->postJson('/api/v1/customer/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ])->assertUnauthorized();
    }

    public function test_booking_applies_a_valid_promo_code(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVenueContext();
        Promotion::factory()->create([
            'vendor_id' => $vendor->id,
            'code' => 'DISKON10',
            'type' => PromotionType::Percentage,
            'value' => 10,
        ]);

        Sanctum::actingAs(CustomerAccount::factory()->create());

        $response = $this->postJson('/api/v1/customer/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
            'promo_code' => 'DISKON10',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.total_price', '200000.00')
            ->assertJsonPath('data.discount_amount', '20000.00')
            ->assertJsonPath('data.promotion.code', 'DISKON10');
    }

    public function test_customer_can_only_view_their_own_bookings(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVenueContext();
        $ownAccount = CustomerAccount::factory()->create(['phone' => '081111111111']);
        $ownCustomer = Customer::factory()->create(['vendor_id' => $vendor->id, 'customer_account_id' => $ownAccount->id]);
        Booking::factory()->count(2)->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $ownCustomer->id,
        ]);

        $otherCustomer = Customer::factory()->create(['vendor_id' => $vendor->id]);
        Booking::factory()->count(3)->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $otherCustomer->id,
        ]);

        Sanctum::actingAs($ownAccount);

        $this->getJson('/api/v1/customer/bookings')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_customer_cannot_view_another_customers_booking(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVenueContext();
        $otherCustomer = Customer::factory()->create(['vendor_id' => $vendor->id, 'customer_account_id' => CustomerAccount::factory()]);
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $otherCustomer->id,
        ]);

        Sanctum::actingAs(CustomerAccount::factory()->create());

        $this->getJson("/api/v1/customer/bookings/{$booking->id}")->assertForbidden();
    }

    public function test_customer_can_initiate_payment_for_their_own_booking(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVenueContext();
        $account = CustomerAccount::factory()->create();
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id, 'customer_account_id' => $account->id]);
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'total_price' => 100000,
        ]);

        Http::fake([
            'sandbox.duitku.com/*' => Http::response([
                'reference' => 'D999',
                'paymentUrl' => 'https://sandbox.duitku.com/topup/D999',
                'statusCode' => '00',
                'statusMessage' => 'SUCCESS',
            ]),
        ]);

        Sanctum::actingAs($account);

        $this->postJson("/api/v1/customer/bookings/{$booking->id}/pay", [
            'payment_method' => 'VC',
        ])->assertCreated()->assertJsonPath('payment_url', 'https://sandbox.duitku.com/topup/D999');

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'sandbox.duitku.com'));
    }

    public function test_customer_cannot_pay_for_another_customers_booking(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVenueContext();
        $otherCustomer = Customer::factory()->create(['vendor_id' => $vendor->id, 'customer_account_id' => CustomerAccount::factory()]);
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $otherCustomer->id,
        ]);

        Sanctum::actingAs(CustomerAccount::factory()->create());

        $this->postJson("/api/v1/customer/bookings/{$booking->id}/pay", [
            'payment_method' => 'VC',
        ])->assertForbidden();
    }
}
