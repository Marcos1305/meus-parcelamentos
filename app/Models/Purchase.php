<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use Carbon\CarbonImmutable;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $card_id
 * @property string $description
 * @property string|null $merchant
 * @property string|null $notes
 * @property int $total_amount_cents
 * @property int $installments_count
 * @property CarbonImmutable $purchased_at
 * @property ExpenseCategory|null $category
 * @property bool $category_is_manual
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Card $card
 * @property-read Collection<int, Installment> $installments
 */
#[Fillable(['card_id', 'description', 'merchant', 'notes', 'total_amount_cents', 'installments_count', 'purchased_at', 'category', 'category_is_manual'])]
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    /**
     * Limites de dominio de uma compra, compartilhados entre o lancamento
     * manual e a importacao de fatura para que os dois caminhos de escrita
     * nunca divirjam sobre o que e uma compra valida.
     */
    public const int MAX_DESCRIPTION_LENGTH = 120;

    /**
     * A observacao e texto corrido escrito pelo usuario, nao rotulo: cabe
     * mais que os 120 caracteres da descricao, mas segue limitada para que a
     * coluna nao vire deposito de documento.
     */
    public const int MAX_NOTES_LENGTH = 1000;

    public const int MAX_INSTALLMENTS_COUNT = 48;

    /** @return BelongsTo<Card, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /** @return HasMany<Installment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('number');
    }

    /** @param Builder<Purchase> $query */
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
            'purchased_at' => 'immutable_date',
            'total_amount_cents' => 'integer',
            'installments_count' => 'integer',
            'category' => ExpenseCategory::class,
            'category_is_manual' => 'boolean',
        ];
    }
}
