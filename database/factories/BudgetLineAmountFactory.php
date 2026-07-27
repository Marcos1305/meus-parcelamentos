<?php

namespace Database\Factories;

use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLineAmount>
 */
class BudgetLineAmountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_line_id' => BudgetLine::factory(),
            'month' => '2026-01-01',
            'amount_cents' => fake()->numberBetween(1_000, 300_000),
        ];
    }
}
