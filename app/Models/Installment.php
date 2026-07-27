<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\InstallmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $purchase_id
 * @property int $number
 * @property int $amount_cents
 * @property CarbonImmutable $due_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read InstallmentStatus $status
 * @property-read Purchase $purchase
 */
#[Fillable(['number', 'amount_cents', 'due_date'])]
class Installment extends Model
{
    /** @use HasFactory<InstallmentFactory> */
    use HasFactory;

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return Attribute<InstallmentStatus, never> */
    protected function status(): Attribute
    {
        return Attribute::get(fn (): InstallmentStatus => InstallmentStatus::forDueDate(
            $this->due_date,
            CarbonImmutable::now(),
        ));
    }

    /** @param Builder<Installment> $query */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->whereHas(
            'purchase.card',
            fn (Builder $cards) => $cards->where('user_id', $user->id),
        );
    }

    /** @param Builder<Installment> $query */
    public function scopeForCard(Builder $query, Card $card): void
    {
        $query->whereHas(
            'purchase',
            fn (Builder $purchases) => $purchases->where('card_id', $card->id),
        );
    }

    /** @param Builder<Installment> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereDate('due_date', '>=', CarbonImmutable::now()->toDateString());
    }

    /** @param Builder<Installment> $query */
    public function scopePaid(Builder $query): void
    {
        $query->whereDate('due_date', '<', CarbonImmutable::now()->toDateString());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'immutable_date',
            'number' => 'integer',
            'amount_cents' => 'integer',
        ];
    }
}
