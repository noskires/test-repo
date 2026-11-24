<?php

namespace App\Http\Controllers\Api;

use App\Dashboard\DashboardCounts;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard's current operational counts (issue 27), optionally narrowed by site, equipment category and vendor.
 * Unknown filter values give all-zero counts rather than an error.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => ['sometimes', 'string', 'max:255'],
            'equipment_category_id' => ['sometimes', 'string', 'max:255'],
            'vendor_id' => ['sometimes', 'integer'],
        ]);

        if (isset($validated['vendor_id'])) {
            $validated['vendor_id'] = (int) $validated['vendor_id'];
        }

        return response()->json(['data' => DashboardCounts::for($request->user(), $validated)]);
    }
}
