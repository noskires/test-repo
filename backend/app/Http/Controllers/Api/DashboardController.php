<?php

namespace App\Http\Controllers\Api;

use App\Dashboard\DashboardCounts;
use App\Dashboard\DashboardFinancials;
use App\Dashboard\DashboardKpis;
use App\Http\Controllers\Controller;
use App\MasterData\MasterDataDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * The dashboard's current operational counts (issue 27) plus the KPIs (issue 28: pm.compliance, equipment.operating,
 * equipment.requiring_attention), optionally narrowed by site, equipment category and vendor. Unknown filter values
 * give all-zero counts rather than an error.
 *
 * Issue 42: optional from/to (Y-m-d, inclusive, Manila dates, both or neither) set the period for pm.compliance,
 * pm.completed_in_range and financials; without them the period is the current Manila month. The status counts
 * ignore the period.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, MasterDataDirectory $directory): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => ['sometimes', 'string', 'max:255'],
            'equipment_category_id' => ['sometimes', 'string', 'max:255'],
            'vendor_id' => ['sometimes', 'integer'],
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if (isset($validated['vendor_id'])) {
            $validated['vendor_id'] = (int) $validated['vendor_id'];
        }

        $filters = Arr::except($validated, ['from', 'to']);
        $today = today()->toImmutable();
        [$from, $to] = isset($validated['from'], $validated['to'])
            ? [Carbon::createFromFormat('!Y-m-d', $validated['from'])->toImmutable(), Carbon::createFromFormat('!Y-m-d', $validated['to'])->toImmutable()]
            : DashboardKpis::defaultPeriod($today);

        $user = $request->user();
        $counts = DashboardCounts::for($user, $filters);
        $kpis = DashboardKpis::for($user, $filters, $directory, $today, [$from, $to]);
        $visibleFilters = DashboardCounts::visibleFilters($user, $filters);
        $counts['pm'] += $kpis['pm'];
        $counts['pm']['completed_in_range'] = DashboardFinancials::completedPmTasks($visibleFilters, $from, $to);
        $counts['equipment'] = $kpis['equipment'];
        $counts['financials'] = DashboardFinancials::for($visibleFilters, $from, $to);

        return response()->json(['data' => $counts]);
    }
}
