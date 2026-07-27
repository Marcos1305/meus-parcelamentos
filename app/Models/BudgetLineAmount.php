<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BudgetLineAmountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $budget_line_id
 * @property CarbonImmutable $month
 * @property int $amount_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BudgetLine $budgetLine
 */
#[Fillable(['month', 'amount_cents'])]
class BudgetLineAmount extends Model
{
    /** @use HasFactory<BudgetLineAmountFactory> */
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
            'amount_cents' => 'integer',
        ];
    }
}
