<?php

namespace Tests\Feature;

use App\Models\CustomerAccount;
use App\Models\CustomerDeviceToken;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Tests\TestCase;

class FcmServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_a_multicast_message_to_every_device_of_the_customer(): void
    {
        $customer = CustomerAccount::factory()->create();
        CustomerDeviceToken::create(['customer_account_id' => $customer->id, 'token' => 'token-a']);
        CustomerDeviceToken::create(['customer_account_id' => $customer->id, 'token' => 'token-b']);

        $messaging = $this->mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message, array $tokens) {
                return $tokens === ['token-a', 'token-b'];
            })
            ->andReturn(MulticastSendReport::withItems([]));

        app(FcmService::class)->sendToCustomer($customer, 'Judul', 'Isi pesan', ['booking_id' => '1']);
    }

    public function test_does_nothing_when_the_customer_has_no_registered_devices(): void
    {
        $customer = CustomerAccount::factory()->create();

        $messaging = $this->mock(Messaging::class);
        $messaging->shouldNotReceive('sendMulticast');

        app(FcmService::class)->sendToCustomer($customer, 'Judul', 'Isi pesan');
    }

    public function test_prunes_tokens_firebase_reports_as_invalid_or_unknown(): void
    {
        $customer = CustomerAccount::factory()->create();
        CustomerDeviceToken::create(['customer_account_id' => $customer->id, 'token' => 'stale-token']);
        CustomerDeviceToken::create(['customer_account_id' => $customer->id, 'token' => 'good-token']);

        $report = MulticastSendReport::withItems([
            SendReport::success(MessageTarget::with('token', 'good-token'), []),
            SendReport::failure(MessageTarget::with('token', 'stale-token'), NotFound::becauseTokenNotFound('stale-token')),
        ]);

        $messaging = $this->mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andReturn($report);

        app(FcmService::class)->sendToCustomer($customer, 'Judul', 'Isi pesan');

        $this->assertDatabaseMissing('customer_device_tokens', ['token' => 'stale-token']);
        $this->assertDatabaseHas('customer_device_tokens', ['token' => 'good-token']);
    }
}
