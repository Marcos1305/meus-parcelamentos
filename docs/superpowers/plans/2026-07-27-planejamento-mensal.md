# Planejamento Mensal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Uma planilha anual que soma contas fixas, renda e parcelamentos por mês, com correção manual do valor de cada cartão.

**Architecture:** Três tabelas novas (`budget_lines`, `budget_line_amounts`, `card_month_amounts`). Uma ação `BuildAnnualPlan` resolve os 12 meses do ano lendo parcelas, padrões e valores gravados. A tela é uma grade com células que salvam ao perder o foco, uma requisição por célula.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, Inertia v3, React 19, TypeScript, Tailwind v4, Wayfinder.

**Spec:** `docs/superpowers/specs/2026-07-27-planejamento-mensal-design.md`

## Global Constraints

- Todos os valores monetários são inteiros em centavos, nunca float.
- `month` trafega na URL e no JSON como `YYYY-MM`; na coluna é `date` no dia 1 do mês.
- Nos dois `PUT` de célula, `amount_cents: null` apaga o registro. Campo **ausente** é requisição malformada (422), para que um erro de serialização no cliente não vire apagamento silencioso.
- `amount_cents` e `default_amount_cents` são não-negativos. O sinal vem do `kind`.
- Recurso de outro usuário devolve **404**, resolvido pelo relacionamento do usuário (`$request->user()->budgetLines()->findOrFail(...)`), não por policy. Isto difere das rotas antigas de cartão, que usam `->can()` e devolvem 403; retrofit delas está fora de escopo.
- Tudo é ancorado na data de vencimento. A coluna de um mês contém o que vence naquele mês.
- `BuildAnnualPlan` conta **todas** as parcelas do mês, vencidas ou não. `BuildMonthlyProjection` **não pode ser alterado**.
- Não existe botão "Salvar Alterações". Cada célula salva sozinha ao perder o foco.
- Comentários documentam o código, não o processo. Nada de "melhoria em relação a...".
- Rodar `vendor/bin/pint --dirty --format agent` antes de cada commit.
- Rodar `php artisan test --compact --filter=<Arquivo>` a cada tarefa; `composer run ci:check` antes do commit final de cada tarefa.

## Estrutura de arquivos

**Criar:**

| arquivo | responsabilidade |
|---|---|
| `app/Enums/BudgetLineKind.php` | `Income` \| `Expense` |
| `database/migrations/*_create_budget_lines_table.php` | linhas recorrentes |
| `database/migrations/*_create_budget_line_amounts_table.php` | valor de uma linha num mês |
| `database/migrations/*_create_card_month_amounts_table.php` | correção de um cartão num mês |
| `app/Models/BudgetLine.php` | modelo + escopo `active` |
| `app/Models/BudgetLineAmount.php` | modelo |
| `app/Models/CardMonthAmount.php` | modelo |
| `database/factories/BudgetLineFactory.php` | + estados `income`, `expense`, `archived` |
| `database/factories/BudgetLineAmountFactory.php` | |
| `database/factories/CardMonthAmountFactory.php` | |
| `app/Actions/BuildAnnualPlan.php` | resolve os 12 meses |
| `app/Http/Controllers/PlanController.php` | a tela |
| `app/Http/Controllers/BudgetLineController.php` | criar, editar, arquivar linha |
| `app/Http/Controllers/MonthAmountController.php` | as duas células |
| `app/Http/Requests/StoreBudgetLineRequest.php` | |
| `app/Http/Requests/UpdateBudgetLineRequest.php` | |
| `app/Http/Requests/UpdateMonthAmountRequest.php` | compartilhado pelos dois `PUT` |
| `routes/planejamento.php` | rotas do módulo |
| `resources/js/pages/plan/index.tsx` | shell da tela, troca de ano |
| `resources/js/components/plan-grid.tsx` | a tabela |
| `resources/js/components/plan-cell.tsx` | célula editável |
| `resources/js/components/budget-line-cell.tsx` | nome, ordem e arquivar da linha |
| `resources/js/components/budget-line-form.tsx` | criar linha |
| `tests/Feature/BudgetLineTest.php` | modelo e escopo da linha (Task 1) |
| `tests/Feature/MonthAmountTest.php` | unicidade e cascata dos valores (Task 2) |
| `tests/Feature/BuildAnnualPlanTest.php` | resolução dos 12 meses (Task 3) |
| `tests/Feature/PlanScreenTest.php` | a tela e o seletor de ano (Task 4) |
| `tests/Feature/PlanEndpointsTest.php` | células e CRUD de linha (Task 5) |

**Modificar:**

| arquivo | mudança |
|---|---|
| `app/Models/User.php` | relação `budgetLines()` |
| `app/Models/Card.php` | relação `monthAmounts()` |
| `routes/web.php` | `require __DIR__.'/planejamento.php';` |
| `resources/js/types/parcelamentos.ts` | tipos do plano |
| `resources/js/components/app-sidebar.tsx` | item "Planejamento" |

---

### Task 1: Enum, tabela e modelo de `budget_lines`

**Files:**
- Create: `app/Enums/BudgetLineKind.php`
- Create: `database/migrations/*_create_budget_lines_table.php`
- Create: `app/Models/BudgetLine.php`
- Create: `database/factories/BudgetLineFactory.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/BudgetLineTest.php`

**Interfaces:**
- Consumes: `App\Models\User` (já existe, tem `cards(): HasMany`).
- Produces: `BudgetLineKind::Income`/`::Expense`; `BudgetLine` com `$fillable = ['kind','name','default_amount_cents','position']`, casts `kind => BudgetLineKind`, `archived_at => immutable_datetime`, escopo `scopeActive`; `User::budgetLines(): HasMany<BudgetLine>`; `BudgetLineFactory` com estados `income()`, `expense()`, `archived()`.

- [ ] **Step 1: Criar o enum**

`app/Enums/BudgetLineKind.php`:

```php
<?php

namespace App\Enums;

enum BudgetLineKind: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Entrada',
            self::Expense => 'Saída',
        };
    }
}
```

- [ ] **Step 2: Gerar e escrever a migration**

Rodar: `php artisan make:migration create_budget_lines_table --no-interaction`

Conteúdo do `up()`:

```php
Schema::create('budget_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('kind');
    $table->string('name');
    $table->unsignedInteger('default_amount_cents');
    $table->unsignedInteger('position');
    $table->timestamp('archived_at')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'archived_at']);
});
```

`down()`: `Schema::dropIfExists('budget_lines');`

- [ ] **Step 3: Escrever o modelo**

`app/Models/BudgetLine.php`:

```php
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
 * @property int $default_amount_cents
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, BudgetLineAmount> $amounts
 */
#[Fillable(['kind', 'name', 'default_amount_cents', 'position'])]
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
```

Nota: `amounts()` aponta para `BudgetLineAmount`, criado na Task 2. Até lá o PHPStan reclama da classe inexistente — por isso a Task 2 vem antes de qualquer `ci:check` completo. Se quiser rodar o `ci:check` ao fim desta tarefa, crie a Task 2 primeiro ou omita `amounts()` aqui e adicione lá.

- [ ] **Step 4: Adicionar a relação em `User`**

Em `app/Models/User.php`, junto de `cards()`:

```php
/** @return HasMany<BudgetLine, $this> */
public function budgetLines(): HasMany
{
    return $this->hasMany(BudgetLine::class);
}
```

E no bloco de PHPDoc da classe: `@property-read Collection<int, BudgetLine> $budgetLines`.

- [ ] **Step 5: Escrever a factory**

`database/factories/BudgetLineFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\BudgetLineKind;
use App\Models\BudgetLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLine>
 */
class BudgetLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => BudgetLineKind::Expense,
            'name' => fake()->randomElement(['Energia', 'Água', 'Internet', 'Financiamento casa']),
            'default_amount_cents' => fake()->numberBetween(5_000, 200_000),
            'position' => fake()->numberBetween(0, 20),
            'archived_at' => null,
        ];
    }

    public function income(): static
    {
        return $this->state(fn (): array => [
            'kind' => BudgetLineKind::Income,
            'name' => 'Salário',
        ]);
    }

    public function expense(): static
    {
        return $this->state(fn (): array => ['kind' => BudgetLineKind::Expense]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
```

- [ ] **Step 6: Escrever o teste que falha**

`tests/Feature/BudgetLineTest.php`:

```php
<?php

use App\Enums\BudgetLineKind;
use App\Models\BudgetLine;
use App\Models\User;

it('guarda o tipo como enum', function (): void {
    $line = BudgetLine::factory()->income()->create();

    expect($line->fresh()->kind)->toBe(BudgetLineKind::Income);
});

it('o escopo active esconde linha arquivada', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->create(['name' => 'Energia']);
    BudgetLine::factory()->for($user)->archived()->create(['name' => 'Netflix']);

    $names = $user->budgetLines()->active()->pluck('name')->all();

    expect($names)->toBe(['Energia']);
});

it('apaga as linhas junto com o usuario', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->create();

    $user->delete();

    expect(BudgetLine::query()->count())->toBe(0);
});
```

- [ ] **Step 7: Rodar e ver falhar**

Rodar: `php artisan test --compact --filter=BudgetLineTest`
Esperado: FAIL — classe `App\Models\BudgetLine` não existe (se rodado antes dos steps 1-5) ou tabela ausente.

- [ ] **Step 8: Migrar e rodar até passar**

Rodar: `php artisan migrate --no-interaction && php artisan test --compact --filter=BudgetLineTest`
Esperado: PASS, 3 testes.

- [ ] **Step 9: Formatar e commitar**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/BudgetLineKind.php app/Models/BudgetLine.php app/Models/User.php database/migrations database/factories/BudgetLineFactory.php tests/Feature/BudgetLineTest.php
git commit -m "feat: linhas de orçamento recorrentes"
```

---

### Task 2: Tabelas de valores mensais

**Files:**
- Create: `database/migrations/*_create_budget_line_amounts_table.php`
- Create: `database/migrations/*_create_card_month_amounts_table.php`
- Create: `app/Models/BudgetLineAmount.php`
- Create: `app/Models/CardMonthAmount.php`
- Create: `database/factories/BudgetLineAmountFactory.php`
- Create: `database/factories/CardMonthAmountFactory.php`
- Modify: `app/Models/Card.php`
- Test: `tests/Feature/MonthAmountTest.php`

**Interfaces:**
- Consumes: `BudgetLine` (Task 1), `Card` (existente).
- Produces: `BudgetLineAmount` com `$fillable = ['month','amount_cents']`, cast `month => immutable_date`; `CardMonthAmount` idem; `Card::monthAmounts(): HasMany<CardMonthAmount>`.

- [ ] **Step 1: Gerar as migrations**

```bash
php artisan make:migration create_budget_line_amounts_table --no-interaction
php artisan make:migration create_card_month_amounts_table --no-interaction
```

`budget_line_amounts` — `up()`:

```php
Schema::create('budget_line_amounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('budget_line_id')->constrained()->cascadeOnDelete();
    $table->date('month');
    $table->unsignedInteger('amount_cents');
    $table->timestamps();

    $table->unique(['budget_line_id', 'month']);
});
```

`card_month_amounts` — `up()`:

```php
Schema::create('card_month_amounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('card_id')->constrained()->cascadeOnDelete();
    $table->date('month');
    $table->unsignedInteger('amount_cents');
    $table->timestamps();

    $table->unique(['card_id', 'month']);
});
```

- [ ] **Step 2: Escrever os modelos**

`app/Models/BudgetLineAmount.php`:

```php
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
```

`app/Models/CardMonthAmount.php`: idêntico em forma, trocando `budget_line_id` por `card_id`, `budgetLine()` por `card(): BelongsTo<Card, $this>` e a factory por `CardMonthAmountFactory`.

- [ ] **Step 3: Relação em `Card`**

Em `app/Models/Card.php`, junto de `purchases()`:

```php
/** @return HasMany<CardMonthAmount, $this> */
public function monthAmounts(): HasMany
{
    return $this->hasMany(CardMonthAmount::class);
}
```

E no PHPDoc da classe: `@property-read Collection<int, CardMonthAmount> $monthAmounts`.

- [ ] **Step 4: Escrever as factories**

`database/factories/BudgetLineAmountFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLineAmount>
 */
class BudgetLineAmountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_line_id' => BudgetLine::factory(),
            'month' => '2026-01-01',
            'amount_cents' => fake()->numberBetween(1_000, 300_000),
        ];
    }
}
```

`database/factories/CardMonthAmountFactory.php`: mesma forma, com `'card_id' => Card::factory()`.

- [ ] **Step 5: Escrever o teste que falha**

`tests/Feature/MonthAmountTest.php`:

```php
<?php

use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use App\Models\Card;
use App\Models\CardMonthAmount;
use Illuminate\Database\QueryException;

it('recusa dois valores para a mesma linha no mesmo mes', function (): void {
    $line = BudgetLine::factory()->create();
    BudgetLineAmount::factory()->for($line)->create(['month' => '2026-03-01']);

    expect(fn () => BudgetLineAmount::factory()->for($line)->create(['month' => '2026-03-01']))
        ->toThrow(QueryException::class);
});

it('recusa duas correcoes para o mesmo cartao no mesmo mes', function (): void {
    $card = Card::factory()->create();
    CardMonthAmount::factory()->for($card)->create(['month' => '2026-03-01']);

    expect(fn () => CardMonthAmount::factory()->for($card)->create(['month' => '2026-03-01']))
        ->toThrow(QueryException::class);
});

it('devolve o mes como data imutavel', function (): void {
    $amount = BudgetLineAmount::factory()->create(['month' => '2026-03-01']);

    expect($amount->fresh()->month->format('Y-m'))->toBe('2026-03');
});

it('apaga os valores junto com a linha', function (): void {
    $line = BudgetLine::factory()->create();
    BudgetLineAmount::factory()->for($line)->create();

    $line->delete();

    expect(BudgetLineAmount::query()->count())->toBe(0);
});
```

- [ ] **Step 6: Rodar até passar**

```bash
php artisan migrate --no-interaction
php artisan test --compact --filter=MonthAmountTest
```
Esperado: PASS, 4 testes.

- [ ] **Step 7: Verificação completa e commit**

```bash
vendor/bin/pint --dirty --format agent
composer run ci:check
git add database/migrations app/Models database/factories tests/Feature/MonthAmountTest.php
git commit -m "feat: valores mensais de linha e correção mensal de cartão"
```

---

### Task 3: `BuildAnnualPlan`

**Files:**
- Create: `app/Actions/BuildAnnualPlan.php`
- Test: `tests/Feature/BuildAnnualPlanTest.php`

**Interfaces:**
- Consumes: `BudgetLine`, `BudgetLineAmount`, `CardMonthAmount` (Tasks 1-2), `Card`, `Purchase`, `Installment`.
- Produces:

```php
BuildAnnualPlan::handle(User $user, int $year): array
```

com a forma:

```php
array{
    year: int,
    months: array<int, string>,
    cards: array<int, array{
        id: int, name: string, last_four_digits: string,
        months: array<string, array{computed_cents: int, override_cents: int|null, effective_cents: int}>,
    }>,
    lines: array<int, array{
        id: int, kind: string, name: string, default_amount_cents: int, position: int,
        months: array<string, array{amount_cents: int|null, effective_cents: int}>,
    }>,
    totals: array<string, array{income_cents: int, expense_cents: int, balance_cents: int}>,
}
```

`months` tem os 12 meses do ano em `YYYY-MM`, em ordem. As chaves de `months` dentro de cada linha e de `totals` são os mesmos 12 valores — sempre os 12, nunca esparso.

- [ ] **Step 1: Escrever os testes que falham**

`tests/Feature/BuildAnnualPlanTest.php`:

```php
<?php

use App\Actions\BuildAnnualPlan;
use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use App\Models\Card;
use App\Models\CardMonthAmount;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

function planFor(User $user, int $year = 2026): array
{
    return app(BuildAnnualPlan::class)->handle($user, $year);
}

function parcelaNoCartao(Card $card, string $dueDate, int $amountCents): Installment
{
    return Installment::factory()
        ->for(Purchase::factory()->for($card))
        ->create(['number' => 1, 'due_date' => $dueDate, 'amount_cents' => $amountCents]);
}

it('devolve os doze meses do ano', function (): void {
    $plan = planFor(User::factory()->create());

    expect($plan['months'])->toBe([
        '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06',
        '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12',
    ])->and($plan['year'])->toBe(2026);
});

it('usa o padrao da linha no mes sem valor gravado', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->expense()->create([
        'name' => 'Energia',
        'default_amount_cents' => 400_00,
    ]);

    $plan = planFor($user);

    expect($plan['lines'][0]['months']['2026-05'])
        ->toBe(['amount_cents' => null, 'effective_cents' => 400_00]);
});

it('o valor gravado vence o padrao', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->expense()->create(['default_amount_cents' => 400_00]);
    BudgetLineAmount::factory()->for($line)->create(['month' => '2026-05-01', 'amount_cents' => 573_74]);

    $plan = planFor($user);

    expect($plan['lines'][0]['months']['2026-05'])
        ->toBe(['amount_cents' => 573_74, 'effective_cents' => 573_74])
        ->and($plan['lines'][0]['months']['2026-06']['effective_cents'])->toBe(400_00);
});

it('soma as parcelas do mes no valor calculado do cartao', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2026-09-10', 100_00);
    parcelaNoCartao($card, '2026-09-20', 250_00);
    parcelaNoCartao($card, '2026-10-10', 900_00);

    $plan = planFor($user);

    expect($plan['cards'][0]['months']['2026-09']['computed_cents'])->toBe(350_00)
        ->and($plan['cards'][0]['months']['2026-10']['computed_cents'])->toBe(900_00);
});

it('conta parcela ja vencida do mes passado', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2026-02-10', 500_00);

    expect(planFor($user)['cards'][0]['months']['2026-02']['computed_cents'])->toBe(500_00);
});

it('a correcao vence o calculado sem apagar o calculado', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2026-09-10', 350_00);
    CardMonthAmount::factory()->for($card)->create(['month' => '2026-09-01', 'amount_cents' => 2_147_33]);

    expect(planFor($user)['cards'][0]['months']['2026-09'])->toBe([
        'computed_cents' => 350_00,
        'override_cents' => 2_147_33,
        'effective_cents' => 2_147_33,
    ]);
});

it('correcao de zero vale zero, nao volta para o calculado', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2026-09-10', 350_00);
    CardMonthAmount::factory()->for($card)->create(['month' => '2026-09-01', 'amount_cents' => 0]);

    expect(planFor($user)['cards'][0]['months']['2026-09']['effective_cents'])->toBe(0);
});

it('soma entrada, saida e saldo', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->income()->create(['default_amount_cents' => 14_500_00]);
    BudgetLine::factory()->for($user)->expense()->create(['default_amount_cents' => 400_00]);
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2026-09-10', 1_000_00);

    $totals = planFor($user)['totals']['2026-09'];

    expect($totals)->toBe([
        'income_cents' => 14_500_00,
        'expense_cents' => 1_400_00,
        'balance_cents' => 13_100_00,
    ]);
});

it('preserva saldo negativo', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->income()->create(['default_amount_cents' => 1_000_00]);
    BudgetLine::factory()->for($user)->expense()->create(['default_amount_cents' => 2_500_00]);

    expect(planFor($user)['totals']['2026-09']['balance_cents'])->toBe(-1_500_00);
});

it('ignora parcela de outro usuario', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();
    parcelaNoCartao(Card::factory()->for(User::factory()->create())->create(), '2026-09-10', 999_00);

    expect(planFor($user)['cards'][0]['months']['2026-09']['computed_cents'])->toBe(0);
});

it('ignora linha arquivada', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->create(['name' => 'Energia']);
    BudgetLine::factory()->for($user)->archived()->create(['name' => 'Netflix']);

    expect(array_column(planFor($user)['lines'], 'name'))->toBe(['Energia']);
});

it('ignora parcela de outro ano', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    parcelaNoCartao($card, '2027-03-10', 800_00);

    $plan = planFor($user, 2026);

    expect(array_sum(array_column($plan['cards'][0]['months'], 'computed_cents')))->toBe(0);
});

it('ordena as linhas por posicao', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->create(['name' => 'Agua', 'position' => 2]);
    BudgetLine::factory()->for($user)->create(['name' => 'Energia', 'position' => 1]);

    expect(array_column(planFor($user)['lines'], 'name'))->toBe(['Energia', 'Agua']);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Rodar: `php artisan test --compact --filter=BuildAnnualPlanTest`
Esperado: FAIL — `Target class [App\Actions\BuildAnnualPlan] does not exist.`

- [ ] **Step 3: Escrever a ação**

`app/Actions/BuildAnnualPlan.php`:

```php
<?php

namespace App\Actions;

use App\Enums\BudgetLineKind;
use App\Models\BudgetLine;
use App\Models\Card;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BuildAnnualPlan
{
    /**
     * @return array{
     *     year: int,
     *     months: array<int, string>,
     *     cards: array<int, array{id: int, name: string, last_four_digits: string, months: array<string, array{computed_cents: int, override_cents: int|null, effective_cents: int}>}>,
     *     lines: array<int, array{id: int, kind: string, name: string, default_amount_cents: int, position: int, months: array<string, array{amount_cents: int|null, effective_cents: int}>}>,
     *     totals: array<string, array{income_cents: int, expense_cents: int, balance_cents: int}>,
     * }
     */
    public function handle(User $user, int $year): array
    {
        $months = $this->months($year);

        $cards = $this->cards($user, $year, $months);
        $lines = $this->lines($user, $year, $months);

        return [
            'year' => $year,
            'months' => $months,
            'cards' => $cards,
            'lines' => $lines,
            'totals' => $this->totals($months, $cards, $lines),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function months(int $year): array
    {
        $january = CarbonImmutable::create($year, 1, 1);

        return array_map(
            fn (int $offset): string => $january->addMonths($offset)->format('Y-m'),
            range(0, 11),
        );
    }

    /**
     * @param  array<int, string>  $months
     * @return array<int, array{id: int, name: string, last_four_digits: string, months: array<string, array{computed_cents: int, override_cents: int|null, effective_cents: int}>}>
     */
    private function cards(User $user, int $year, array $months): array
    {
        $cards = $user->cards()->orderBy('name')->orderBy('id')->get();
        $cardIds = $cards->pluck('id')->all();

        $computed = $this->computedByCard($cardIds, $year);
        $overrides = $this->overridesByCard($cardIds, $year);

        return $cards->map(function (Card $card) use ($months, $computed, $overrides): array {
            $byMonth = [];

            foreach ($months as $month) {
                $computedCents = $computed[$card->id][$month] ?? 0;
                $overrideCents = $overrides[$card->id][$month] ?? null;

                $byMonth[$month] = [
                    'computed_cents' => $computedCents,
                    'override_cents' => $overrideCents,
                    'effective_cents' => $overrideCents ?? $computedCents,
                ];
            }

            return [
                'id' => $card->id,
                'name' => $card->name,
                'last_four_digits' => $card->last_four_digits,
                'months' => $byMonth,
            ];
        })->all();
    }

    /**
     * A soma das parcelas que vencem em cada mes, vencidas ou nao. O filtro de
     * parcela em aberto do dashboard responde outra pergunta e aqui zeraria
     * todo mes ja passado.
     *
     * @param  array<int, int>  $cardIds
     * @return array<int, array<string, int>>
     */
    private function computedByCard(array $cardIds, int $year): array
    {
        if ($cardIds === []) {
            return [];
        }

        $rows = DB::table('installments')
            ->join('purchases', 'purchases.id', '=', 'installments.purchase_id')
            ->whereIn('purchases.card_id', $cardIds)
            ->whereBetween('installments.due_date', ["{$year}-01-01", "{$year}-12-31"])
            ->get([
                'purchases.card_id as card_id',
                'installments.due_date as due_date',
                'installments.amount_cents as amount_cents',
            ]);

        $computed = [];

        foreach ($rows as $row) {
            $month = substr((string) $row->due_date, 0, 7);
            $cardId = (int) $row->card_id;

            $computed[$cardId][$month] = ($computed[$cardId][$month] ?? 0) + (int) $row->amount_cents;
        }

        return $computed;
    }

    /**
     * @param  array<int, int>  $cardIds
     * @return array<int, array<string, int>>
     */
    private function overridesByCard(array $cardIds, int $year): array
    {
        if ($cardIds === []) {
            return [];
        }

        $rows = DB::table('card_month_amounts')
            ->whereIn('card_id', $cardIds)
            ->whereBetween('month', ["{$year}-01-01", "{$year}-12-31"])
            ->get(['card_id', 'month', 'amount_cents']);

        $overrides = [];

        foreach ($rows as $row) {
            $overrides[(int) $row->card_id][substr((string) $row->month, 0, 7)] = (int) $row->amount_cents;
        }

        return $overrides;
    }

    /**
     * @param  array<int, string>  $months
     * @return array<int, array{id: int, kind: string, name: string, default_amount_cents: int, position: int, months: array<string, array{amount_cents: int|null, effective_cents: int}>}>
     */
    private function lines(User $user, int $year, array $months): array
    {
        $lines = $user->budgetLines()->active()->orderBy('position')->orderBy('id')->get();

        $amounts = $this->amountsByLine($lines->pluck('id')->all(), $year);

        return $lines->map(function (BudgetLine $line) use ($months, $amounts): array {
            $byMonth = [];

            foreach ($months as $month) {
                $amountCents = $amounts[$line->id][$month] ?? null;

                $byMonth[$month] = [
                    'amount_cents' => $amountCents,
                    'effective_cents' => $amountCents ?? $line->default_amount_cents,
                ];
            }

            return [
                'id' => $line->id,
                'kind' => $line->kind->value,
                'name' => $line->name,
                'default_amount_cents' => $line->default_amount_cents,
                'position' => $line->position,
                'months' => $byMonth,
            ];
        })->all();
    }

    /**
     * @param  array<int, int>  $lineIds
     * @return array<int, array<string, int>>
     */
    private function amountsByLine(array $lineIds, int $year): array
    {
        if ($lineIds === []) {
            return [];
        }

        $rows = DB::table('budget_line_amounts')
            ->whereIn('budget_line_id', $lineIds)
            ->whereBetween('month', ["{$year}-01-01", "{$year}-12-31"])
            ->get(['budget_line_id', 'month', 'amount_cents']);

        $amounts = [];

        foreach ($rows as $row) {
            $amounts[(int) $row->budget_line_id][substr((string) $row->month, 0, 7)] = (int) $row->amount_cents;
        }

        return $amounts;
    }

    /**
     * @param  array<int, string>  $months
     * @param  array<int, array<string, mixed>>  $cards
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, array{income_cents: int, expense_cents: int, balance_cents: int}>
     */
    private function totals(array $months, array $cards, array $lines): array
    {
        $totals = [];

        foreach ($months as $month) {
            $income = 0;
            $expense = 0;

            foreach ($lines as $line) {
                $effective = $line['months'][$month]['effective_cents'];

                if ($line['kind'] === BudgetLineKind::Income->value) {
                    $income += $effective;

                    continue;
                }

                $expense += $effective;
            }

            foreach ($cards as $card) {
                $expense += $card['months'][$month]['effective_cents'];
            }

            $totals[$month] = [
                'income_cents' => $income,
                'expense_cents' => $expense,
                'balance_cents' => $income - $expense,
            ];
        }

        return $totals;
    }
}
```

- [ ] **Step 4: Rodar até passar**

Rodar: `php artisan test --compact --filter=BuildAnnualPlanTest`
Esperado: PASS, 13 testes.

- [ ] **Step 5: Provar que os testes não são vazios**

Trocar temporariamente `$overrideCents ?? $computedCents` por `$computedCents` e rodar de novo.
Esperado: FAIL em "a correcao vence o calculado" e "correcao de zero vale zero". Reverter.

- [ ] **Step 6: Formatar e commitar**

```bash
vendor/bin/pint --dirty --format agent
composer run ci:check
git add app/Actions/BuildAnnualPlan.php tests/Feature/BuildAnnualPlanTest.php
git commit -m "feat: resolução do plano anual"
```

---

### Task 4: A tela (somente leitura)

**Files:**
- Create: `app/Http/Controllers/PlanController.php`
- Create: `routes/planejamento.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PlanScreenTest.php`

**Interfaces:**
- Consumes: `BuildAnnualPlan::handle(User, int): array` (Task 3).
- Produces: rota nomeada `plan.index` em `GET /planejamento`, componente Inertia `plan/index`, props `plan` (a saída de `BuildAnnualPlan`) e `years` (`array<int, int>`).

- [ ] **Step 1: Escrever o teste que falha**

`tests/Feature/PlanScreenTest.php`:

```php
<?php

use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

test('visitante e mandado para o login', function (): void {
    $this->get(route('plan.index'))->assertRedirect(route('login'));
});

it('entrega os doze meses do ano corrente por padrao', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('plan.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('plan/index')
            ->where('plan.year', 2026)
            ->has('plan.months', 12));
});

it('aceita o ano na query string', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('plan.index', ['ano' => 2027]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('plan.year', 2027));
});

it('cai no ano corrente quando o ano e invalido', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('plan.index', ['ano' => 'abacaxi']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('plan.year', 2026));
});

it('oferece o ano anterior, o corrente e o seguinte', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('plan.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('years', [2025, 2026, 2027]));
});
```

- [ ] **Step 2: Rodar e ver falhar**

Rodar: `php artisan test --compact --filter=PlanScreenTest`
Esperado: FAIL — rota `plan.index` não definida.

- [ ] **Step 3: Escrever o controller**

`app/Http/Controllers/PlanController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\BuildAnnualPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    /**
     * Limites do seletor de ano. Fora deles a tela projeta um horizonte que
     * nao tem lastro em parcelamento nenhum.
     */
    private const FIRST_YEAR = 2000;

    private const LAST_YEAR = 2100;

    public function index(Request $request, BuildAnnualPlan $buildAnnualPlan): Response
    {
        $currentYear = CarbonImmutable::now()->year;
        $year = $this->year($request, $currentYear);

        return Inertia::render('plan/index', [
            'plan' => $buildAnnualPlan->handle($request->user(), $year),
            'years' => [$currentYear - 1, $currentYear, $currentYear + 1],
        ]);
    }

    private function year(Request $request, int $currentYear): int
    {
        $raw = $request->query('ano');

        if (! is_string($raw) || preg_match('/^\d{4}$/', $raw) !== 1) {
            return $currentYear;
        }

        $year = (int) $raw;

        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR) {
            return $currentYear;
        }

        return $year;
    }
}
```

- [ ] **Step 4: Criar o arquivo de rotas**

`routes/planejamento.php`:

```php
<?php

use App\Http\Controllers\PlanController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('planejamento', [PlanController::class, 'index'])->name('plan.index');
});
```

Em `routes/web.php`, depois de `require __DIR__.'/parcelamentos.php';`:

```php
require __DIR__.'/planejamento.php';
```

- [ ] **Step 5: Adicionar os tipos do plano**

Ao fim de `resources/js/types/parcelamentos.ts`:

```ts
export type PlanCardMonth = {
    computed_cents: number;
    override_cents: number | null;
    effective_cents: number;
};

export type PlanLineMonth = {
    amount_cents: number | null;
    effective_cents: number;
};

export type PlanCardRow = {
    id: number;
    name: string;
    last_four_digits: string;
    months: Record<string, PlanCardMonth>;
};

export type PlanLineRow = {
    id: number;
    kind: 'income' | 'expense';
    name: string;
    default_amount_cents: number;
    position: number;
    months: Record<string, PlanLineMonth>;
};

export type PlanMonthTotals = {
    income_cents: number;
    expense_cents: number;
    balance_cents: number;
};

export type AnnualPlan = {
    year: number;
    months: string[];
    cards: PlanCardRow[];
    lines: PlanLineRow[];
    totals: Record<string, PlanMonthTotals>;
};
```

- [ ] **Step 6: Criar um componente mínimo para a tela responder**

`resources/js/pages/plan/index.tsx`:

```tsx
import { Head } from '@inertiajs/react';
import planRoute from '@/routes/plan';
import type { AnnualPlan } from '@/types';

export default function Plan({ plan }: { plan: AnnualPlan }) {
    return (
        <>
            <Head title="Planejamento" />
            <div className="p-4">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Planejamento {plan.year}
                </h1>
            </div>
        </>
    );
}

Plan.layout = {
    breadcrumbs: [{ title: 'Planejamento', href: planRoute.index() }],
};
```

O helper `planRoute` do Wayfinder só existe depois de `npm run build` (ou com o `vite` rodando), porque é gerado a partir das rotas PHP. Rode o build antes de conferir o `tsc`.

- [ ] **Step 7: Rodar até passar**

Rodar: `php artisan test --compact --filter=PlanScreenTest`
Esperado: PASS, 5 testes.

- [ ] **Step 8: Formatar e commitar**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/PlanController.php routes tests/Feature/PlanScreenTest.php resources/js/pages/plan resources/js/types/parcelamentos.ts
git commit -m "feat: tela de planejamento anual"
```

---

### Task 5: Endpoints de célula e de linha

**Files:**
- Create: `app/Http/Controllers/MonthAmountController.php`
- Create: `app/Http/Controllers/BudgetLineController.php`
- Create: `app/Http/Requests/UpdateMonthAmountRequest.php`
- Create: `app/Http/Requests/StoreBudgetLineRequest.php`
- Create: `app/Http/Requests/UpdateBudgetLineRequest.php`
- Modify: `routes/planejamento.php`
- Test: `tests/Feature/PlanEndpointsTest.php`

**Interfaces:**
- Consumes: `BudgetLine`, `BudgetLineAmount`, `CardMonthAmount` (Tasks 1-2); `User::budgetLines()`; `User::cards()`.
- Produces: rotas nomeadas `plan.lines.amount`, `plan.cards.amount`, `plan.lines.store`, `plan.lines.update`, `plan.lines.destroy`.

- [ ] **Step 1: Escrever os testes que falham**

`tests/Feature/PlanEndpointsTest.php`:

```php
<?php

use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use App\Models\Card;
use App\Models\CardMonthAmount;
use App\Models\User;

it('grava o valor de uma linha num mes', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), ['amount_cents' => 573_74])
        ->assertRedirect();

    expect(BudgetLineAmount::query()->where('budget_line_id', $line->id)->value('amount_cents'))
        ->toBe(573_74);
});

it('sobrescreve o valor ja gravado do mesmo mes', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();
    BudgetLineAmount::factory()->for($line)->create(['month' => '2026-05-01', 'amount_cents' => 100_00]);

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), ['amount_cents' => 200_00]);

    expect(BudgetLineAmount::query()->count())->toBe(1)
        ->and(BudgetLineAmount::query()->value('amount_cents'))->toBe(200_00);
});

it('amount_cents nulo apaga o valor do mes', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();
    BudgetLineAmount::factory()->for($line)->create(['month' => '2026-05-01']);

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), ['amount_cents' => null]);

    expect(BudgetLineAmount::query()->count())->toBe(0);
});

it('recusa amount_cents ausente para nao apagar por acidente', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), [])
        ->assertSessionHasErrors('amount_cents');
});

it('recusa valor negativo', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), ['amount_cents' => -1])
        ->assertSessionHasErrors('amount_cents');
});

it('recusa mes malformado', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-13']), ['amount_cents' => 100])
        ->assertNotFound();
});

it('nao enxerga linha de outro usuario', function (): void {
    $alheia = BudgetLine::factory()->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->put(route('plan.lines.amount', [$alheia->id, '2026-05']), ['amount_cents' => 100])
        ->assertNotFound();
});

it('recusa valor em linha arquivada', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->archived()->create();

    $this->actingAs($user)
        ->put(route('plan.lines.amount', [$line->id, '2026-05']), ['amount_cents' => 100])
        ->assertNotFound();
});

it('grava a correcao de um cartao', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.cards.amount', [$card->id, '2026-09']), ['amount_cents' => 2_147_33]);

    expect(CardMonthAmount::query()->value('amount_cents'))->toBe(2_147_33);
});

it('amount_cents nulo apaga a correcao do cartao', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    CardMonthAmount::factory()->for($card)->create(['month' => '2026-09-01']);

    $this->actingAs($user)
        ->put(route('plan.cards.amount', [$card->id, '2026-09']), ['amount_cents' => null]);

    expect(CardMonthAmount::query()->count())->toBe(0);
});

it('aceita correcao de zero', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->put(route('plan.cards.amount', [$card->id, '2026-09']), ['amount_cents' => 0]);

    expect(CardMonthAmount::query()->value('amount_cents'))->toBe(0);
});

it('nao enxerga cartao de outro usuario', function (): void {
    $alheio = Card::factory()->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->put(route('plan.cards.amount', [$alheio->id, '2026-09']), ['amount_cents' => 100])
        ->assertNotFound();
});

it('cria uma linha no fim da lista', function (): void {
    $user = User::factory()->create();
    BudgetLine::factory()->for($user)->create(['position' => 4]);

    $this->actingAs($user)->post(route('plan.lines.store'), [
        'kind' => 'expense',
        'name' => 'Internet',
        'default_amount_cents' => 99_90,
    ]);

    $line = BudgetLine::query()->where('name', 'Internet')->firstOrFail();

    expect($line->position)->toBe(5)
        ->and($line->user_id)->toBe($user->id);
});

it('recusa linha sem nome', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('plan.lines.store'), [
            'kind' => 'expense',
            'name' => '',
            'default_amount_cents' => 100,
        ])
        ->assertSessionHasErrors('name');
});

it('recusa tipo fora do enum', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('plan.lines.store'), [
            'kind' => 'investimento',
            'name' => 'Tesouro',
            'default_amount_cents' => 100,
        ])
        ->assertSessionHasErrors('kind');
});

it('renomeia a linha', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create(['name' => 'Luz']);

    $this->actingAs($user)->patch(route('plan.lines.update', $line->id), [
        'name' => 'Energia',
        'default_amount_cents' => 400_00,
        'position' => 1,
    ]);

    expect($line->fresh()->name)->toBe('Energia');
});

it('arquiva a linha em vez de apagar', function (): void {
    $user = User::factory()->create();
    $line = BudgetLine::factory()->for($user)->create();

    $this->actingAs($user)->delete(route('plan.lines.destroy', $line->id));

    expect($line->fresh()->archived_at)->not->toBeNull()
        ->and(BudgetLine::query()->count())->toBe(1);
});

it('nao arquiva linha de outro usuario', function (): void {
    $alheia = BudgetLine::factory()->for(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('plan.lines.destroy', $alheia->id))
        ->assertNotFound();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Rodar: `php artisan test --compact --filter=PlanEndpointsTest`
Esperado: FAIL — rotas não definidas.

- [ ] **Step 3: Escrever os form requests**

`app/Http/Requests/UpdateMonthAmountRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMonthAmountRequest extends FormRequest
{
    /**
     * `present` e nao `required`: null e um valor legitimo — apaga o registro —
     * mas o campo ausente e requisicao malformada. Sem isso, um erro de
     * serializacao no cliente apagaria em silencio um valor digitado.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount_cents' => ['present', 'nullable', 'integer', 'min:0'],
        ];
    }
}
```

`app/Http/Requests/StoreBudgetLineRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\BudgetLineKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetLineRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(BudgetLineKind::class)],
            'name' => ['required', 'string', 'max:60'],
            'default_amount_cents' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

`app/Http/Requests/UpdateBudgetLineRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetLineRequest extends FormRequest
{
    /**
     * O tipo nao muda depois de criado: virar uma despesa em entrada mudaria
     * o sinal de todos os meses ja preenchidos sem que o usuario perceba.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'default_amount_cents' => ['required', 'integer', 'min:0'],
            'position' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

- [ ] **Step 4: Escrever o controller das células**

`app/Http/Controllers/MonthAmountController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMonthAmountRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class MonthAmountController extends Controller
{
    public function line(UpdateMonthAmountRequest $request, int $budgetLine, string $month): RedirectResponse
    {
        $line = $request->user()->budgetLines()->active()->findOrFail($budgetLine);
        $firstDay = $this->firstDayOfMonth($month);
        $amountCents = $request->validated('amount_cents');

        if ($amountCents === null) {
            $line->amounts()->where('month', $firstDay)->delete();

            return back();
        }

        $line->amounts()->updateOrCreate(
            ['month' => $firstDay],
            ['amount_cents' => $amountCents],
        );

        return back();
    }

    public function card(UpdateMonthAmountRequest $request, int $card, string $month): RedirectResponse
    {
        $owned = $request->user()->cards()->findOrFail($card);
        $firstDay = $this->firstDayOfMonth($month);
        $amountCents = $request->validated('amount_cents');

        if ($amountCents === null) {
            $owned->monthAmounts()->where('month', $firstDay)->delete();

            return back();
        }

        $owned->monthAmounts()->updateOrCreate(
            ['month' => $firstDay],
            ['amount_cents' => $amountCents],
        );

        return back();
    }

    /**
     * O mes vem da URL, entao nao passa por validacao de corpo. Formato
     * invalido e 404: a celula pedida nao existe.
     */
    private function firstDayOfMonth(string $month): string
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            abort(404);
        }

        return CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01")->toDateString();
    }
}
```

Os dois métodos repetem oito linhas de propósito. Um método compartilhado teria de receber duas relações `HasMany` de modelos diferentes, e o único tipo que aceita as duas é `mixed` — um tipo que mente para o PHPStan e esconde exatamente o que o nível 7 existe para pegar. Repetição visível custa menos que tipagem falsa.

- [ ] **Step 5: Escrever o controller das linhas**

`app/Http/Controllers/BudgetLineController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBudgetLineRequest;
use App\Http\Requests\UpdateBudgetLineRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BudgetLineController extends Controller
{
    public function store(StoreBudgetLineRequest $request): RedirectResponse
    {
        $request->user()->budgetLines()->create([
            ...$request->validated(),
            'position' => (int) $request->user()->budgetLines()->max('position') + 1,
        ]);

        return back();
    }

    public function update(UpdateBudgetLineRequest $request, int $budgetLine): RedirectResponse
    {
        $request->user()->budgetLines()->findOrFail($budgetLine)->update($request->validated());

        return back();
    }

    public function destroy(Request $request, int $budgetLine): RedirectResponse
    {
        $request->user()->budgetLines()->findOrFail($budgetLine)
            ->forceFill(['archived_at' => now()])
            ->save();

        return back();
    }
}
```

`archived_at` fica fora do `#[Fillable]` de propósito, então `update(['archived_at' => ...])` seria ignorado em silêncio — o teste "arquiva a linha em vez de apagar" pega isso. Arquivar é decisão do servidor, não campo que o cliente manda: daí o `forceFill`.

- [ ] **Step 6: Registrar as rotas**

Em `routes/planejamento.php`, dentro do grupo existente:

```php
Route::post('planejamento/linhas', [BudgetLineController::class, 'store'])->name('plan.lines.store');
Route::patch('planejamento/linhas/{budgetLine}', [BudgetLineController::class, 'update'])->name('plan.lines.update');
Route::delete('planejamento/linhas/{budgetLine}', [BudgetLineController::class, 'destroy'])->name('plan.lines.destroy');
Route::put('planejamento/linhas/{budgetLine}/{month}', [MonthAmountController::class, 'line'])->name('plan.lines.amount');
Route::put('planejamento/cartoes/{card}/{month}', [MonthAmountController::class, 'card'])->name('plan.cards.amount');
```

- [ ] **Step 7: Rodar até passar**

Rodar: `php artisan test --compact --filter=PlanEndpointsTest`
Esperado: PASS, 18 testes.

- [ ] **Step 8: Provar que os testes não são vazios**

Trocar `'present'` por `'sometimes'` na regra de `amount_cents` e rodar.
Esperado: FAIL em "recusa amount_cents ausente". Reverter.

- [ ] **Step 9: Formatar e commitar**

```bash
vendor/bin/pint --dirty --format agent
composer run ci:check
git add app/Http routes tests/Feature/PlanEndpointsTest.php
git commit -m "feat: endpoints de célula e de linha do planejamento"
```

---

### Task 6: Grade somente leitura

**Files:**
- Create: `resources/js/components/plan-grid.tsx`
- Modify: `resources/js/pages/plan/index.tsx`
- Modify: `resources/js/components/app-sidebar.tsx`

**Interfaces:**
- Consumes: props `plan: AnnualPlan` e `years: number[]` da Task 4; tipos `AnnualPlan`, `PlanCardRow`, `PlanLineRow`, `PlanMonthTotals` já definidos na Task 4.
- Produces: componente `<PlanGrid plan={...} />`.

- [ ] **Step 1: Escrever a grade**

`resources/js/components/plan-grid.tsx`:

```tsx
import { formatBRL } from '@/lib/currency';
import { cn } from '@/lib/utils';
import type { AnnualPlan } from '@/types';

const MONTH_LABEL = new Intl.DateTimeFormat('pt-BR', {
    month: 'short',
    timeZone: 'UTC',
});

function monthHeader(month: string): string {
    return MONTH_LABEL.format(new Date(`${month}-01T00:00:00Z`))
        .replace('.', '')
        .toUpperCase();
}

const HEAD_CELL = 'px-3 py-2 text-right text-xs font-semibold whitespace-nowrap';
const BODY_CELL = 'px-3 py-2 text-right text-sm tabular-nums whitespace-nowrap';
const STICKY = 'sticky left-0 z-10 bg-card px-3 py-2 text-left text-sm font-medium';

export function PlanGrid({ plan }: { plan: AnnualPlan }) {
    const incomeLines = plan.lines.filter((line) => line.kind === 'income');
    const expenseLines = plan.lines.filter((line) => line.kind === 'expense');

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full border-collapse">
                <thead className="bg-muted">
                    <tr>
                        <th className={cn(STICKY, 'bg-muted text-xs uppercase')}>
                            Categoria
                        </th>
                        {plan.months.map((month) => (
                            <th key={month} className={HEAD_CELL}>
                                {monthHeader(month)}
                            </th>
                        ))}
                    </tr>
                </thead>

                <tbody className="divide-y">
                    {incomeLines.map((line) => (
                        <tr key={line.id}>
                            <td className={STICKY}>{line.name}</td>
                            {plan.months.map((month) => (
                                <td key={month} className={BODY_CELL}>
                                    {formatBRL(
                                        line.months[month].effective_cents,
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}

                    {plan.cards.map((card) => (
                        <tr key={`card-${card.id}`}>
                            <td className={STICKY}>
                                {card.name} • {card.last_four_digits}
                            </td>
                            {plan.months.map((month) => (
                                <td key={month} className={BODY_CELL}>
                                    {formatBRL(
                                        card.months[month].effective_cents,
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}

                    {expenseLines.map((line) => (
                        <tr key={line.id}>
                            <td className={STICKY}>{line.name}</td>
                            {plan.months.map((month) => (
                                <td key={month} className={BODY_CELL}>
                                    {formatBRL(
                                        line.months[month].effective_cents,
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>

                <tfoot className="border-t-2">
                    <tr className="bg-secondary/10">
                        <td className={cn(STICKY, 'bg-card font-semibold')}>
                            ENTRADA
                        </td>
                        {plan.months.map((month) => (
                            <td key={month} className={BODY_CELL}>
                                {formatBRL(plan.totals[month].income_cents)}
                            </td>
                        ))}
                    </tr>
                    <tr className="bg-destructive/10">
                        <td className={cn(STICKY, 'bg-card font-semibold')}>
                            SAÍDA
                        </td>
                        {plan.months.map((month) => (
                            <td key={month} className={BODY_CELL}>
                                {formatBRL(plan.totals[month].expense_cents)}
                            </td>
                        ))}
                    </tr>
                    <tr className="bg-primary/20">
                        <td className={cn(STICKY, 'bg-card font-semibold')}>
                            SALDO
                        </td>
                        {plan.months.map((month) => (
                            <td
                                key={month}
                                className={cn(
                                    BODY_CELL,
                                    'font-semibold',
                                    plan.totals[month].balance_cents < 0 &&
                                        'text-destructive',
                                )}
                            >
                                {formatBRL(plan.totals[month].balance_cents)}
                            </td>
                        ))}
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}
```

- [ ] **Step 2: Montar a página com troca de ano**

`resources/js/pages/plan/index.tsx`:

```tsx
import { Head, Link } from '@inertiajs/react';
import { PlanGrid } from '@/components/plan-grid';
import { cn } from '@/lib/utils';
import { plan as planRoute } from '@/routes';
import type { AnnualPlan } from '@/types';

type PlanProps = {
    plan: AnnualPlan;
    years: number[];
};

export default function Plan({ plan, years }: PlanProps) {
    return (
        <>
            <Head title="Planejamento" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Gastos {plan.year}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Contas fixas, cartões e renda mês a mês
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {years.map((year) => (
                            <Link
                                key={year}
                                href={planRoute.index({
                                    query: { ano: String(year) },
                                })}
                                className={cn(
                                    'rounded-full border px-4 py-1.5 text-sm transition-colors',
                                    year === plan.year
                                        ? 'border-transparent bg-primary font-semibold text-primary-foreground'
                                        : 'border-border bg-card text-muted-foreground hover:border-primary',
                                )}
                            >
                                {year}
                            </Link>
                        ))}
                    </div>
                </div>

                <PlanGrid plan={plan} />
            </div>
        </>
    );
}

Plan.layout = {
    breadcrumbs: [{ title: 'Planejamento', href: planRoute.index() }],
};
```

- [ ] **Step 3: Item na sidebar**

Em `resources/js/components/app-sidebar.tsx`, importar `CalendarRange` de `lucide-react` e `plan` de `@/routes`, e acrescentar a `mainNavItems` depois de "Meus Cartões":

```tsx
{
    title: 'Planejamento',
    href: plan.index(),
    icon: CalendarRange,
},
```

- [ ] **Step 4: Verificar**

```bash
npm run build
npm run types:check
npm run lint:check
```
Esperado: sem erros. Se `@/routes` não exportar `plan`, rode `npm run build` de novo — o Wayfinder gera os helpers no build.

- [ ] **Step 5: Formatar e commitar**

```bash
npm run format
git add resources/js
git commit -m "feat: grade do planejamento anual"
```

---

### Task 7: Células editáveis

**Files:**
- Create: `resources/js/components/plan-cell.tsx`
- Modify: `resources/js/components/plan-grid.tsx`

**Interfaces:**
- Consumes: `PlanCardMonth`, `PlanLineMonth` (Task 6); rotas `plan.lines.amount` e `plan.cards.amount` (Task 5).
- Produces: `<PlanCell url={string} valueCents={number} isExplicit={boolean} hint={string | null} />`.

- [ ] **Step 1: Escrever a célula**

`resources/js/components/plan-cell.tsx`:

```tsx
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { formatBRL, parseBRLToCents } from '@/lib/currency';
import { cn } from '@/lib/utils';

type PlanCellProps = {
    url: string;
    valueCents: number;
    isExplicit: boolean;
    hint: string | null;
};

export function PlanCell({
    url,
    valueCents,
    isExplicit,
    hint,
}: PlanCellProps) {
    const [draft, setDraft] = useState(() => formatBRL(valueCents));

    function commit() {
        const trimmed = draft.trim();
        const next = trimmed === '' ? null : parseBRLToCents(trimmed);

        if (next === valueCents && isExplicit) {
            return;
        }

        if (next === null && !isExplicit) {
            setDraft(formatBRL(valueCents));

            return;
        }

        router.put(
            url,
            { amount_cents: next },
            {
                preserveScroll: true,
                preserveState: false,
                onError: () => setDraft(formatBRL(valueCents)),
            },
        );
    }

    return (
        <td className="p-0">
            <input
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                onBlur={commit}
                inputMode="numeric"
                aria-label={hint ?? undefined}
                title={hint ?? undefined}
                className={cn(
                    'w-full bg-transparent px-3 py-2 text-right text-sm tabular-nums outline-none focus:bg-accent',
                    isExplicit ? 'text-foreground' : 'text-muted-foreground',
                )}
            />
        </td>
    );
}
```

Nota de comportamento: `preserveState: false` faz o Inertia recarregar as props depois de salvar, então os totais do rodapé se atualizam sozinhos. `draft` é reinicializado porque o componente remonta com a nova prop.

- [ ] **Step 2: Trocar as células da grade**

Em `resources/js/components/plan-grid.tsx`, substituir cada `<td className={BODY_CELL}>{formatBRL(...)}</td>` de linha e de cartão por `<PlanCell>`.

Para linha (renda e despesa):

```tsx
<PlanCell
    key={month}
    url={amountRoutes.line(line.id, month)}
    valueCents={line.months[month].effective_cents}
    isExplicit={line.months[month].amount_cents !== null}
    hint={
        line.months[month].amount_cents === null
            ? `Estimado: ${formatBRL(line.default_amount_cents)}`
            : null
    }
/>
```

Para cartão:

```tsx
<PlanCell
    key={month}
    url={amountRoutes.card(card.id, month)}
    valueCents={card.months[month].effective_cents}
    isExplicit={card.months[month].override_cents !== null}
    hint={
        card.months[month].override_cents === null
            ? null
            : `Parcelamentos: ${formatBRL(card.months[month].computed_cents)}`
    }
/>
```

As URLs vêm do Wayfinder. No topo de `plan-grid.tsx`:

```tsx
import planRoute from '@/routes/plan';

const amountRoutes = {
    line: (budgetLine: number, month: string) =>
        planRoute.lines.amount({ budgetLine, month }).url,
    card: (card: number, month: string) =>
        planRoute.cards.amount({ card, month }).url,
};
```

**O import é `planRoute`, não `plan`.** O componente `PlanGrid` já recebe uma prop chamada `plan`; importar o helper com o mesmo nome sombreia a prop e quebra a grade inteira.

A forma de objeto (`{ budgetLine, month }`) é a que o Wayfinder gera para rota de dois parâmetros — confirmado em `resources/js/routes/purchases/index.ts`, que aceita objeto, array ou escalar. Use o objeto: nomeia os dois parâmetros e não depende da ordem.

- [ ] **Step 3: Mostrar o calculado sob a correção do cartão**

Abaixo do `<input>` na célula de cartão, quando houver correção, o valor calculado precisa ficar visível — é a decisão 4 da spec. Em `plan-cell.tsx`, acrescentar depois do `<input>`:

```tsx
{hint && (
    <span className="block px-3 pb-1 text-right text-[11px] text-muted-foreground tabular-nums">
        {hint}
    </span>
)}
```

E remover `title={hint ?? undefined}` para não duplicar a informação em tooltip.

- [ ] **Step 4: Verificar**

```bash
npm run build && npm run types:check && npm run lint:check
composer run ci:check
```
Esperado: sem erros; a suíte PHP continua verde.

- [ ] **Step 5: Testar à mão**

Subir o app e conferir, em `/planejamento`:
1. Digitar um valor numa célula de conta fixa e sair do campo — o valor persiste após recarregar.
2. Apagar a célula e sair — volta ao padrão, em cinza.
3. Corrigir um mês de cartão — o valor dos parcelamentos aparece embaixo em letra menor.
4. O rodapé SALDO muda sozinho depois de cada edição.

- [ ] **Step 6: Formatar e commitar**

```bash
npm run format
git add resources/js
git commit -m "feat: células editáveis do planejamento"
```

---

### Task 8: Gerenciar linhas na tela

**Files:**
- Create: `resources/js/components/budget-line-form.tsx`
- Create: `resources/js/components/budget-line-cell.tsx`
- Modify: `resources/js/components/plan-grid.tsx`

**Interfaces:**
- Consumes: rotas `plan.lines.store`, `plan.lines.update`, `plan.lines.destroy` (Task 5); tipo `PlanLineRow` (Task 4).
- Produces: `<BudgetLineForm kind="income" | "expense" />` — a linha "nova" ao fim de cada bloco; `<BudgetLineCell line={PlanLineRow} />` — a célula fixa com nome editável, setas de ordem e arquivar.

- [ ] **Step 1: Escrever o formulário de nova linha**

`resources/js/components/budget-line-form.tsx`:

```tsx
import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { parseBRLToCents } from '@/lib/currency';
import plan from '@/routes/plan';

export function BudgetLineForm({ kind }: { kind: 'income' | 'expense' }) {
    const form = useForm({
        kind,
        name: '',
        default_amount_cents: 0,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post(plan.lines.store().url, {
            preserveScroll: true,
            onSuccess: () => form.reset('name', 'default_amount_cents'),
        });
    }

    return (
        <form onSubmit={submit} className="flex items-center gap-2 p-2">
            <Input
                value={form.data.name}
                onChange={(event) => form.setData('name', event.target.value)}
                placeholder={kind === 'income' ? 'Nova entrada' : 'Nova conta'}
                className="h-8 w-48"
            />
            <Input
                defaultValue=""
                onChange={(event) =>
                    form.setData(
                        'default_amount_cents',
                        parseBRLToCents(event.target.value),
                    )
                }
                placeholder="Valor estimado"
                inputMode="numeric"
                className="h-8 w-36"
            />
            <Button type="submit" size="sm" disabled={form.processing}>
                <Plus /> Adicionar
            </Button>
        </form>
    );
}
```

- [ ] **Step 2: Célula fixa com renomear, reordenar e arquivar**

A spec pede que as linhas sejam renomeadas, reordenadas e arquivadas na própria tabela. As três operações usam o mesmo `PATCH`/`DELETE`, então moram no mesmo componente.

`resources/js/components/budget-line-cell.tsx`:

```tsx
import { router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Trash2 } from 'lucide-react';
import { useState } from 'react';
import planRoute from '@/routes/plan';
import type { PlanLineRow } from '@/types';

export function BudgetLineCell({ line }: { line: PlanLineRow }) {
    const [name, setName] = useState(line.name);

    function patch(changes: Partial<Pick<PlanLineRow, 'name' | 'position'>>) {
        router.patch(
            planRoute.lines.update(line.id).url,
            {
                name: line.name,
                default_amount_cents: line.default_amount_cents,
                position: line.position,
                ...changes,
            },
            { preserveScroll: true, onError: () => setName(line.name) },
        );
    }

    function archive() {
        if (
            !window.confirm(
                `Arquivar ${line.name}? Os valores já lançados são preservados.`,
            )
        ) {
            return;
        }

        router.delete(planRoute.lines.destroy(line.id).url, {
            preserveScroll: true,
        });
    }

    return (
        <div className="flex items-center gap-1">
            <input
                value={name}
                onChange={(event) => setName(event.target.value)}
                onBlur={() => name !== line.name && patch({ name })}
                aria-label={`Nome da linha ${line.name}`}
                className="w-40 bg-transparent text-sm font-medium outline-none focus:bg-accent"
            />
            <button
                type="button"
                onClick={() => patch({ position: line.position - 1 })}
                className="text-muted-foreground hover:text-foreground"
                aria-label={`Subir ${line.name}`}
            >
                <ChevronUp className="size-4" />
            </button>
            <button
                type="button"
                onClick={() => patch({ position: line.position + 1 })}
                className="text-muted-foreground hover:text-foreground"
                aria-label={`Descer ${line.name}`}
            >
                <ChevronDown className="size-4" />
            </button>
            <button
                type="button"
                onClick={archive}
                className="text-muted-foreground hover:text-destructive"
                aria-label={`Arquivar ${line.name}`}
            >
                <Trash2 className="size-4" />
            </button>
        </div>
    );
}
```

Em `plan-grid.tsx`, trocar `<td className={STICKY}>{line.name}</td>` (nas duas listas, renda e despesa) por:

```tsx
<td className={STICKY}>
    <BudgetLineCell line={line} />
</td>
```

As linhas de cartão **não** recebem este componente: cartão não é criado nem arquivado aqui.

Limite conhecido da reordenação: as setas somam ou subtraem 1 na posição, sem trocar com a linha vizinha. Com posições consecutivas isso empata duas linhas na mesma posição, e o desempate cai no `id` (a ordenação de `BuildAnnualPlan` é `position` e depois `id`). O resultado é previsível mas nem sempre é uma troca limpa. Ordenação por arrasto, que resolveria de vez, exige biblioteca nova e está fora deste plano.

- [ ] **Step 3: Encaixar os formulários**

Depois do último `<tr>` de renda e depois do último de despesa, uma linha de tabela com o formulário ocupando a largura toda:

```tsx
<tr>
    <td colSpan={plan.months.length + 1} className="bg-muted/30">
        <BudgetLineForm kind="income" />
    </td>
</tr>
```

E o equivalente com `kind="expense"` no fim do bloco de despesas.

- [ ] **Step 4: Verificar**

```bash
npm run build && npm run types:check && npm run lint:check
composer run ci:check
```

- [ ] **Step 5: Testar à mão**

1. Criar uma conta fixa nova — ela aparece com o valor estimado repetido nos 12 meses.
2. Criar uma entrada — ela aparece no bloco de cima e entra no total ENTRADA.
3. Renomear uma linha e sair do campo — o nome persiste após recarregar.
4. Usar as setas de ordem — a linha muda de lugar.
5. Arquivar uma linha — ela some da grade e os totais mudam.

- [ ] **Step 6: Commit final**

```bash
npm run format
vendor/bin/pint --dirty --format agent
composer run ci:check
git add resources/js
git commit -m "feat: criar e arquivar linhas do planejamento"
```

---

## Verificação final

Ao fim das 8 tarefas:

```bash
composer run ci:check
```

Esperado: Pint passa, PHPStan 0 erros, toda a suíte verde. A contagem de testes deve ter subido em pelo menos 43 (3 + 4 + 13 + 5 + 18) em relação aos 308 do início.
