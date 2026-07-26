<?php

namespace App\Http\Controllers;

use App\Actions\BuildDashboardSummary;
use App\Actions\BuildMonthlyProjection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        BuildDashboardSummary $buildDashboardSummary,
        BuildMonthlyProjection $buildMonthlyProjection,
    ): Response {
        $user = $request->user();

        return Inertia::render('dashboard', [
            'summary' => $buildDashboardSummary->handle($user),
            'projection' => $buildMonthlyProjection->handle($user),
        ]);
    }
}
