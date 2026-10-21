<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use Carbon\CarbonImmutable;
use Database\Factories\CashPurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A compra avulsa da fatura. Existe para responder "para onde foi o dinheiro"
 * e nada mais: nao gera parcela, nao entra na projecao, nao aparece no
 * historico. E fato passado, nao compromisso futuro — que e o que o resto do
 * app rastreia.
 *
 * @property int $id
 * @property int $card_id
 * @property string $description
 * @property int $amount_cents
 * @property CarbonImmutable $purchased_at
 * @property CarbonImmutable $billed_at
 * @property ExpenseCategory|null $category
 * @property bool $category_is_manual
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Card $card
 */
#[Fillable(['card_id', 'description', 'amount_cents', 'purchased_at', 'billed_at', 'category', 'category_is_manual'])]
class CashPurchase extends Model
{
    /** @use HasFactory<CashPurchaseFactory> */
    use HasFactory;

    /** @return BelongsTo<Card, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /** @param Builder<CashPurchase> $query */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->whereHas(
            'card',
            fn (Builder $cards) => $cards->where('user_id', $user->id),
        );
    }

    /**
     * As duas datas sao normalizadas na escrita por mutator, e nao pelo cast
     * `immutable_date`, porque a impressao digital do indice unico compara
     * texto. O cast padrao grava "2026-06-05 00:00:00" quando recebe um objeto
     * de data, e a gravacao em lote da importacao grava "2026-06-05": as duas
     * formas nunca casariam, e a duplicata passaria pelo indice em silencio —
     * exatamente o que ele existe para impedir.
     *
     * `immutable_date:Y-m-d` nao resolve: um cast com formato proprio vira
     * `immutable_custom_datetime`, que o Eloquent nao reconhece como data, e
     * entao o ramo que formataria o valor na escrita nunca roda.
     *
     * @return Attribute<CarbonImmutable, string>
     */
    protected function purchasedAt(): Attribute
    {
        return $this->dateOnly();
    }

    /** @return Attribute<CarbonImmutable, string> */
    protected function billedAt(): Attribute
    {
        return $this->dateOnly();
    }

    /** @return Attribute<CarbonImmutable, string> */
    private function dateOnly(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): CarbonImmutable => CarbonImmutable::parse($value),
            set: fn (mixed $value): string => CarbonImmutable::parse($value)->toDateString(),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'category' => ExpenseCategory::class,
            'category_is_manual' => 'boolean',
        ];
    }
}
