<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Waiting\WaitingItem;
use App\Waiting\WaitingList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaitingListController extends Controller
{
    /**
     * The acting user's "Waiting for me" list, oldest waiting first. Empty, not 403, for a user with no role.
     */
    public function __invoke(Request $request, WaitingList $waitingList): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (WaitingItem $item): array => $item->toArray(), $waitingList->for($request->user())),
        ]);
    }
}
