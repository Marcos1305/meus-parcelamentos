<?php

namespace App\Actions;

use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class BuildHistoryQuery
{
    /**
     * @return array{month: string, status: string, card: string}
     */
    public function filters(Request $request): array
    {
        return [
            'month' => (string) $request->query('month', CarbonImmutable::now()->format('Y-m')),
            'status' => (string) $request->query('status', 'all'),
            'card' => (string) $request->query('card', 'all'),
        ];
    }

    /**
     * @param  array{month: string, status: string, card: string}  $filters
     * @return Builder<Installment>
     */
    public function handle(User $user, array $filters): Builder
    {
        $query = Installment::query()
            ->forUser($user)
            ->with(['purchase.card']);

        if ($filters['month'] !== 'all' && preg_match('/^\d{4}-\d{2}$/', $filters['month']) === 1) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $filters['month'].'-01');

            $query->whereBetween('due_date', [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ]);
        }

        if ($filters['card'] !== 'all') {
            $query->whereHas(
                'purchase',
                fn (Builder $purchases) => $purchases->where('card_id', (int) $filters['card']),
            );
        }

        $today = CarbonImmutable::now();

        match (InstallmentStatus::tryFrom($filters['status'])) {
            InstallmentStatus::Paid => $query->whereDate('due_date', '<', $today->toDateString()),
            InstallmentStatus::Upcoming => $query
                ->whereDate('due_date', '>=', $today->toDateString())
                ->whereDate('due_date', '<=', $today->endOfMonth()->toDateString()),
            InstallmentStatus::Pending => $query->whereDate('due_date', '>', $today->endOfMonth()->toDateString()),
            null => null,
        };

        return $query;
    }
}
