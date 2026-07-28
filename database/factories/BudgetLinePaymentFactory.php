<?php

namespace Database\Factories;

use App\Models\BudgetLine;
use App\Models\BudgetLinePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLinePayment>
 */
class BudgetLinePaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_line_id' => BudgetLine::factory(),
            'month' => '2026-01-01',
        ];
    }
}
