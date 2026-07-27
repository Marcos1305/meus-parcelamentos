<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CardMonthAmountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $card_id
 * @property CarbonImmutable $month
 * @property int $amount_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Card $card
 */
#[Fillable(['month', 'amount_cents'])]
class CardMonthAmount extends Model
{
    /** @use HasFactory<CardMonthAmountFactory> */
    use HasFactory;

    /** @return BelongsTo<Card, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'amount_cents' => 'integer',
        ];
    }
}
