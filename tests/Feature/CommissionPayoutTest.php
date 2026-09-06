<?php

namespace Tests\Feature;

use App\Enums\PaymentGatewayStatus;
use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommissionPayoutTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT_CODE = 'TESTMERCHANT';

    private const API_KEY = 'test-api-key';

    private function callbackSignature(string $merchantOrderId, int $amount): string
    {
        return md5(self::MERCHANT_CODE.$amount.$merchantOrderId.self::API_KEY);
    }

    private function makePaidPayment(Vendor $vendor, float $amount, float $commissionRate): Payment
    {
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
        ]);

        $commissionAmount = round($amount * $commissionRate / 100, 2);

        return Payment::factory()->create([
            'booking_id' => $booking->id,
            'amount' => $amount,
            'status' => PaymentGatewayStatus::Paid,
            'commission_amount' => $commissionAmount,
            'vendor_payout_amount' => $amount - $commissionAmount,
        ]);
    }

    public function test_payment_callback_computes_commission_using_the_vendors_rate(): void
    {
        $vendor = Vendor::factory()->create(['commission_rate' => 15]);
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id, 'hourly_rate' => 100000]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
        ]);
        $payment = Payment::factory()->create([
            'booking_id' => $booking->id,
            'merchant_order_id' => 'ORDER-1',
            'amount' => 200000,
            'status' => PaymentGatewayStatus::Pending,
        ]);

        $response = $this->postJson('/api/v1/payments/callback', [
            'merchantCode' => self::MERCHANT_CODE,
            'amount' => 200000,
            'merchantOrderId' => 'ORDER-1',
            'resultCode' => '00',
            'reference' => 'REF-1',
            'paymentCode' => 'VC',
            'signature' => $this->callbackSignature('ORDER-1', 200000),
        ]);

        $response->assertOk();
        $payment->refresh();

        $this->assertSame('30000.00', $payment->commission_amount);
        $this->assertSame('170000.00', $payment->vendor_payout_amount);
    }

    public function test_commission_summary_is_scoped_to_the_authenticated_vendor(): void
    {
        $vendorA = Vendor::factory()->create(['commission_rate' => 10]);
        $vendorB = Vendor::factory()->create(['commission_rate' => 10]);
        $this->makePaidPayment($vendorA, 100000, 10);
        $this->makePaidPayment($vendorB, 500000, 10);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendorA)->create());

        $response = $this->getJson('/api/v1/commissions')->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.vendor_id', $vendorA->id);
        $response->assertJsonPath('data.0.gross_revenue', '100000.00');
        $response->assertJsonPath('data.0.commission_earned', '10000.00');
        $response->assertJsonPath('data.0.outstanding_balance', '90000.00');
    }

    public function test_super_admin_sees_commission_summary_for_every_vendor(): void
    {
        $vendorA = Vendor::factory()->create(['commission_rate' => 10]);
        $vendorB = Vendor::factory()->create(['commission_rate' => 20]);
        $this->makePaidPayment($vendorA, 100000, 10);
        $this->makePaidPayment($vendorB, 100000, 20);

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/commissions')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_staff_cannot_view_commission_summary(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson('/api/v1/commissions')->assertForbidden();
    }

    public function test_super_admin_can_record_a_payout_within_the_outstanding_balance(): void
    {
        $vendor = Vendor::factory()->create(['commission_rate' => 10]);
        $this->makePaidPayment($vendor, 100000, 10);

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $response = $this->postJson('/api/v1/payouts', [
            'vendor_id' => $vendor->id,
            'amount' => 90000,
            'note' => 'Transfer BCA',
        ]);

        $response->assertCreated()->assertJsonPath('data.amount', '90000.00');
        $this->assertDatabaseHas('payouts', ['vendor_id' => $vendor->id, 'amount' => 90000]);
    }

    public function test_payout_amount_cannot_exceed_the_outstanding_balance(): void
    {
        $vendor = Vendor::factory()->create(['commission_rate' => 10]);
        $this->makePaidPayment($vendor, 100000, 10);

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/v1/payouts', [
            'vendor_id' => $vendor->id,
            'amount' => 90000.01,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_vendor_admin_cannot_record_a_payout(): void
    {
        $vendor = Vendor::factory()->create(['commission_rate' => 10]);
        $this->makePaidPayment($vendor, 100000, 10);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson('/api/v1/payouts', [
            'vendor_id' => $vendor->id,
            'amount' => 1000,
        ])->assertForbidden();
    }

    public function test_vendor_admin_can_view_only_their_own_payouts(): void
    {
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        Payout::factory()->count(2)->create(['vendor_id' => $vendorA->id]);
        Payout::factory()->count(3)->create(['vendor_id' => $vendorB->id]);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendorA)->create());

        $this->getJson('/api/v1/payouts')->assertOk()->assertJsonCount(2, 'data');
    }
}
