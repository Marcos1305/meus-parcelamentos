<?php

namespace Database\Seeders;

use App\Actions\GenerateInstallments;
use App\Enums\CardBrand;
use App\Models\Card;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@exemplo.test'],
            ['name' => 'Marcos', 'password' => 'password', 'email_verified_at' => now()],
        );

        if ($user->cards()->exists()) {
            return;
        }

        $cards = [
            ['name' => 'Nubank', 'brand' => CardBrand::Mastercard, 'label' => 'Platinum', 'last_four_digits' => '8291', 'credit_limit_cents' => 2_000_000, 'closing_day' => 3, 'due_day' => 10],
            ['name' => 'Inter', 'brand' => CardBrand::Visa, 'label' => 'Black', 'last_four_digits' => '4402', 'credit_limit_cents' => 1_500_000, 'closing_day' => 28, 'due_day' => 5],
            ['name' => 'XP Investimentos', 'brand' => CardBrand::Visa, 'label' => 'Infinite', 'last_four_digits' => '9901', 'credit_limit_cents' => 1_500_000, 'closing_day' => 18, 'due_day' => 25],
        ];

        $purchases = [
            [0, 'MacBook Pro M2', 'Apple Store - Shopping Morumbi', 1_200_000, 24, '-12 months'],
            [0, 'iPhone 15 Pro Max', 'Apple Store - Shopping Morumbi', 849_900, 12, '-3 months'],
            [1, 'Tênis Nike Air Max', 'Nike Store Brasil', 89_990, 6, '-5 months'],
            [1, 'Geladeira Brastemp', 'Fast Shop Online', 420_000, 10, '-2 months'],
            [2, 'Cadeira Herman Miller', 'Herman Miller BR', 1_500_000, 24, '-8 months'],
            [2, 'Passagem GRU-LIS', 'TAP Air Portugal', 680_000, 8, '-1 month'],
        ];

        $created = array_map(
            fn (array $attributes): Card => $user->cards()->create($attributes),
            $cards,
        );

        $generateInstallments = app(GenerateInstallments::class);

        foreach ($purchases as [$cardIndex, $description, $merchant, $totalCents, $count, $when]) {
            $purchase = Purchase::create([
                'card_id' => $created[$cardIndex]->id,
                'description' => $description,
                'merchant' => $merchant,
                'total_amount_cents' => $totalCents,
                'installments_count' => $count,
                'purchased_at' => now()->modify($when)->toDateString(),
            ]);

            $generateInstallments->handle($purchase->load('card'));
        }
    }
}
