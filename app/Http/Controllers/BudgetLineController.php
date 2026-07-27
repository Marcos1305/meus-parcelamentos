<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBudgetLineRequest;
use App\Http\Requests\UpdateBudgetLineRequest;
use App\Models\BudgetLine;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BudgetLineController extends Controller
{
    public function store(StoreBudgetLineRequest $request): RedirectResponse
    {
        $request->user()->budgetLines()->create([
            ...$request->validated(),
            'position' => (int) $request->user()->budgetLines()->max('position') + 1,
        ]);

        return back();
    }

    public function update(UpdateBudgetLineRequest $request, int $budgetLine): RedirectResponse
    {
        $line = $request->user()->budgetLines()->findOrFail($budgetLine);

        $line->update([
            'name' => $request->validated('name'),
            'default_amount_cents' => $request->validated('default_amount_cents'),
        ]);

        $this->reposition($request->user(), $line, (int) $request->validated('position'));

        return back();
    }

    /**
     * Recoloca a linha no indice pedido e renumera todas de 0 a n-1.
     *
     * Gravar a posicao crua nao bastaria: as setas da tela mandam posicao
     * menos um, e isso empata a linha movida com a vizinha. O empate cairia
     * no id e a linha nao sairia do lugar em metade dos cliques. Reinserir e
     * renumerar torna cada clique uma troca de verdade.
     */
    private function reposition(User $user, BudgetLine $moved, int $target): void
    {
        $others = $user->budgetLines()
            ->where('id', '!=', $moved->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->values();

        $index = max(0, min($target, $others->count()));

        $others->take($index)
            ->push($moved)
            ->concat($others->skip($index))
            ->values()
            ->each(fn (BudgetLine $line, int $position) => $line
                ->forceFill(['position' => $position])
                ->save());
    }

    public function destroy(Request $request, int $budgetLine): RedirectResponse
    {
        $request->user()->budgetLines()->findOrFail($budgetLine)
            ->forceFill(['archived_at' => now()])
            ->save();

        return back();
    }
}
