<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BudgetLinePaymentFactory;
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
 * @property int $budget_line_id
 * @property CarbonImmutable $month
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BudgetLine $budgetLine
 */
#[Fillable(['month'])]
class BudgetLinePayment extends Model
{
    /** @use HasFactory<BudgetLinePaymentFactory> */
    use HasFactory;

    /** @return BelongsTo<BudgetLine, $this> */
    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
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
