<?php

namespace Tests\Feature;

use App\Enums\PriceListItemType;
use App\Models\PriceListItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PriceListApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
    }

    private function actingAsPriceListManager(bool $isAllowed = true): User
    {
        Gate::define('manage-price-lists', fn (User $user): bool => $isAllowed);

        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'MAT-001',
            'description' => 'Engine oil, 15W-40, 4L',
            'type' => 'material',
            'unit_of_measure' => 'pail',
            'unit_price' => '1234.56',
        ], $overrides);
    }

    private function itemsUri(?Vendor $vendor = null): string
    {
        return '/api/vendors/'.($vendor ?? $this->vendor)->id.'/price-list-items';
    }

    public function test_creating_an_item_returns_201_and_stores_the_price_exactly(): void
    {
        $this->actingAsPriceListManager();

        $this->postJson($this->itemsUri(), $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.vendor_id', $this->vendor->id)
            ->assertJsonPath('data.code', 'MAT-001')
            ->assertJsonPath('data.description', 'Engine oil, 15W-40, 4L')
            ->assertJsonPath('data.type', 'material')
            ->assertJsonPath('data.unit_of_measure', 'pail')
            ->assertJsonPath('data.unit_price', '1234.56')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('price_list_items', [
            'vendor_id' => $this->vendor->id,
            'code' => 'MAT-001',
            'unit_price_centavos' => 123456,
            'is_active' => true,
        ]);
        $this->assertSame(123456, PriceListItem::query()->sole()->unit_price_centavos);
    }

    public function test_a_service_item_without_a_code_and_a_zero_price_is_accepted(): void
    {
        $this->actingAsPriceListManager();

        $this->postJson($this->itemsUri(), $this->validPayload([
            'code' => null,
            'description' => 'Site inspection',
            'type' => 'service',
            'unit_of_measure' => 'visit',
            'unit_price' => 0,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.code', null)
            ->assertJsonPath('data.type', 'service')
            ->assertJsonPath('data.unit_price', '0.00');
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing description' => [['description' => null], ['description']],
            'missing type' => [['type' => null], ['type']],
            'unknown type' => [['type' => 'labour'], ['type']],
            'missing unit of measure' => [['unit_of_measure' => ''], ['unit_of_measure']],
            'missing unit price' => [['unit_price' => null], ['unit_price']],
            'negative unit price' => [['unit_price' => '-0.01'], ['unit_price']],
            'unit price with three decimals' => [['unit_price' => '10.005'], ['unit_price']],
            'unit price that is not a number' => [['unit_price' => 'free'], ['unit_price']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $invalidFields
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_items_return_422(array $overrides, array $invalidFields): void
    {
        $this->actingAsPriceListManager();

        $this->postJson($this->itemsUri(), $this->validPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($invalidFields);

        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_a_duplicate_code_within_the_same_vendor_returns_422(): void
    {
        $this->actingAsPriceListManager();
        PriceListItem::factory()->for($this->vendor)->create(['code' => 'MAT-001']);

        $this->postJson($this->itemsUri(), $this->validPayload(['code' => 'MAT-001']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->assertDatabaseCount('price_list_items', 1);
    }

    public function test_the_same_code_at_a_different_vendor_is_fine(): void
    {
        $this->actingAsPriceListManager();
        PriceListItem::factory()->for(Vendor::factory())->create(['code' => 'MAT-001']);

        $this->postJson($this->itemsUri(), $this->validPayload(['code' => 'MAT-001']))->assertCreated();

        $this->assertDatabaseCount('price_list_items', 2);
    }

    public function test_several_items_without_a_code_can_coexist(): void
    {
        $this->actingAsPriceListManager();

        $this->postJson($this->itemsUri(), $this->validPayload(['code' => null]))->assertCreated();
        $this->postJson($this->itemsUri(), $this->validPayload(['code' => null, 'description' => 'Labour, per hour']))->assertCreated();

        $this->assertDatabaseCount('price_list_items', 2);
    }

    public function test_listing_returns_only_that_vendors_items(): void
    {
        $this->actingAsPriceListManager(isAllowed: false);
        $ownItem = PriceListItem::factory()->for($this->vendor)->create();
        PriceListItem::factory()->for(Vendor::factory())->create();

        $this->getJson($this->itemsUri())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownItem->id);
    }

    public function test_listing_can_be_filtered_by_type(): void
    {
        $this->actingAsPriceListManager();
        $material = PriceListItem::factory()->for($this->vendor)->create(['type' => PriceListItemType::Material]);
        $service = PriceListItem::factory()->for($this->vendor)->create(['type' => PriceListItemType::Service]);

        $this->getJson($this->itemsUri().'?type=service')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $service->id);
        $this->getJson($this->itemsUri().'?type=material')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $material->id);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function activeFilters(): array
    {
        return [
            'true' => ['true', true],
            '1' => ['1', true],
            'false' => ['false', false],
            '0' => ['0', false],
        ];
    }

    #[DataProvider('activeFilters')]
    public function test_listing_can_be_filtered_by_active(string $filter, bool $expectActive): void
    {
        $this->actingAsPriceListManager();
        $active = PriceListItem::factory()->for($this->vendor)->create();
        $inactive = PriceListItem::factory()->for($this->vendor)->inactive()->create();

        $this->getJson($this->itemsUri()."?active={$filter}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expectActive ? $active->id : $inactive->id);
    }

    public function test_invalid_filters_return_422(): void
    {
        $this->actingAsPriceListManager();

        $this->getJson($this->itemsUri().'?type=labour')->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->getJson($this->itemsUri().'?active=maybe')->assertUnprocessable()->assertJsonValidationErrors(['active']);
    }

    public function test_updating_an_item_returns_200_and_persists_including_deactivation(): void
    {
        $this->actingAsPriceListManager();
        $item = PriceListItem::factory()->for($this->vendor)->create(['unit_price_centavos' => 100000]);

        $this->patchJson("/api/price-list-items/{$item->id}", [
            'description' => 'Engine oil, 15W-40, 18L',
            'unit_of_measure' => 'drum',
            'unit_price' => '4999.99',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.unit_price', '4999.99')
            ->assertJsonPath('data.is_active', false);

        $item->refresh();
        $this->assertSame('Engine oil, 15W-40, 18L', $item->description);
        $this->assertSame('drum', $item->unit_of_measure);
        $this->assertSame(499999, $item->unit_price_centavos);
        $this->assertFalse($item->is_active);
    }

    public function test_changing_a_price_changes_only_that_item(): void
    {
        $this->actingAsPriceListManager();
        $item = PriceListItem::factory()->for($this->vendor)->create(['unit_price_centavos' => 100000]);
        $otherItem = PriceListItem::factory()->for($this->vendor)->create(['unit_price_centavos' => 250000]);

        $this->patchJson("/api/price-list-items/{$item->id}", ['unit_price' => '1500.00'])->assertOk();

        $this->assertSame(250000, $otherItem->fresh()->unit_price_centavos);
        $this->assertSame($this->vendor->id, $item->fresh()->vendor_id);
    }

    public function test_an_item_can_keep_its_own_code_but_not_take_another_items_code(): void
    {
        $this->actingAsPriceListManager();
        $item = PriceListItem::factory()->for($this->vendor)->create(['code' => 'MAT-001']);
        PriceListItem::factory()->for($this->vendor)->create(['code' => 'MAT-002']);

        $this->patchJson("/api/price-list-items/{$item->id}", ['code' => 'MAT-001', 'description' => 'Renamed'])->assertOk();
        $this->patchJson("/api/price-list-items/{$item->id}", ['code' => 'MAT-002'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->assertSame('MAT-001', $item->fresh()->code);
    }

    public function test_updating_with_a_negative_price_returns_422(): void
    {
        $this->actingAsPriceListManager();
        $item = PriceListItem::factory()->for($this->vendor)->create(['unit_price_centavos' => 100000]);

        $this->patchJson("/api/price-list-items/{$item->id}", ['unit_price' => '-5'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_price']);

        $this->assertSame(100000, $item->fresh()->unit_price_centavos);
    }

    public function test_writes_return_403_when_the_gate_denies(): void
    {
        $this->actingAsPriceListManager(isAllowed: false);
        $item = PriceListItem::factory()->for($this->vendor)->create(['description' => 'Original']);

        $this->postJson($this->itemsUri(), $this->validPayload())->assertForbidden();
        $this->patchJson("/api/price-list-items/{$item->id}", ['description' => 'Changed'])->assertForbidden();

        $this->assertDatabaseCount('price_list_items', 1);
        $this->assertSame('Original', $item->fresh()->description);
    }

    public function test_unknown_vendors_and_items_return_404(): void
    {
        $this->actingAsPriceListManager();

        $this->getJson('/api/vendors/9999/price-list-items')->assertNotFound();
        $this->postJson('/api/vendors/9999/price-list-items', $this->validPayload())->assertNotFound();
        $this->patchJson('/api/price-list-items/9999', ['description' => 'x'])->assertNotFound();
    }

    public function test_every_endpoint_returns_401_when_unauthenticated(): void
    {
        $item = PriceListItem::factory()->for($this->vendor)->create();

        $this->getJson($this->itemsUri())->assertUnauthorized();
        $this->postJson($this->itemsUri(), $this->validPayload())->assertUnauthorized();
        $this->patchJson("/api/price-list-items/{$item->id}", ['description' => 'x'])->assertUnauthorized();
    }

    public function test_there_is_no_route_that_deletes_a_price_list_item(): void
    {
        $deleteRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_contains($route->uri(), 'price-list-items'))
            ->filter(fn (RoutingRoute $route): bool => in_array('DELETE', $route->methods(), true));

        $this->assertCount(0, $deleteRoutes);
    }
}
