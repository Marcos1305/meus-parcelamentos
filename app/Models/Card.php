<?php

namespace App\Models;

use App\Enums\CardBrand;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property CardBrand $brand
 * @property string|null $label
 * @property string $last_four_digits
 * @property int $credit_limit_cents
 * @property int $closing_day
 * @property int $due_day
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Purchase> $purchases
 * @property-read Collection<int, Installment> $installments
 * @property-read Collection<int, CardMonthAmount> $monthAmounts
 */
#[Fillable(['name', 'brand', 'label', 'last_four_digits', 'credit_limit_cents', 'closing_day', 'due_day'])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** @return HasManyThrough<Installment, Purchase, $this> */
    public function installments(): HasManyThrough
    {
        return $this->hasManyThrough(Installment::class, Purchase::class);
    }

    /** @return HasMany<CardMonthAmount, $this> */
    public function monthAmounts(): HasMany
    {
        return $this->hasMany(CardMonthAmount::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'brand' => CardBrand::class,
            'credit_limit_cents' => 'integer',
            'closing_day' => 'integer',
            'due_day' => 'integer',
        ];
    }
}
