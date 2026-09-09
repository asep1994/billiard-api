<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_banners(): void
    {
        Banner::factory()->count(2)->create();

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/banners')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_vendor_admin_cannot_list_banners(): void
    {
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $this->getJson('/api/v1/banners')->assertForbidden();
    }

    public function test_staff_cannot_list_banners(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/v1/banners')->assertForbidden();
    }

    public function test_super_admin_can_upload_a_banner(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $response = $this->postJson('/api/v1/banners', [
            'title' => 'Promo Akhir Tahun',
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ]);

        $response->assertCreated()->assertJsonPath('data.title', 'Promo Akhir Tahun');

        $banner = Banner::firstOrFail();
        Storage::disk('public')->assertExists($banner->image_path);
        $this->assertNotNull($response->json('data.image_url'));
        $this->assertTrue($banner->is_active);
    }

    public function test_new_banners_default_to_the_end_of_the_order(): void
    {
        Storage::fake('public');
        Banner::factory()->create(['order' => 5]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/v1/banners', ['image' => UploadedFile::fake()->image('banner.jpg')])
            ->assertCreated()
            ->assertJsonPath('data.order', 6);
    }

    public function test_uploading_a_banner_requires_an_image(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/v1/banners', ['title' => 'No image'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_vendor_admin_cannot_upload_a_banner(): void
    {
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $this->postJson('/api/v1/banners', ['image' => UploadedFile::fake()->image('banner.jpg')])
            ->assertForbidden();
    }

    public function test_super_admin_can_update_a_banners_order_and_active_state(): void
    {
        $banner = Banner::factory()->create(['order' => 1, 'is_active' => true]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->putJson("/api/v1/banners/{$banner->id}", ['order' => 3, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.order', 3)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_super_admin_can_delete_a_banner_and_its_image(): void
    {
        Storage::fake('public');
        $path = UploadedFile::fake()->image('banner.jpg')->store('banners', 'public');
        $banner = Banner::factory()->create(['image_path' => $path]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->deleteJson("/api/v1/banners/{$banner->id}")->assertNoContent();

        $this->assertModelMissing($banner);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_customer_endpoint_only_returns_active_banners_in_order(): void
    {
        Banner::factory()->create(['title' => 'Second', 'order' => 2, 'is_active' => true]);
        Banner::factory()->create(['title' => 'Hidden', 'order' => 1, 'is_active' => false]);
        Banner::factory()->create(['title' => 'First', 'order' => 1, 'is_active' => true]);

        $this->getJson('/api/v1/customer/banners')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'First')
            ->assertJsonPath('data.1.title', 'Second');
    }

    public function test_customer_endpoint_does_not_require_authentication(): void
    {
        Banner::factory()->create();

        $this->getJson('/api/v1/customer/banners')->assertOk()->assertJsonCount(1, 'data');
    }
}
