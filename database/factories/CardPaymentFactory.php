<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CardPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardPayment>
 */
class CardPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'month' => '2026-01-01',
        ];
    }
}
