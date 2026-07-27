<?php

namespace Database\Factories;

use App\Enums\BudgetLineKind;
use App\Models\BudgetLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLine>
 */
class BudgetLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => BudgetLineKind::Expense,
            'name' => fake()->randomElement(['Energia', 'Água', 'Internet', 'Financiamento casa']),
            'default_amount_cents' => fake()->numberBetween(5_000, 200_000),
            'position' => fake()->numberBetween(0, 20),
            'archived_at' => null,
        ];
    }

    public function income(): static
    {
        return $this->state(fn (): array => [
            'kind' => BudgetLineKind::Income,
            'name' => 'Salário',
        ]);
    }

    public function expense(): static
    {
        return $this->state(fn (): array => ['kind' => BudgetLineKind::Expense]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
