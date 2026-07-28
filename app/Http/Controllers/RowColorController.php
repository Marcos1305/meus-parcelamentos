<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRowColorRequest;
use Illuminate\Http\RedirectResponse;

class RowColorController extends Controller
{
    /**
     * A cor tem rota própria em vez de entrar no PATCH da linha porque aquele
     * endpoint recoloca e renumera as posições a cada chamada. Trocar de cor
     * não pode mexer na ordem da tabela.
     */
    public function line(UpdateRowColorRequest $request, int $budgetLine): RedirectResponse
    {
        $request->user()->budgetLines()->active()->findOrFail($budgetLine)
            ->update(['color' => $this->normalized($request->validated('color'))]);

        return back();
    }

    public function card(UpdateRowColorRequest $request, int $card): RedirectResponse
    {
        $request->user()->cards()->findOrFail($card)
            ->update(['color' => $this->normalized($request->validated('color'))]);

        return back();
    }

    /**
     * O seletor do navegador manda minusculas, mas um hexadecimal digitado a
     * mao pode vir em maiusculas. Guardar em caixa unica evita duas linhas
     * com a mesma cor parecendo diferentes.
     */
    private function normalized(mixed $color): ?string
    {
        return is_string($color) ? mb_strtolower($color) : null;
    }
}
