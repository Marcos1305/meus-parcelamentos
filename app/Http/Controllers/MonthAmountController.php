<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMonthAmountRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class MonthAmountController extends Controller
{
    public function line(UpdateMonthAmountRequest $request, int $budgetLine, string $month): RedirectResponse
    {
        $line = $request->user()->budgetLines()->active()->findOrFail($budgetLine);
        $firstDay = $this->firstDayOfMonth($month);
        $amountCents = $request->validated('amount_cents');

        if ($amountCents === null) {
            $line->amounts()->whereDate('month', $firstDay)->delete();

            return back();
        }

        $existing = $line->amounts()->whereDate('month', $firstDay)->first();

        if ($existing !== null) {
            $existing->update(['amount_cents' => $amountCents]);
        } else {
            $line->amounts()->create(['month' => $firstDay, 'amount_cents' => $amountCents]);
        }

        return back();
    }

    public function card(UpdateMonthAmountRequest $request, int $card, string $month): RedirectResponse
    {
        $owned = $request->user()->cards()->findOrFail($card);
        $firstDay = $this->firstDayOfMonth($month);
        $amountCents = $request->validated('amount_cents');

        if ($amountCents === null) {
            $owned->monthAmounts()->whereDate('month', $firstDay)->delete();

            return back();
        }

        $existing = $owned->monthAmounts()->whereDate('month', $firstDay)->first();

        if ($existing !== null) {
            $existing->update(['amount_cents' => $amountCents]);
        } else {
            $owned->monthAmounts()->create(['month' => $firstDay, 'amount_cents' => $amountCents]);
        }

        return back();
    }

    /**
     * O mes vem da URL, entao nao passa por validacao de corpo. Formato
     * invalido e 404: a celula pedida nao existe.
     */
    private function firstDayOfMonth(string $month): string
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            abort(404);
        }

        return CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01")->toDateString();
    }
}
