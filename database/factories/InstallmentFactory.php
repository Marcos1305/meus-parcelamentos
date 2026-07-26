<?php

namespace Database\Factories;

use App\Models\Installment;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Installment>
 */
class InstallmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_id' => Purchase::factory(),
            'number' => 1,
            'amount_cents' => fake()->numberBetween(1_000, 200_000),
            'due_date' => fake()->dateTimeBetween('-6 months', '+6 months'),
        ];
    }
}
