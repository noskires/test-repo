<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\NeedsSetup\NeedsSetupList;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class NeedsSetupController extends Controller
{
    /**
     * The admin Needs Setup list (issue 32), computed live. Needs the manage-site-settings Gate.
     */
    public function __invoke(NeedsSetupList $needsSetupList): JsonResponse
    {
        Gate::authorize('manage-site-settings');

        return response()->json(['data' => $needsSetupList->entries()]);
    }
}
