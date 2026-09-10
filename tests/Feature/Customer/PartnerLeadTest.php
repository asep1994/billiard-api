<?php

namespace Tests\Feature\Customer;

use App\Models\CustomerAccount;
use App\Models\PartnerLead;
use App\Models\User;
use App\Notifications\NewPartnerLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerLeadTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'google_place_id' => 'place_abc123',
            'name' => 'Afterhour Billiard & Lounge',
            'address' => 'Jl. Gudang Selatan No.22, Bandung',
            'latitude' => -6.9169,
            'longitude' => 107.6208,
            'google_rating' => 4.8,
            'google_rating_count' => 444,
        ];
    }

    public function test_guest_cannot_submit_a_partner_lead(): void
    {
        $this->postJson('/api/v1/customer/partner-leads', $this->payload())->assertUnauthorized();
    }

    public function test_customer_can_submit_a_partner_lead(): void
    {
        Notification::fake();
        $superAdmin = User::factory()->superAdmin()->create();
        $customer = CustomerAccount::factory()->create();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/customer/partner-leads', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Afterhour Billiard & Lounge')
            ->assertJsonPath('data.status', 'new');

        $this->assertDatabaseHas('partner_leads', [
            'google_place_id' => 'place_abc123',
            'customer_account_id' => $customer->id,
        ]);

        Notification::assertSentTo($superAdmin, NewPartnerLead::class);
    }

    public function test_submitting_the_same_place_twice_does_not_create_a_duplicate_lead(): void
    {
        Notification::fake();
        Sanctum::actingAs(CustomerAccount::factory()->create());

        $this->postJson('/api/v1/customer/partner-leads', $this->payload())->assertCreated();
        $this->postJson('/api/v1/customer/partner-leads', $this->payload())->assertCreated();

        $this->assertSame(1, PartnerLead::where('google_place_id', 'place_abc123')->count());
    }

    public function test_name_and_google_place_id_are_required(): void
    {
        Sanctum::actingAs(CustomerAccount::factory()->create());

        $this->postJson('/api/v1/customer/partner-leads', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['google_place_id', 'name']);
    }
}
