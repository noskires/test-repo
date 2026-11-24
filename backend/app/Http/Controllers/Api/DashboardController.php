<?php

namespace App\Http\Controllers\Api;

use App\Dashboard\DashboardCounts;
use App\Dashboard\DashboardKpis;
use App\Http\Controllers\Controller;
use App\MasterData\MasterDataDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard's current operational counts (issue 27) plus the KPIs (issue 28: pm.compliance, equipment.operating,
 * equipment.requiring_attention), optionally narrowed by site, equipment category and vendor. Unknown filter values
 * give all-zero counts rather than an error.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, MasterDataDirectory $directory): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => ['sometimes', 'string', 'max:255'],
            'equipment_category_id' => ['sometimes', 'string', 'max:255'],
            'vendor_id' => ['sometimes', 'integer'],
        ]);

        if (isset($validated['vendor_id'])) {
            $validated['vendor_id'] = (int) $validated['vendor_id'];
        }

        $user = $request->user();
        $counts = DashboardCounts::for($user, $validated);
        $kpis = DashboardKpis::for($user, $validated, $directory, today()->toImmutable());
        $counts['pm'] += $kpis['pm'];
        $counts['equipment'] = $kpis['equipment'];

        return response()->json(['data' => $counts]);
    }
}
