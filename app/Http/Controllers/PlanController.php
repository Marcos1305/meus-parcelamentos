<?php

namespace App\Http\Controllers;

use App\Actions\BuildAnnualPlan;
use App\Actions\EnsureIncomeLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    /**
     * Limites do seletor de ano. Fora deles a tela projeta um horizonte que
     * nao tem lastro em parcelamento nenhum.
     */
    private const FIRST_YEAR = 2000;

    private const LAST_YEAR = 2100;

    public function index(
        Request $request,
        BuildAnnualPlan $buildAnnualPlan,
        EnsureIncomeLine $ensureIncomeLine,
    ): Response {
        $currentYear = CarbonImmutable::now()->year;
        $year = $this->year($request, $currentYear);

        $ensureIncomeLine->handle($request->user());

        return Inertia::render('plan/index', [
            'plan' => $buildAnnualPlan->handle($request->user(), $year),
            'years' => [$currentYear - 1, $currentYear, $currentYear + 1],
        ]);
    }

    private function year(Request $request, int $currentYear): int
    {
        $raw = $request->query('ano');

        if (! is_string($raw) || preg_match('/^\d{4}$/', $raw) !== 1) {
            return $currentYear;
        }

        $year = (int) $raw;

        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR) {
            return $currentYear;
        }

        return $year;
    }
}
