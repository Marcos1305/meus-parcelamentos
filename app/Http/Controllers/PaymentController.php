<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePaymentRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class PaymentController extends Controller
{
    /**
     * Marca ou desmarca uma célula como paga. A presença do registro é a marca.
     *
     * A busca usa `whereDate` e não uma comparação direta: o cast grava o mês
     * como `Y-m-d H:i:s`, então casar contra `Y-m-d` nunca acha a linha que já
     * existe — e `firstOrCreate` acabaria tentando inserir de novo, estourando
     * o índice único na segunda vez que a mesma conta fosse marcada.
     */
    public function line(UpdatePaymentRequest $request, int $budgetLine, string $month): RedirectResponse
    {
        $line = $request->user()->budgetLines()->active()->findOrFail($budgetLine);
        $firstDay = $this->firstDayOfMonth($month);

        $existing = $line->payments()->whereDate('month', $firstDay);

        if ($request->boolean('paid')) {
            if (! $existing->exists()) {
                $line->payments()->create(['month' => $firstDay]);
            }

            return back();
        }

        $existing->delete();

        return back();
    }

    public function card(UpdatePaymentRequest $request, int $card, string $month): RedirectResponse
    {
        $owned = $request->user()->cards()->findOrFail($card);
        $firstDay = $this->firstDayOfMonth($month);

        $existing = $owned->payments()->whereDate('month', $firstDay);

        if ($request->boolean('paid')) {
            if (! $existing->exists()) {
                $owned->payments()->create(['month' => $firstDay]);
            }

            return back();
        }

        $existing->delete();

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
