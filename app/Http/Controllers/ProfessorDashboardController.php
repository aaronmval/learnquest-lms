<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfessorAnalyticsRequest;
use App\Services\Learning\ProfessorAnalyticsService;
use Illuminate\Http\JsonResponse;

class ProfessorDashboardController extends Controller
{
    /**
     * Live data for the professor dashboard charts, scoped to the sections
     * the authenticated professor manages.
     */
    public function data(ProfessorAnalyticsRequest $request, ProfessorAnalyticsService $analytics): JsonResponse
    {
        return response()->json($analytics->dashboard(
            $request->user(),
            $request->classId(),
            $request->quarterName(),
            $request->startDate(),
            $request->endDate(),
            $request->subjectId(),
        ));
    }
}
