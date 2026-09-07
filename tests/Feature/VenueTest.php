<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VenueTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_scoped_to_the_authenticated_users_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Venue::factory()->count(2)->create(['vendor_id' => $vendor->id]);
        Venue::factory()->count(3)->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson('/api/v1/venues')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_show_includes_the_related_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson("/api/v1/venues/{$venue->id}")
            ->assertOk()
            ->assertJsonPath('data.vendor.id', $vendor->id);
    }

    public function test_opening_and_closing_time_serialize_as_plain_hh_mm(): void
    {
        // Regression: the "datetime:H:i" cast format only applies when the
        // attribute is echoed directly - JSON serialization ignores it and
        // outputs a full UTC ISO datetime unless the Resource formats it
        // itself, which broke the admin dashboard's <input type="time">.
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create([
            'vendor_id' => $vendor->id,
            'opening_time' => '09:00',
            'closing_time' => '22:00',
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson("/api/v1/venues/{$venue->id}")
            ->assertOk()
            ->assertJsonPath('data.opening_time', '09:00')
            ->assertJsonPath('data.closing_time', '22:00');
    }

    public function test_super_admin_sees_venues_across_all_vendors(): void
    {
        Venue::factory()->count(2)->create();
        Venue::factory()->count(3)->create();

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/venues')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_vendor_admin_can_create_a_venue_scoped_to_their_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $response = $this->postJson('/api/v1/venues', [
            'name' => 'Downtown Hall',
            'slug' => 'downtown-hall',
            'opening_time' => '09:00',
            'closing_time' => '22:00',
        ]);

        $response->assertCreated()->assertJsonPath('data.vendor_id', $vendor->id);
    }

    public function test_vendor_admin_cannot_assign_a_venue_to_another_vendor(): void
    {
        $ownVendor = Vendor::factory()->create();
        $otherVendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($ownVendor)->create());

        $response = $this->postJson('/api/v1/venues', [
            'vendor_id' => $otherVendor->id,
            'name' => 'Downtown Hall',
            'slug' => 'downtown-hall',
        ]);

        $response->assertCreated()->assertJsonPath('data.vendor_id', $ownVendor->id);
    }

    public function test_staff_cannot_create_a_venue(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/venues', [
            'name' => 'Downtown Hall',
            'slug' => 'downtown-hall',
        ])->assertForbidden();
    }

    public function test_super_admin_must_specify_a_vendor_id_when_creating_a_venue(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/v1/venues', [
            'name' => 'Downtown Hall',
            'slug' => 'downtown-hall',
        ])->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
    }

    public function test_slug_must_be_unique_within_the_same_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Venue::factory()->create(['vendor_id' => $vendor->id, 'slug' => 'main-hall']);
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson('/api/v1/venues', [
            'name' => 'Main Hall Again',
            'slug' => 'main-hall',
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_same_slug_is_allowed_for_different_vendors(): void
    {
        $otherVendor = Vendor::factory()->create();
        Venue::factory()->create(['vendor_id' => $otherVendor->id, 'slug' => 'main-hall']);

        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson('/api/v1/venues', [
            'name' => 'Main Hall',
            'slug' => 'main-hall',
        ])->assertCreated();
    }

    public function test_vendor_admin_cannot_view_a_venue_from_another_vendor(): void
    {
        $venue = Venue::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $this->getJson("/api/v1/venues/{$venue->id}")->assertForbidden();
    }

    public function test_vendor_admin_can_upload_a_cover_photo_for_their_own_venue(): void
    {
        Storage::fake('public');
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $photo = UploadedFile::fake()->image('venue.jpg');

        $response = $this->postJson("/api/v1/venues/{$venue->id}/photo", ['photo' => $photo]);

        $response->assertOk();
        Storage::disk('public')->assertExists($venue->refresh()->photo_path);
        $this->assertNotNull($response->json('data.photo_url'));
    }

    public function test_uploading_a_new_photo_replaces_and_deletes_the_old_one(): void
    {
        Storage::fake('public');
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson("/api/v1/venues/{$venue->id}/photo", ['photo' => UploadedFile::fake()->image('first.jpg')]);
        $firstPath = $venue->refresh()->photo_path;

        $this->postJson("/api/v1/venues/{$venue->id}/photo", ['photo' => UploadedFile::fake()->image('second.jpg')]);

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($venue->refresh()->photo_path);
    }

    public function test_vendor_admin_cannot_upload_a_photo_for_another_vendors_venue(): void
    {
        Storage::fake('public');
        $venue = Venue::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $this->postJson("/api/v1/venues/{$venue->id}/photo", ['photo' => UploadedFile::fake()->image('venue.jpg')])
            ->assertForbidden();
    }

    public function test_photo_upload_requires_an_image_file(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson("/api/v1/venues/{$venue->id}/photo", ['photo' => UploadedFile::fake()->create('doc.pdf', 10)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');
    }

    public function test_vendor_admin_can_set_description_and_facilities_when_creating_a_venue(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $response = $this->postJson('/api/v1/venues', [
            'name' => 'Downtown Hall',
            'slug' => 'downtown-hall',
            'description' => 'A cozy spot.',
            'facilities' => ['parking', 'wifi'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.description', 'A cozy spot.')
            ->assertJsonPath('data.facilities', ['parking', 'wifi']);
    }

    public function test_venue_facilities_must_be_valid_values(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson('/api/v1/venues', [
            'name' => 'Downtown Hall',
            'slug' => 'downtown-hall',
            'facilities' => ['swimming_pool'],
        ])->assertUnprocessable()->assertJsonValidationErrors('facilities.0');
    }

    public function test_vendor_admin_can_update_description_and_facilities(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id, 'facilities' => ['wifi']]);
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->putJson("/api/v1/venues/{$venue->id}", ['facilities' => ['ac', 'food_drink']])
            ->assertOk()
            ->assertJsonPath('data.facilities', ['ac', 'food_drink']);
    }
}
