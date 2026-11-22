<?php

namespace App\Http\Controllers\Api;

use App\Enums\PriceListItemType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePriceListItemRequest;
use App\Http\Requests\UpdatePriceListItemRequest;
use App\Http\Resources\PriceListItemResource;
use App\Models\PriceListItem;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * A Vendor's Price List. Writes need the manage-price-lists Gate. Items are deactivated, never deleted.
 */
class PriceListItemController extends Controller
{
    /**
     * List a Vendor's items, optionally filtered by type and active state.
     */
    public function index(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['sometimes', Rule::enum(PriceListItemType::class)],
            'active' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
        ]);

        $items = $vendor->priceListItems()
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->value()))
            ->when($request->filled('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('description')
            ->get();

        return PriceListItemResource::collection($items);
    }

    /**
     * Add an item to a Vendor's Price List.
     */
    public function store(StorePriceListItemRequest $request, Vendor $vendor): JsonResponse
    {
        $item = $vendor->priceListItems()->create($request->priceListItemAttributes());

        return PriceListItemResource::make($item->refresh())
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update an item, including deactivating it.
     */
    public function update(UpdatePriceListItemRequest $request, PriceListItem $priceListItem): PriceListItemResource
    {
        $priceListItem->update($request->priceListItemAttributes());

        return PriceListItemResource::make($priceListItem);
    }
}
