<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CashPurchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashPurchase>
 */
class CashPurchaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $purchasedAt = fake()->dateTimeBetween('-12 months', 'now');

        return [
            'card_id' => Card::factory(),
            'description' => fake()->randomElement(['Padaria do Bairro', 'Posto Ipiranga', 'Supermercado Extra', 'Farmácia Pague Menos']),
            'amount_cents' => fake()->numberBetween(500, 80_000),
            'purchased_at' => $purchasedAt,
            'billed_at' => (clone $purchasedAt)->modify('+1 month'),
            'category' => null,
            'category_is_manual' => false,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(['card_id' => Card::factory()->for($user)]);
    }
}
