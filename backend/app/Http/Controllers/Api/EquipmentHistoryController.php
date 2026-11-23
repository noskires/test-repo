<?php

namespace App\Http\Controllers\Api;

use App\History\EquipmentHistory;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read-only maintenance history of one Equipment (issue 26), by its external ID. Vendor staff only see their own
 * Vendor's records; an Equipment ID with no records gives an empty list.
 */
class EquipmentHistoryController extends Controller
{
    /**
     * List the Equipment's PM Tasks, E-Calls and Work Orders, newest first, optionally narrowed by entry date
     * (`from` / `to`, inclusive, Y-m-d).
     */
    public function __invoke(Request $request, string $equipmentId): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($request->has('from') ? ['after_or_equal:from'] : [])],
        ]);

        return response()->json(['data' => EquipmentHistory::for(
            equipmentId: $equipmentId,
            user: $request->user(),
            from: isset($validated['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['from']) : null,
            to: isset($validated['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['to']) : null,
        )]);
    }
}
