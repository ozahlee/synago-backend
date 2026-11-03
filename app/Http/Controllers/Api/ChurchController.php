<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChurchSummary;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;

/** The signed-in user's own church: summary and finishing the welcome set-up. */
class ChurchController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => ChurchSummary::make(Tenant::church())]);
    }

    public function completeOnboarding(): JsonResponse
    {
        $church = Tenant::church();
        if (! $church->onboarded_at) {
            $church->update(['onboarded_at' => now()]);
        }

        return response()->json(['data' => ChurchSummary::make($church)]);
    }
}
