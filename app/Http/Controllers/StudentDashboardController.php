<?php

namespace App\Http\Controllers;

use App\Services\Learning\StudentAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StudentDashboardController extends Controller
{
    private const QUARTERS = [
        1 => '1st Quarter',
        2 => '2nd Quarter',
        3 => '3rd Quarter',
        4 => '4th Quarter',
    ];

    /**
     * Live data for the student dashboard charts, scoped to the
     * authenticated student only.
     */
    public function data(Request $request, StudentAnalyticsService $analytics): JsonResponse
    {
        $validated = $request->validate([
            'quarter' => ['nullable', 'integer', 'in:1,2,3,4'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date', 'after_or_equal:start'],
        ]);

        $quarter = isset($validated['quarter']) ? self::QUARTERS[(int) $validated['quarter']] : null;
        $start = isset($validated['start']) ? Carbon::parse($validated['start']) : null;
        $end = isset($validated['end']) ? Carbon::parse($validated['end']) : null;

        return response()->json($analytics->dashboard($request->user(), $quarter, $start, $end));
    }
}
