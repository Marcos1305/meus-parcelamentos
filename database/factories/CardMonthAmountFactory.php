<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CardMonthAmount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardMonthAmount>
 */
class CardMonthAmountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'month' => '2026-01-01',
            'amount_cents' => fake()->numberBetween(1_000, 300_000),
        ];
    }
}
