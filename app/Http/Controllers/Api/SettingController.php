<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Church-wide settings (name, denomination, services, pledge number prefix). */
class SettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => Setting::values()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'churchName' => ['sometimes', 'string', 'max:100'],
            'denomination' => ['sometimes', Rule::in(['anglican', 'pentecostal', 'other'])],
            'diocese' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'pledgePrefix' => ['sometimes', 'string', 'max:10', 'regex:/^[A-Za-z0-9-]+$/'],
            'services' => ['sometimes', 'array', 'min:1', 'max:10'],
            'services.*' => ['string', 'max:50', 'distinct'],
            'serviceTimes' => ['sometimes', 'array', 'max:10'],
            'serviceTimes.*' => ['nullable', 'date_format:H:i'],
            'quietHoursStart' => ['sometimes', 'date_format:H:i'],
            'quietHoursEnd' => ['sometimes', 'date_format:H:i'],
            'smsMonthlyBudget' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'financialYearStartMonth' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'sacramentRequirements' => ['sometimes', 'array', 'max:20'],
            'sacramentRequirements.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'sacramentRequirements.*.label' => ['required', 'string', 'max:100'],
            'sacramentRequirements.*.kinds' => ['required', 'array', 'min:1'],
            'sacramentRequirements.*.kinds.*' => [Rule::in(['baptism', 'confirmation', 'dedication'])],
            'sacramentRequirements.*.fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        Setting::put($data);

        return $this->show();
    }
}
