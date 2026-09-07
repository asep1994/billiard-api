<?php

namespace Tests\Feature\Customer;

use Tests\TestCase;

class CustomerConfigTest extends TestCase
{
    public function test_guest_can_fetch_the_current_service_fee(): void
    {
        config(['booking.service_fee' => 3000]);

        $this->getJson('/api/v1/customer/config')
            ->assertOk()
            ->assertJsonPath('data.service_fee', 3000);
    }
}
