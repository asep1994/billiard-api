<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulatePaymentSuccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_a_pending_payment_as_paid(): void
    {
        $payment = Payment::factory()->create();

        $this->artisan('payment:simulate', ['payment' => $payment->id])->assertExitCode(0);

        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertSame('paid', $payment->fresh()->booking->payment_status->value);
    }

    public function test_accepts_a_merchant_order_id_as_the_identifier(): void
    {
        $payment = Payment::factory()->create();

        $this->artisan('payment:simulate', ['payment' => $payment->merchant_order_id])->assertExitCode(0);

        $this->assertSame('paid', $payment->fresh()->status->value);
    }

    public function test_does_nothing_when_the_payment_is_already_paid(): void
    {
        $payment = Payment::factory()->paid()->create();
        $originalReference = $payment->duitku_reference;

        $this->artisan('payment:simulate', ['payment' => $payment->id])->assertExitCode(0);

        $this->assertSame($originalReference, $payment->fresh()->duitku_reference);
    }

    public function test_fails_when_the_payment_does_not_exist(): void
    {
        $this->artisan('payment:simulate', ['payment' => 999999])->assertExitCode(1);
    }

    public function test_refuses_to_run_outside_sandbox_mode(): void
    {
        config(['services.duitku.sandbox' => false]);
        $payment = Payment::factory()->create();

        $this->artisan('payment:simulate', ['payment' => $payment->id])->assertExitCode(1);

        $this->assertSame('pending', $payment->fresh()->status->value);
    }
}
