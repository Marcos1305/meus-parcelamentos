<?php

namespace Database\Factories;

use App\Actions\BuildInstallmentSchedule;
use App\Models\Card;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
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
            'description' => fake()->randomElement(['MacBook Pro M2', 'iPhone 15 Pro Max', 'Tênis Nike Air Max', 'Cadeira Herman Miller']),
            'merchant' => fake()->company(),
            'total_amount_cents' => fake()->numberBetween(10_000, 1_500_000),
            'installments_count' => fake()->numberBetween(1, 12),
            'purchased_at' => fake()->dateTimeBetween('-18 months', 'now'),
        ];
    }

    /**
     * Cria a compra com o numero de parcelas informado e gera as parcelas
     * pela Action real, nunca montando as linhas a mao.
     */
    public function parcelada(int $count): static
    {
        return $this->state(['installments_count' => $count])
            ->afterCreating(function (Purchase $purchase): void {
                $card = $purchase->card;

                $schedule = app(BuildInstallmentSchedule::class)->handle(
                    $purchase->total_amount_cents,
                    $purchase->installments_count,
                    $purchase->purchased_at,
                    $card->closing_day,
                    $card->due_day,
                );

                $purchase->installments()->createMany($schedule);
            });
    }
}
