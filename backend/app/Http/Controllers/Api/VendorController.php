<?php

namespace App\Http\Controllers\Api;

use App\Enums\VendorStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVendorRequest;
use App\Http\Requests\UpdateVendorRequest;
use App\Http\Resources\VendorResource;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Vendor registry. There is deliberately no destroy action: vendors are deactivated via update.
 */
class VendorController extends Controller
{
    /**
     * List vendors, optionally filtered by status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(VendorStatus::class)],
        ]);

        $vendors = Vendor::query()
            ->when(
                $request->filled('status'),
                fn ($query) => $query->withStatus(VendorStatus::from($request->string('status')->value())),
            )
            ->orderBy('name')
            ->get();

        return VendorResource::collection($vendors);
    }

    /**
     * Register a new vendor.
     */
    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = Vendor::create($request->validated());

        return VendorResource::make($vendor->refresh())
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a single vendor.
     */
    public function show(Vendor $vendor): VendorResource
    {
        return VendorResource::make($vendor);
    }

    /**
     * Update a vendor's details or status (deactivation replaces deletion).
     */
    public function update(UpdateVendorRequest $request, Vendor $vendor): VendorResource
    {
        $vendor->update($request->validated());

        return VendorResource::make($vendor);
    }
}
