<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use Carbon\CarbonImmutable;
use Database\Factories\CashPurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            /**
             * Formato explicito, nao so 'immutable_date': o cast sem formato
             * so trunca a hora na leitura, mas grava com hora zerada no
             * formato completo do grammar. O indice unico de reimportacao
             * compara string contra o insert bruto do importador, que grava
             * so a data — sem o formato explicito as duas gravacoes divergem
             * em string e a duplicata escapa do unico.
             */
            'purchased_at' => 'immutable_date:Y-m-d',
            'billed_at' => 'immutable_date:Y-m-d',
            'amount_cents' => 'integer',
            'category' => ExpenseCategory::class,
            'category_is_manual' => 'boolean',
        ];
    }
}
