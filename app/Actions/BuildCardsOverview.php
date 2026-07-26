<?php

namespace App\Actions;

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Collection;

class BuildCardsOverview
{
    /**
     * Visao por cartao da tela Meus Cartoes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(User $user): array
    {
        $cards = $user->cards()
            ->with(['purchases.installments'])
            ->orderBy('name')
            ->get();

        return $cards->map(fn (Card $card): array => $this->forCard($card))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function forCard(Card $card): array
    {
        $open = $this->openInstallments($card->purchases->flatMap(
            fn (Purchase $purchase): Collection => $purchase->installments,
        ));

        $committed = (int) $open->sum('amount_cents');
        $nextDueDate = $open->min('due_date');

        return [
            'id' => $card->id,
            'name' => $card->name,
            'brand' => $card->brand->value,
            'brand_label' => $card->brand->label(),
            'label' => $card->label,
            'last_four_digits' => $card->last_four_digits,
            'credit_limit_cents' => $card->credit_limit_cents,
            'closing_day' => $card->closing_day,
            'due_day' => $card->due_day,
            'committed_cents' => $committed,
            'available_cents' => $card->credit_limit_cents - $committed,
            'committed_ratio' => $card->credit_limit_cents > 0
                ? round($committed / $card->credit_limit_cents, 4)
                : 0.0,
            'next_due_date' => $nextDueDate?->toDateString(),
            'next_due_cents' => $nextDueDate === null
                ? 0
                : (int) $open->where('due_date', $nextDueDate)->sum('amount_cents'),
            'featured' => $this->featured($card),
        ];
    }

    /**
     * O parcelamento ativo com maior valor restante; empate pelo mais recente.
     *
     * @return array<string, mixed>|null
     */
    private function featured(Card $card): ?array
    {
        $winner = $card->purchases
            ->map(function (Purchase $purchase): ?array {
                $open = $this->openInstallments($purchase->installments);

                if ($open->isEmpty()) {
                    return null;
                }

                return [
                    'purchase' => $purchase,
                    'remaining_cents' => (int) $open->sum('amount_cents'),
                    'current_number' => (int) $open->min('number'),
                    'installments_count' => $purchase->installments->count(),
                ];
            })
            ->filter()
            ->sort(function (array $a, array $b): int {
                /** @var Purchase $left */
                $left = $a['purchase'];
                /** @var Purchase $right */
                $right = $b['purchase'];

                return $b['remaining_cents'] <=> $a['remaining_cents']
                    ?: $right->purchased_at <=> $left->purchased_at
                    ?: $right->id <=> $left->id;
            })
            ->first();

        if ($winner === null) {
            return null;
        }

        /** @var Purchase $purchase */
        $purchase = $winner['purchase'];

        return [
            'description' => $purchase->description,
            'merchant' => $purchase->merchant,
            'total_amount_cents' => $purchase->total_amount_cents,
            'paid_cents' => $purchase->total_amount_cents - $winner['remaining_cents'],
            'remaining_cents' => $winner['remaining_cents'],
            'current_number' => $winner['current_number'],
            'installments_count' => $winner['installments_count'],
        ];
    }

    /**
     * @param  Collection<int, Installment>  $installments
     * @return Collection<int, Installment>
     */
    private function openInstallments(Collection $installments): Collection
    {
        return $installments->filter(
            fn (Installment $installment): bool => $installment->status->isOpen(),
        );
    }
}
