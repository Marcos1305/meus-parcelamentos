<?php

namespace App\Models;

use App\Enums\BudgetLineKind;
use Carbon\CarbonImmutable;
use Database\Factories\BudgetLineFactory;
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
 * @property int $user_id
 * @property BudgetLineKind $kind
 * @property string $name
 * @property string|null $color
 * @property int $default_amount_cents
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, BudgetLineAmount> $amounts
 * @property-read Collection<int, BudgetLinePayment> $payments
 */
#[Fillable(['kind', 'name', 'color', 'default_amount_cents', 'position'])]
class BudgetLine extends Model
{
    /** @use HasFactory<BudgetLineFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<BudgetLineAmount, $this> */
    public function amounts(): HasMany
    {
        return $this->hasMany(BudgetLineAmount::class);
    }

    /** @return HasMany<BudgetLinePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(BudgetLinePayment::class);
    }

    /** @param Builder<BudgetLine> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BudgetLineKind::class,
            'default_amount_cents' => 'integer',
            'position' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
