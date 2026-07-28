<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateHiddenMonthsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class HiddenMonthController extends Controller
{
    /**
     * Substitui a lista de meses escondidos daquele ano.
     *
     * A tela manda a lista inteira em vez de um mês por vez porque o atalho
     * "esconder meses passados" mexe em vários de uma vez; uma requisição por
     * mês deixaria a tabela num estado intermediário visível a cada resposta.
     *
     * Só os meses do ano da URL são apagados: a escolha de um ano não pode
     * limpar a de outro.
     */
    public function update(UpdateHiddenMonthsRequest $request, int $year): RedirectResponse
    {
        /** @var array<int, string> $months */
        $months = $request->validated('months');

        DB::transaction(function () use ($request, $year, $months): void {
            $request->user()->hiddenPlanMonths()
                ->whereDate('month', '>=', "{$year}-01-01")
                ->whereDate('month', '<=', "{$year}-12-31")
                ->delete();

            foreach ($months as $month) {
                $request->user()->hiddenPlanMonths()->create(['month' => "{$month}-01"]);
            }
        });

        return back();
    }
}
