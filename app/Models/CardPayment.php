<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CardPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A presenca da linha e a marca de pago. Nao ha coluna de estado: sem
 * registro, o mes nao foi pago.
 *
 * @property int $id
 * @property int $card_id
 * @property CarbonImmutable $month
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Card $card
 */
#[Fillable(['month'])]
class CardPayment extends Model
{
    /** @use HasFactory<CardPaymentFactory> */
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
        ];
    }
}
