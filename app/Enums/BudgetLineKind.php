<?php

namespace App\Enums;

enum BudgetLineKind: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Entrada',
            self::Expense => 'Saída',
        };
    }
}
