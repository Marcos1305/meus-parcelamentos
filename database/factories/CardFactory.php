<?php

namespace Database\Factories;

use App\Enums\CardBrand;
use App\Models\Card;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Nubank', 'Inter', 'XP Investimentos', 'Itaú', 'C6 Bank']),
            'brand' => fake()->randomElement(CardBrand::cases()),
            'label' => fake()->randomElement(['Platinum', 'Black', 'Gold', null]),
            'last_four_digits' => (string) fake()->numberBetween(1000, 9999),
            'credit_limit_cents' => fake()->numberBetween(100_000, 5_000_000),
            'closing_day' => fake()->numberBetween(1, 28),
            'due_day' => fake()->numberBetween(1, 28),
        ];
    }
}
