<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VendorApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsVendorManager(bool $isAllowed = true): User
    {
        Gate::define('manage-vendors', fn (User $user): bool => $isAllowed);

        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Facilities Inc.',
            'contact_person' => 'Juan dela Cruz',
            'email' => 'ops@acme.test',
            'contact_number' => '+63 917 000 0000',
        ], $overrides);
    }

    public function test_creating_a_vendor_returns_201_and_defaults_to_active(): void
    {
        $this->actingAsVendorManager();

        $response = $this->postJson('/api/vendors', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Acme Facilities Inc.')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('vendors', [
            'name' => 'Acme Facilities Inc.',
            'contact_person' => 'Juan dela Cruz',
            'email' => 'ops@acme.test',
            'contact_number' => '+63 917 000 0000',
            'status' => 'active',
        ]);
    }

    public function test_creating_without_name_or_with_invalid_email_returns_422(): void
    {
        $this->actingAsVendorManager();

        $this->postJson('/api/vendors', $this->validPayload(['name' => '', 'email' => 'not-an-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);

        $this->assertDatabaseCount('vendors', 0);
    }

    public function test_creating_with_an_unknown_status_returns_422(): void
    {
        $this->actingAsVendorManager();

        $this->postJson('/api/vendors', $this->validPayload(['status' => 'deleted']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_updating_name_contact_fields_and_status_returns_200_and_persists(): void
    {
        $this->actingAsVendorManager();
        $vendor = Vendor::factory()->create();

        $this->patchJson("/api/vendors/{$vendor->id}", [
            'name' => 'Renamed Vendor',
            'contact_person' => 'Maria Santos',
            'email' => 'new@vendor.test',
            'contact_number' => '02 8123 4567',
            'status' => 'inactive',
        ])->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('vendors', [
            'id' => $vendor->id,
            'name' => 'Renamed Vendor',
            'contact_person' => 'Maria Santos',
            'email' => 'new@vendor.test',
            'contact_number' => '02 8123 4567',
            'status' => 'inactive',
        ]);
    }

    public function test_partial_update_keeps_other_fields(): void
    {
        $this->actingAsVendorManager();
        $vendor = Vendor::factory()->create(['name' => 'Original']);

        $this->patchJson("/api/vendors/{$vendor->id}", ['status' => 'inactive'])->assertOk();

        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'name' => 'Original', 'status' => 'inactive']);
    }

    public function test_updating_with_invalid_email_returns_422(): void
    {
        $this->actingAsVendorManager();
        $vendor = Vendor::factory()->create();

        $this->patchJson("/api/vendors/{$vendor->id}", ['email' => 'nope', 'name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'name']);
    }

    public function test_listing_vendors_can_be_filtered_by_status(): void
    {
        $this->actingAsVendorManager();
        $active = Vendor::factory()->count(2)->create();
        Vendor::factory()->inactive()->create();

        $response = $this->getJson('/api/vendors?status=active');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(
            $active->pluck('id')->all(),
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_listing_vendors_can_be_filtered_by_inactive_status(): void
    {
        $this->actingAsVendorManager();
        Vendor::factory()->count(2)->create();
        $inactive = Vendor::factory()->inactive()->create();

        $this->getJson('/api/vendors?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id);
    }

    public function test_listing_with_an_unknown_status_filter_returns_422(): void
    {
        $this->actingAsVendorManager();

        $this->getJson('/api/vendors?status=bogus')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_listing_without_filter_returns_all_vendors(): void
    {
        $this->actingAsVendorManager(isAllowed: false);
        Vendor::factory()->count(2)->create();
        Vendor::factory()->inactive()->create();

        $this->getJson('/api/vendors')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_showing_a_vendor_returns_it_to_any_authenticated_user(): void
    {
        $this->actingAsVendorManager(isAllowed: false);
        $vendor = Vendor::factory()->create();

        $this->getJson("/api/vendors/{$vendor->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $vendor->id)
            ->assertJsonPath('data.email', $vendor->email);
    }

    public function test_create_returns_403_when_gate_denies(): void
    {
        $this->actingAsVendorManager(isAllowed: false);

        $this->postJson('/api/vendors', $this->validPayload())->assertForbidden();

        $this->assertDatabaseCount('vendors', 0);
    }

    public function test_update_returns_403_when_gate_denies(): void
    {
        $this->actingAsVendorManager(isAllowed: false);
        $vendor = Vendor::factory()->create(['name' => 'Original']);

        $this->patchJson("/api/vendors/{$vendor->id}", ['name' => 'Hijacked'])->assertForbidden();

        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'name' => 'Original']);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function endpoints(): array
    {
        return [
            'index' => ['GET', false],
            'store' => ['POST', false],
            'show' => ['GET', true],
            'update' => ['PATCH', true],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_every_endpoint_returns_401_when_unauthenticated(string $method, bool $targetsAVendor): void
    {
        $vendor = Vendor::factory()->create();
        $uri = $targetsAVendor ? "/api/vendors/{$vendor->id}" : '/api/vendors';

        $this->json($method, $uri, $this->validPayload())->assertUnauthorized();
    }

    public function test_unauthenticated_non_json_request_also_returns_401(): void
    {
        $this->get('/api/vendors')->assertUnauthorized();
    }

    public function test_there_is_no_route_that_deletes_a_vendor(): void
    {
        $deleteVendorRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true)
                && str_contains($route->uri(), 'vendors'));

        $this->assertCount(0, $deleteVendorRoutes);

        $this->actingAsVendorManager();
        $vendor = Vendor::factory()->create();

        $this->deleteJson("/api/vendors/{$vendor->id}")->assertMethodNotAllowed();
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id]);
    }
}
