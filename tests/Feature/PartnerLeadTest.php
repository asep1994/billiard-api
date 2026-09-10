<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\PartnerLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_partner_leads(): void
    {
        PartnerLead::factory()->count(2)->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/partner-leads')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_vendor_admin_cannot_list_partner_leads(): void
    {
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $this->getJson('/api/v1/partner-leads')->assertForbidden();
    }

    public function test_staff_cannot_list_partner_leads(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/v1/partner-leads')->assertForbidden();
    }

    public function test_leads_can_be_filtered_by_status(): void
    {
        PartnerLead::factory()->create(['status' => LeadStatus::New]);
        PartnerLead::factory()->create(['status' => LeadStatus::Converted]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/partner-leads?status=converted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'converted');
    }

    public function test_super_admin_can_update_a_leads_status(): void
    {
        $lead = PartnerLead::factory()->create(['status' => LeadStatus::New]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->putJson("/api/v1/partner-leads/{$lead->id}", ['status' => 'contacted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'contacted');

        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
    }

    public function test_vendor_admin_cannot_update_a_leads_status(): void
    {
        $lead = PartnerLead::factory()->create(['status' => LeadStatus::New]);
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $this->putJson("/api/v1/partner-leads/{$lead->id}", ['status' => 'contacted'])->assertForbidden();
    }
}
