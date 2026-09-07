<?php

namespace Tests\Feature;

use App\Enums\PaymentGatewayStatus;
use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Venue;
use App\Notifications\Customer\PaymentReceived as CustomerPaymentReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT_CODE = 'TESTMERCHANT';

    private const API_KEY = 'test-api-key';

    private function makeBooking(): Booking
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);

        return Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'total_price' => 100000,
        ]);
    }

    private function callbackSignature(string $merchantOrderId, int $amount): string
    {
        return md5(self::MERCHANT_CODE.$amount.$merchantOrderId.self::API_KEY);
    }

    public function test_index_is_scoped_to_the_authenticated_users_vendor(): void
    {
        $booking = $this->makeBooking();
        Payment::factory()->count(2)->create(['booking_id' => $booking->id]);
        Payment::factory()->count(3)->create();

        Sanctum::actingAs(User::factory()->staff(Vendor::find($booking->vendor_id))->create());

        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_super_admin_sees_payments_across_all_vendors(): void
    {
        Payment::factory()->count(2)->create();
        Payment::factory()->count(3)->create();

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_staff_can_initiate_a_payment_and_receive_a_payment_url(): void
    {
        $booking = $this->makeBooking();

        Http::fake([
            'sandbox.duitku.com/*' => Http::response([
                'merchantCode' => self::MERCHANT_CODE,
                'reference' => 'D12345REF',
                'paymentUrl' => 'https://sandbox.duitku.com/topup/D12345REF',
                'statusCode' => '00',
                'statusMessage' => 'SUCCESS',
            ]),
        ]);

        Sanctum::actingAs(User::factory()->staff(Vendor::find($booking->vendor_id))->create());

        $response = $this->postJson("/api/v1/bookings/{$booking->id}/pay", [
            'payment_method' => 'VC',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.duitku_reference', 'D12345REF')
            ->assertJsonPath('data.booking.id', $booking->id)
            ->assertJsonPath('payment_url', 'https://sandbox.duitku.com/topup/D12345REF');

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'status' => 'pending',
            'duitku_reference' => 'D12345REF',
        ]);
    }

    public function test_the_charged_amount_includes_the_bookings_service_fee(): void
    {
        // Regression: BookingPaymentService used to compute the charged
        // amount from total_price - discount_amount directly, ignoring
        // service_fee entirely, so the amount actually billed via Duitku
        // was less than what the customer app displayed as the total.
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'total_price' => 100000,
            'service_fee' => 3000,
        ]);

        Http::fake([
            'sandbox.duitku.com/*' => Http::response([
                'statusCode' => '00',
                'reference' => 'D1',
                'paymentUrl' => 'https://sandbox.duitku.com/topup/D1',
            ]),
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson("/api/v1/bookings/{$booking->id}/pay", ['payment_method' => 'VC']);

        Http::assertSent(fn (ClientRequest $request) => $request->data()['paymentAmount'] === 103000);
        $this->assertSame(103000.0, (float) $booking->payments()->first()->amount);
    }

    public function test_the_create_transaction_request_carries_a_correctly_computed_signature(): void
    {
        $booking = $this->makeBooking();

        Http::fake([
            'sandbox.duitku.com/*' => Http::response([
                'statusCode' => '00',
                'reference' => 'D1',
                'paymentUrl' => 'https://sandbox.duitku.com/topup/D1',
            ]),
        ]);

        Sanctum::actingAs(User::factory()->staff(Vendor::find($booking->vendor_id))->create());

        $this->postJson("/api/v1/bookings/{$booking->id}/pay", ['payment_method' => 'VC']);

        Http::assertSent(function (ClientRequest $request) {
            $body = $request->data();
            $expected = md5(self::MERCHANT_CODE.$body['merchantOrderId'].$body['paymentAmount'].self::API_KEY);

            return $body['merchantCode'] === self::MERCHANT_CODE
                && $body['paymentAmount'] === 100000
                && $body['signature'] === $expected;
        });
    }

    public function test_a_booking_that_is_already_paid_cannot_be_paid_again(): void
    {
        $booking = $this->makeBooking();
        Payment::factory()->paid()->create(['booking_id' => $booking->id]);

        Sanctum::actingAs(User::factory()->staff(Vendor::find($booking->vendor_id))->create());

        $this->postJson("/api/v1/bookings/{$booking->id}/pay", ['payment_method' => 'VC'])
            ->assertStatus(409);
    }

    public function test_a_user_cannot_initiate_payment_for_a_booking_from_another_vendor(): void
    {
        $booking = $this->makeBooking();
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->postJson("/api/v1/bookings/{$booking->id}/pay", ['payment_method' => 'VC'])
            ->assertForbidden();
    }

    public function test_unauthenticated_request_cannot_initiate_payment(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/bookings/{$booking->id}/pay", ['payment_method' => 'VC'])
            ->assertUnauthorized();
    }

    public function test_callback_marks_the_payment_and_booking_as_paid_when_the_signature_and_result_are_valid(): void
    {
        $booking = $this->makeBooking();
        $payment = Payment::factory()->create([
            'booking_id' => $booking->id,
            'merchant_order_id' => 'BOOK-1-ABCDEFGH',
            'amount' => 100000,
        ]);

        $response = $this->postJson('/api/v1/payments/callback', [
            'merchantCode' => self::MERCHANT_CODE,
            'amount' => 100000,
            'merchantOrderId' => $payment->merchant_order_id,
            'resultCode' => '00',
            'reference' => 'D999',
            'paymentCode' => 'VC',
            'signature' => $this->callbackSignature($payment->merchant_order_id, 100000),
        ]);

        $response->assertOk()->assertSee('SUCCESS');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentGatewayStatus::Paid->value,
            'duitku_reference' => 'D999',
        ]);

        $this->assertSame('paid', $booking->fresh()->payment_status->value);
    }

    public function test_callback_marks_the_payment_as_failed_when_the_result_code_is_not_success(): void
    {
        $booking = $this->makeBooking();
        $payment = Payment::factory()->create([
            'booking_id' => $booking->id,
            'merchant_order_id' => 'BOOK-1-FAILCASE',
            'amount' => 100000,
        ]);

        $this->postJson('/api/v1/payments/callback', [
            'merchantCode' => self::MERCHANT_CODE,
            'amount' => 100000,
            'merchantOrderId' => $payment->merchant_order_id,
            'resultCode' => '01',
            'signature' => $this->callbackSignature($payment->merchant_order_id, 100000),
        ])->assertOk();

        $this->assertSame(PaymentGatewayStatus::Failed, $payment->fresh()->status);
    }

    public function test_callback_with_an_invalid_signature_is_rejected(): void
    {
        $booking = $this->makeBooking();
        $payment = Payment::factory()->create([
            'booking_id' => $booking->id,
            'merchant_order_id' => 'BOOK-1-BADSIG',
            'amount' => 100000,
        ]);

        $this->postJson('/api/v1/payments/callback', [
            'merchantCode' => self::MERCHANT_CODE,
            'amount' => 100000,
            'merchantOrderId' => $payment->merchant_order_id,
            'resultCode' => '00',
            'signature' => 'not-the-real-signature',
        ])->assertStatus(400);

        $this->assertSame(PaymentGatewayStatus::Pending, $payment->fresh()->status);
    }

    public function test_callback_for_an_unknown_order_returns_not_found(): void
    {
        $this->postJson('/api/v1/payments/callback', [
            'merchantCode' => self::MERCHANT_CODE,
            'amount' => 100000,
            'merchantOrderId' => 'DOES-NOT-EXIST',
            'resultCode' => '00',
            'signature' => $this->callbackSignature('DOES-NOT-EXIST', 100000),
        ])->assertStatus(404);
    }

    public function test_callback_is_idempotent_for_an_already_paid_payment(): void
    {
        $booking = $this->makeBooking();
        $payment = Payment::factory()->paid()->create([
            'booking_id' => $booking->id,
            'merchant_order_id' => 'BOOK-1-ALREADYPAID',
            'amount' => 100000,
            'duitku_reference' => 'ORIGINAL-REF',
        ]);

        $this->postJson('/api/v1/payments/callback', [
            'merchantCode' => self::MERCHANT_CODE,
            'amount' => 100000,
            'merchantOrderId' => $payment->merchant_order_id,
            'resultCode' => '00',
            'reference' => 'DUPLICATE-REF',
            'signature' => $this->callbackSignature($payment->merchant_order_id, 100000),
        ])->assertOk();

        $this->assertSame('ORIGINAL-REF', $payment->fresh()->duitku_reference);
    }

    public function test_callback_pushes_a_notification_to_the_customers_linked_account(): void
    {
        Notification::fake();

        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);
        $account = CustomerAccount::factory()->create();
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id, 'customer_account_id' => $account->id]);
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'total_price' => 100000,
        ]);
        $payment = Payment::factory()->create([
            'booking_id' => $booking->id,
            'merchant_order_id' => 'BOOK-1-PUSHCASE',
            'amount' => 100000,
        ]);

        $this->postJson('/api/v1/payments/callback', [
            'merchantCode' => self::MERCHANT_CODE,
            'amount' => 100000,
            'merchantOrderId' => $payment->merchant_order_id,
            'resultCode' => '00',
            'reference' => 'D999',
            'signature' => $this->callbackSignature($payment->merchant_order_id, 100000),
        ])->assertOk();

        Notification::assertSentTo($account, CustomerPaymentReceived::class);
    }

    public function test_callback_does_not_require_authentication(): void
    {
        $booking = $this->makeBooking();
        $payment = Payment::factory()->create([
            'booking_id' => $booking->id,
            'merchant_order_id' => 'BOOK-1-NOAUTH',
            'amount' => 100000,
        ]);

        $this->postJson('/api/v1/payments/callback', [
            'merchantCode' => self::MERCHANT_CODE,
            'amount' => 100000,
            'merchantOrderId' => $payment->merchant_order_id,
            'resultCode' => '00',
            'signature' => $this->callbackSignature($payment->merchant_order_id, 100000),
        ])->assertOk();
    }
}
