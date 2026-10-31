<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AnalyticsService;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function index(Request $request, LocationService $locations, AnalyticsService $analytics): JsonResponse
    {
        $data = $request->validate([
            'month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'locationId' => ['sometimes', 'integer', 'min:1', 'exists:locations,id'],
        ]);
        $settings = Setting::findOrFail(1);
        $location = $locations->resolve($data['locationId'] ?? null);

        return response()->json($analytics->report($location->id, $data['month'] ?? now($settings->timezone)->format('Y-m'), $settings));
    }
}
