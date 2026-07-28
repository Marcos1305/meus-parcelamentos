<?php

namespace App\Actions;

use App\Enums\BudgetLineKind;
use App\Models\BudgetLine;
use App\Models\User;

class EnsureIncomeLine
{
    /**
     * A renda e uma linha unica, digitada mes a mes na propria linha ENTRADA do
     * rodape. Nao existe tela para cria-la nem para dar nome a ela, entao ela
     * nasce na primeira visita ao planejamento e e reaproveitada nas seguintes.
     *
     * O valor padrao fica em zero de proposito: renda nao tem estimativa que se
     * repita sozinha, cada mes vale o que for digitado nele.
     */
    public function handle(User $user): BudgetLine
    {
        $existing = $user->budgetLines()
            ->active()
            ->where('kind', BudgetLineKind::Income)
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $user->budgetLines()->create([
            'kind' => BudgetLineKind::Income,
            'name' => 'Entrada',
            'default_amount_cents' => 0,
            'position' => 0,
        ]);
    }
}
