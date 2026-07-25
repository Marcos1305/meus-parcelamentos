# Meus Parcelamentos — Núcleo: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir o núcleo do "Meus Parcelamentos": cadastro de cartões, lançamento manual de compras parceladas, dashboard com projeção de 6 meses, histórico filtrável e exportação CSV.

**Architecture:** Parcelas são materializadas — cada compra grava N linhas em `installments` no momento da criação, com valor e vencimento já calculados. O cálculo vive em Actions puras, sem banco, testadas em `tests/Unit`. O status da parcela nunca é persistido: é função pura de `due_date` contra hoje. Controllers são finos e delegam para Actions; agregações de leitura também são Actions, devolvendo arrays tipados que o Inertia entrega direto ao React.

**Tech Stack:** Laravel 13 (PHP 8.4), Inertia v3, React 19, TypeScript, Tailwind v4, shadcn/ui, Wayfinder, Pest 4, Larastan nível 7, SQLite. Uma dependência nova aprovada: `recharts`.

**Spec:** `docs/superpowers/specs/2026-07-24-parcelamentos-nucleo-design.md`

## Global Constraints

- **Ambiente:** Laravel Herd. Rodar `php artisan`, `composer`, `vendor/bin/pint` direto, sem prefixo. **Nunca `ddev`.**
- **Dinheiro é sempre `int` de centavos.** Nenhum `float` ou `decimal` no domínio. Formatação BRL só no React.
- **Datas de domínio usam `CarbonImmutable`.** Colunas de data usam cast `immutable_date`.
- **Status nunca é persistido.** Não existe coluna `paid_at`, `status` ou equivalente.
- **Toda Action de cálculo é pura**, sem acesso a banco, testada em `tests/Unit` (que não recebe `RefreshDatabase`).
- **Todo teste que toca status congela o relógio** com `travelTo()`. Sem isso a suíte quebra na virada do mês.
- **PHPStan nível 7 tem que passar.** Todo array devolvido por Action precisa de PHPDoc com array shape.
- **Pint depois de tocar PHP:** `vendor/bin/pint --dirty --format agent`.
- **Criar arquivos com `php artisan make:`**, sempre com `--no-interaction`.
- **Nenhuma pasta base nova em `app/`.** Usar `app/Actions`, `app/Enums`, `app/Models`, `app/Http`, `app/Policies`, `app/Console/Commands`.
- **Enums usam TitleCase nas chaves:** `case Paid`, não `case PAID`.
- **Chaves de banco em inglês, URIs em português.** Exceção: a rota `dashboard` mantém URI e nome em inglês.
- **Wayfinder gera `resources/js/actions/` e `resources/js/routes/`** — são gitignored e regenerados pelo Vite. Rodar `npm run dev` ou `npm run build` depois de mexer em rotas, antes de importar no React.
- **Commits frequentes**, um por task no mínimo. Terminar cada mensagem de commit com `Claude-Session: https://claude.ai/code/session_0156ogwsuLM3WTFASr1rELTR`.

---

## File Structure

**Criados em `app/`:**

| Arquivo | Responsabilidade |
|---|---|
| `Enums/CardBrand.php` | Bandeiras aceitas e seus rótulos |
| `Enums/InstallmentStatus.php` | Os três status e a regra que os deriva de uma data |
| `Actions/SplitIntoInstallments.php` | Rateio de centavos. Puro. |
| `Actions/CalculateFirstDueDate.php` | Regra de fechamento/vencimento. Puro. |
| `Actions/BuildInstallmentSchedule.php` | Combina os dois acima num cronograma. Puro. |
| `Actions/GenerateInstallments.php` | Persiste o cronograma de uma compra. Idempotente. |
| `Actions/BuildDashboardSummary.php` | Números do topo do Dashboard |
| `Actions/BuildMonthlyProjection.php` | Total por mês, N meses à frente |
| `Actions/BuildCardsOverview.php` | Visão por cartão da tela Meus Cartões |
| `Actions/BuildFinanceTip.php` | A regra única de dica de finanças |
| `Models/Card.php`, `Models/Purchase.php`, `Models/Installment.php` | Eloquent + relações + scopes |
| `Policies/CardPolicy.php`, `Policies/PurchasePolicy.php` | Propriedade por `user_id` |
| `Http/Controllers/DashboardController.php` | |
| `Http/Controllers/CardController.php` | CRUD completo |
| `Http/Controllers/PurchaseController.php` | create, store, destroy |
| `Http/Controllers/HistoryController.php` | Listagem filtrada e paginada |
| `Http/Controllers/ExportHistoryController.php` | Invokable, streaming de CSV |
| `Http/Requests/StoreCardRequest.php`, `UpdateCardRequest.php`, `StorePurchaseRequest.php` | |
| `Console/Commands/RebuildInstallments.php` | `installments:rebuild` |

**Criados em `resources/js/`:**

| Arquivo | Responsabilidade |
|---|---|
| `types/parcelamentos.ts` | Tipos dos props que o Inertia entrega |
| `lib/currency.ts` | `formatBRL`, `formatMonthLabel`, `parseBRLToCents` |
| `lib/installments.ts` | Espelho no cliente do cronograma, só para o preview |
| `components/ui/chart.tsx` | Wrapper shadcn sobre recharts |
| `components/status-chip.tsx` | Selo Pago / Próximo / Pendente |
| `components/projection-chart.tsx` | Gráfico de 6 meses |
| `components/empty-state.tsx` | CTA reutilizável de estado vazio |
| `pages/dashboard.tsx` | Reescrito |
| `pages/cards/index.tsx`, `cards/create.tsx`, `cards/edit.tsx` | |
| `pages/cards/card-form.tsx` | Formulário compartilhado por create e edit |
| `pages/purchases/create.tsx` | Novo lançamento com preview ao vivo |
| `pages/history/index.tsx` | |

**Modificados:** `routes/web.php`, `resources/js/components/app-sidebar.tsx`, `resources/js/types/index.ts`, `resources/css/app.css`, `config/app.php`, `.env` / `.env.example`, `database/seeders/DatabaseSeeder.php`, `package.json`.

**Criado:** `routes/parcelamentos.php`.

### Refinamento em relação à spec

A spec cita `GenerateInstallments` como a Action que "orquestra as duas e grava as N linhas". No plano isso vira **duas** classes: `BuildInstallmentSchedule` (pura, devolve o cronograma) e `GenerateInstallments` (persiste). O motivo é testabilidade — a regra inteira fica coberta por testes unitários sem banco, e a parte que toca o banco vira três linhas triviais. O comportamento externo é idêntico ao especificado.

---

## Task 1: Cálculo de parcelas (Actions puras)

O coração do domínio. Nada aqui toca banco. Se esta task estiver certa, todo o resto é encanamento.

**Files:**
- Modify: `config/app.php` (timezone), `.env`, `.env.example`
- Create: `app/Actions/SplitIntoInstallments.php`
- Create: `app/Actions/CalculateFirstDueDate.php`
- Create: `app/Actions/BuildInstallmentSchedule.php`
- Test: `tests/Unit/SplitIntoInstallmentsTest.php`
- Test: `tests/Unit/CalculateFirstDueDateTest.php`
- Test: `tests/Unit/BuildInstallmentScheduleTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `App\Actions\SplitIntoInstallments::handle(int $totalCents, int $count): array<int, int>`
  - `App\Actions\CalculateFirstDueDate::handle(CarbonImmutable $purchasedAt, int $closingDay, int $dueDay): CarbonImmutable`
  - `App\Actions\BuildInstallmentSchedule::handle(int $totalCents, int $count, CarbonImmutable $purchasedAt, int $closingDay, int $dueDay): array<int, array{number: int, amount_cents: int, due_date: CarbonImmutable}>`

- [ ] **Step 1: Ajustar timezone e locale**

Em `.env` e `.env.example`, trocar:

```
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=pt_BR
```

Em `config/app.php`, o valor de `'timezone'` passa a:

```php
'timezone' => 'America/Sao_Paulo',
```

Sem isso, `now()` roda em UTC e o status de uma parcela muda três horas antes da meia-noite de Brasília.

- [ ] **Step 2: Escrever o teste do rateio**

Criar `tests/Unit/SplitIntoInstallmentsTest.php`:

```php
<?php

use App\Actions\SplitIntoInstallments;

it('devolve o total intacto em uma parcela', function (): void {
    expect((new SplitIntoInstallments)->handle(10000, 1))->toBe([10000]);
});

it('coloca o resto na primeira parcela', function (): void {
    expect((new SplitIntoInstallments)->handle(100000, 3))->toBe([33334, 33333, 33333]);
});

it('divide exatamente quando nao ha resto', function (): void {
    expect((new SplitIntoInstallments)->handle(120000, 4))->toBe([30000, 30000, 30000, 30000]);
});

it('lida com valores degenerados sem perder centavos', function (): void {
    expect((new SplitIntoInstallments)->handle(1, 3))->toBe([1, 0, 0]);
});

it('sempre soma exatamente o total', function (int $total, int $count): void {
    $parcels = (new SplitIntoInstallments)->handle($total, $count);

    expect(array_sum($parcels))->toBe($total)
        ->and($parcels)->toHaveCount($count);
})->with([
    [100000, 3], [99999, 7], [1, 48], [7, 2], [123456789, 13], [50000, 1], [333, 48],
]);
```

O último teste é o que importa: a invariante "a soma bate" vale para qualquer entrada, não só para os casos que eu lembrei de escrever.

- [ ] **Step 3: Rodar e ver falhar**

Run: `php artisan test --compact --filter=SplitIntoInstallments`
Expected: FAIL com `Class "App\Actions\SplitIntoInstallments" not found`.

- [ ] **Step 4: Implementar o rateio**

Run: `php artisan make:class Actions/SplitIntoInstallments --no-interaction`

Conteúdo de `app/Actions/SplitIntoInstallments.php`:

```php
<?php

namespace App\Actions;

class SplitIntoInstallments
{
    /**
     * Rateia um total em centavos entre N parcelas, com o resto na primeira.
     *
     * @return array<int, int>
     */
    public function handle(int $totalCents, int $count): array
    {
        $base = intdiv($totalCents, $count);
        $remainder = $totalCents % $count;

        return array_map(
            fn (int $index): int => $index === 0 ? $base + $remainder : $base,
            range(0, $count - 1),
        );
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --compact --filter=SplitIntoInstallments`
Expected: PASS, 9 testes.

- [ ] **Step 6: Escrever o teste da data de vencimento**

Criar `tests/Unit/CalculateFirstDueDateTest.php`:

```php
<?php

use App\Actions\CalculateFirstDueDate;
use Carbon\CarbonImmutable;

it('calcula o primeiro vencimento', function (string $purchasedAt, int $closingDay, int $dueDay, string $expected): void {
    $result = (new CalculateFirstDueDate)->handle(
        CarbonImmutable::parse($purchasedAt),
        $closingDay,
        $dueDay,
    );

    expect($result->toDateString())->toBe($expected);
})->with([
    'antes do fechamento, vencimento depois do fechamento' => ['2026-03-02', 3, 10, '2026-03-10'],
    'depois do fechamento empurra um mes' => ['2026-03-05', 3, 10, '2026-04-10'],
    'vencimento antes do fechamento empurra um mes' => ['2026-03-02', 28, 5, '2026-04-05'],
    'dia 31 cortado em fevereiro' => ['2026-01-15', 10, 31, '2026-02-28'],
    'dia 31 cortado em fevereiro bissexto' => ['2024-01-15', 10, 31, '2024-02-29'],
    'virada de ano' => ['2026-12-20', 10, 15, '2027-01-15'],
    'compra no proprio dia do fechamento entra na fatura do mes' => ['2026-03-03', 3, 10, '2026-03-10'],
    'fechamento e vencimento no mesmo dia empurra um mes' => ['2026-03-01', 10, 10, '2026-04-10'],
]);
```

O penúltimo caso trava a regra do limite: `dia(P) <= C` é inclusivo. O último trava `D <= C` quando são iguais.

- [ ] **Step 7: Rodar e ver falhar**

Run: `php artisan test --compact --filter=CalculateFirstDueDate`
Expected: FAIL com `Class "App\Actions\CalculateFirstDueDate" not found`.

- [ ] **Step 8: Implementar a data de vencimento**

Run: `php artisan make:class Actions/CalculateFirstDueDate --no-interaction`

```php
<?php

namespace App\Actions;

use Carbon\CarbonImmutable;

class CalculateFirstDueDate
{
    /**
     * Determina o vencimento da primeira parcela de uma compra.
     *
     * A compra entra na fatura que fecha no mes da compra quando o dia da
     * compra nao passou do fechamento; caso contrario, na fatura seguinte.
     * O vencimento cai no mes do fechamento quando o dia de vencimento e
     * posterior ao de fechamento, e no mes seguinte quando nao e.
     */
    public function handle(CarbonImmutable $purchasedAt, int $closingDay, int $dueDay): CarbonImmutable
    {
        $closingMonth = $purchasedAt->startOfMonth();

        if ($purchasedAt->day > $closingDay) {
            $closingMonth = $closingMonth->addMonth();
        }

        $dueMonth = $dueDay > $closingDay
            ? $closingMonth
            : $closingMonth->addMonth();

        return $dueMonth->addDays(min($dueDay, $dueMonth->daysInMonth) - 1);
    }
}
```

`startOfMonth()` antes de `addMonth()` é o que torna a aritmética segura: somar um mês ao dia 1 nunca transborda, enquanto somar ao dia 31 transborda.

- [ ] **Step 9: Rodar e ver passar**

Run: `php artisan test --compact --filter=CalculateFirstDueDate`
Expected: PASS, 8 testes.

- [ ] **Step 10: Escrever o teste do cronograma**

Criar `tests/Unit/BuildInstallmentScheduleTest.php`:

```php
<?php

use App\Actions\BuildInstallmentSchedule;
use App\Actions\CalculateFirstDueDate;
use App\Actions\SplitIntoInstallments;
use Carbon\CarbonImmutable;

function schedule(): BuildInstallmentSchedule
{
    return new BuildInstallmentSchedule(
        new SplitIntoInstallments,
        new CalculateFirstDueDate,
    );
}

it('numera as parcelas a partir de um', function (): void {
    $result = schedule()->handle(30000, 3, CarbonImmutable::parse('2026-03-02'), 3, 10);

    expect(array_column($result, 'number'))->toBe([1, 2, 3]);
});

it('distribui os valores com o resto na primeira', function (): void {
    $result = schedule()->handle(100000, 3, CarbonImmutable::parse('2026-03-02'), 3, 10);

    expect(array_column($result, 'amount_cents'))->toBe([33334, 33333, 33333]);
});

it('avanca um mes por parcela', function (): void {
    $result = schedule()->handle(30000, 3, CarbonImmutable::parse('2026-03-02'), 3, 10);

    $dates = array_map(fn (array $row): string => $row['due_date']->toDateString(), $result);

    expect($dates)->toBe(['2026-03-10', '2026-04-10', '2026-05-10']);
});

it('nao propaga o corte de dia dos meses curtos', function (): void {
    $result = schedule()->handle(30000, 3, CarbonImmutable::parse('2025-12-15'), 10, 31);

    $dates = array_map(fn (array $row): string => $row['due_date']->toDateString(), $result);

    expect($dates)->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('atravessa a virada de ano', function (): void {
    $result = schedule()->handle(30000, 3, CarbonImmutable::parse('2026-11-02'), 3, 10);

    $dates = array_map(fn (array $row): string => $row['due_date']->toDateString(), $result);

    expect($dates)->toBe(['2026-11-10', '2026-12-10', '2027-01-10']);
});

it('gera uma unica linha para compra a vista', function (): void {
    $result = schedule()->handle(50000, 1, CarbonImmutable::parse('2026-03-02'), 3, 10);

    expect($result)->toHaveCount(1)
        ->and($result[0]['amount_cents'])->toBe(50000);
});
```

O teste "não propaga o corte" é o único que pega o bug clássico de calcular cada data a partir da anterior: fevereiro corta para 28, e março herdaria 28 em vez de 31.

- [ ] **Step 11: Rodar e ver falhar**

Run: `php artisan test --compact --filter=BuildInstallmentSchedule`
Expected: FAIL com `Class "App\Actions\BuildInstallmentSchedule" not found`.

- [ ] **Step 12: Implementar o cronograma**

Run: `php artisan make:class Actions/BuildInstallmentSchedule --no-interaction`

```php
<?php

namespace App\Actions;

use Carbon\CarbonImmutable;

class BuildInstallmentSchedule
{
    public function __construct(
        private SplitIntoInstallments $splitIntoInstallments,
        private CalculateFirstDueDate $calculateFirstDueDate,
    ) {}

    /**
     * Monta o cronograma completo de parcelas de uma compra.
     *
     * @return array<int, array{number: int, amount_cents: int, due_date: CarbonImmutable}>
     */
    public function handle(
        int $totalCents,
        int $count,
        CarbonImmutable $purchasedAt,
        int $closingDay,
        int $dueDay,
    ): array {
        $amounts = $this->splitIntoInstallments->handle($totalCents, $count);
        $firstDueDate = $this->calculateFirstDueDate->handle($purchasedAt, $closingDay, $dueDay);

        $schedule = [];

        foreach ($amounts as $index => $amountCents) {
            $month = $firstDueDate->startOfMonth()->addMonths($index);

            $schedule[] = [
                'number' => $index + 1,
                'amount_cents' => $amountCents,
                'due_date' => $month->addDays(min($dueDay, $month->daysInMonth) - 1),
            ];
        }

        return $schedule;
    }
}
```

Cada data é derivada do mês base mais o offset, e o dia vem sempre de `$dueDay` original — nunca da data anterior.

- [ ] **Step 13: Rodar e ver passar**

Run: `php artisan test --compact --filter=BuildInstallmentSchedule`
Expected: PASS, 6 testes.

- [ ] **Step 14: Formatar e checar tipos**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

Expected: Pint sem alterações pendentes, PHPStan `[OK] No errors`.

- [ ] **Step 15: Commit**

```bash
git add app/Actions tests/Unit config/app.php .env.example
git commit -m "$(cat <<'EOF'
feat: cálculo de parcelas e datas de vencimento

Actions puras para rateio de centavos e regra de fechamento/vencimento
do cartão. Timezone da aplicação para America/Sao_Paulo.

Claude-Session: https://claude.ai/code/session_0156ogwsuLM3WTFASr1rELTR
EOF
)"
```

---

## Task 2: Schema, enums, models e factories

**Files:**
- Create: `app/Enums/CardBrand.php`, `app/Enums/InstallmentStatus.php`
- Create: `database/migrations/*_create_cards_table.php`, `*_create_purchases_table.php`, `*_create_installments_table.php`
- Create: `app/Models/Card.php`, `app/Models/Purchase.php`, `app/Models/Installment.php`
- Create: `database/factories/CardFactory.php`, `PurchaseFactory.php`, `InstallmentFactory.php`
- Test: `tests/Unit/InstallmentStatusTest.php`, `tests/Feature/ModelRelationshipsTest.php`

**Interfaces:**
- Consumes: nada da Task 1 ainda.
- Produces:
  - `App\Enums\CardBrand` (string-backed: `visa`, `mastercard`, `elo`, `amex`, `hipercard`, `outro`), método `label(): string`
  - `App\Enums\InstallmentStatus` (string-backed: `paid`, `upcoming`, `pending`), estático `forDueDate(CarbonImmutable $dueDate, CarbonImmutable $today): self`
  - `App\Models\Card` — `$user_id, $name, $brand (CardBrand), $label, $last_four_digits, $credit_limit_cents, $closing_day, $due_day`; relações `user()`, `purchases()`, `installments()` (hasManyThrough)
  - `App\Models\Purchase` — `$card_id, $description, $merchant, $total_amount_cents, $installments_count, $purchased_at (CarbonImmutable)`; relações `card()`, `installments()`
  - `App\Models\Installment` — `$purchase_id, $number, $amount_cents, $due_date (CarbonImmutable)`; relação `purchase()`; accessor `status: InstallmentStatus`; scopes `forUser(User)`, `open()`, `paid()`
  - `CardFactory`, `PurchaseFactory`, `InstallmentFactory`

- [ ] **Step 1: Escrever o teste do enum de status**

Criar `tests/Unit/InstallmentStatusTest.php`:

```php
<?php

use App\Enums\InstallmentStatus;
use Carbon\CarbonImmutable;

it('classifica a parcela pela data de vencimento', function (string $dueDate, string $today, InstallmentStatus $expected): void {
    $status = InstallmentStatus::forDueDate(
        CarbonImmutable::parse($dueDate),
        CarbonImmutable::parse($today),
    );

    expect($status)->toBe($expected);
})->with([
    'mes passado' => ['2026-06-10', '2026-07-15', InstallmentStatus::Paid],
    'este mes, ja venceu' => ['2026-07-10', '2026-07-15', InstallmentStatus::Paid],
    'este mes, vence hoje' => ['2026-07-15', '2026-07-15', InstallmentStatus::Upcoming],
    'este mes, ainda vai vencer' => ['2026-07-28', '2026-07-15', InstallmentStatus::Upcoming],
    'mes que vem' => ['2026-08-10', '2026-07-15', InstallmentStatus::Pending],
    'ano que vem' => ['2027-01-10', '2026-07-15', InstallmentStatus::Pending],
    'mesmo mes de outro ano nao conta como atual' => ['2027-07-10', '2026-07-15', InstallmentStatus::Pending],
]);

it('considera em aberto tudo que nao esta pago', function (): void {
    expect(InstallmentStatus::Upcoming->isOpen())->toBeTrue()
        ->and(InstallmentStatus::Pending->isOpen())->toBeTrue()
        ->and(InstallmentStatus::Paid->isOpen())->toBeFalse();
});
```

O caso "mesmo mês de outro ano" existe porque `isSameMonth()` do Carbon compara mês **e** ano só quando o segundo argumento pede — é um erro fácil de cometer.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter=InstallmentStatus`
Expected: FAIL com `Class "App\Enums\InstallmentStatus" not found`.

- [ ] **Step 3: Criar os enums**

```bash
php artisan make:enum Enums/InstallmentStatus --string --no-interaction
php artisan make:enum Enums/CardBrand --string --no-interaction
```

`app/Enums/InstallmentStatus.php`:

```php
<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum InstallmentStatus: string
{
    case Paid = 'paid';
    case Upcoming = 'upcoming';
    case Pending = 'pending';

    /**
     * Deriva o status de uma parcela. Nunca persistido: e sempre funcao
     * da data de vencimento contra hoje.
     */
    public static function forDueDate(CarbonImmutable $dueDate, CarbonImmutable $today): self
    {
        if ($dueDate->startOfDay()->lessThan($today->startOfDay())) {
            return self::Paid;
        }

        return $dueDate->isSameMonth($today, true)
            ? self::Upcoming
            : self::Pending;
    }

    /**
     * Uma parcela esta "em aberto" quando ainda vai ser cobrada.
     */
    public function isOpen(): bool
    {
        return $this !== self::Paid;
    }

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Pago',
            self::Upcoming => 'Próximo',
            self::Pending => 'Pendente',
        };
    }
}
```

O segundo argumento `true` em `isSameMonth` é o que exige que o ano também bata.

`app/Enums/CardBrand.php`:

```php
<?php

namespace App\Enums;

enum CardBrand: string
{
    case Visa = 'visa';
    case Mastercard = 'mastercard';
    case Elo = 'elo';
    case Amex = 'amex';
    case Hipercard = 'hipercard';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Visa => 'Visa',
            self::Mastercard => 'Mastercard',
            self::Elo => 'Elo',
            self::Amex => 'American Express',
            self::Hipercard => 'Hipercard',
            self::Outro => 'Outra',
        };
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --compact --filter=InstallmentStatus`
Expected: PASS, 8 testes.

- [ ] **Step 5: Criar as migrations**

```bash
php artisan make:migration create_cards_table --no-interaction
php artisan make:migration create_purchases_table --no-interaction
php artisan make:migration create_installments_table --no-interaction
```

Corpo do `up()` de `create_cards_table`:

```php
Schema::create('cards', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('brand');
    $table->string('label')->nullable();
    $table->string('last_four_digits', 4);
    $table->unsignedInteger('credit_limit_cents');
    $table->unsignedTinyInteger('closing_day');
    $table->unsignedTinyInteger('due_day');
    $table->timestamps();

    $table->index('user_id');
});
```

`create_purchases_table`:

```php
Schema::create('purchases', function (Blueprint $table) {
    $table->id();
    $table->foreignId('card_id')->constrained()->cascadeOnDelete();
    $table->string('description');
    $table->string('merchant')->nullable();
    $table->unsignedInteger('total_amount_cents');
    $table->unsignedTinyInteger('installments_count');
    $table->date('purchased_at');
    $table->timestamps();

    $table->index('card_id');
});
```

`create_installments_table`:

```php
Schema::create('installments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('number');
    $table->unsignedInteger('amount_cents');
    $table->date('due_date');
    $table->timestamps();

    $table->index(['due_date', 'purchase_id']);
    $table->unique(['purchase_id', 'number']);
});
```

Cada `down()` é o `Schema::dropIfExists()` da respectiva tabela, na forma que o stub já gera.

- [ ] **Step 6: Rodar as migrations**

Run: `php artisan migrate`
Expected: as três migrations aplicadas sem erro.

- [ ] **Step 7: Criar os models com factory**

```bash
php artisan make:model Card --factory --no-interaction
php artisan make:model Purchase --factory --no-interaction
php artisan make:model Installment --factory --no-interaction
```

`app/Models/Card.php`:

```php
<?php

namespace App\Models;

use App\Enums\CardBrand;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Purchase> $purchases
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Installment> $installments
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
```

`user_id` fica fora do `#[Fillable]` de propósito — é sempre atribuído pelo controller a partir do usuário autenticado, nunca vindo do request.

`app/Models/Purchase.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property int $total_amount_cents
 * @property int $installments_count
 * @property CarbonImmutable $purchased_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Card $card
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Installment> $installments
 */
#[Fillable(['card_id', 'description', 'merchant', 'total_amount_cents', 'installments_count', 'purchased_at'])]
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchased_at' => 'immutable_date',
            'total_amount_cents' => 'integer',
            'installments_count' => 'integer',
        ];
    }
}
```

`app/Models/Installment.php`:

```php
<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\InstallmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $purchase_id
 * @property int $number
 * @property int $amount_cents
 * @property CarbonImmutable $due_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read InstallmentStatus $status
 * @property-read Purchase $purchase
 */
#[Fillable(['number', 'amount_cents', 'due_date'])]
class Installment extends Model
{
    /** @use HasFactory<InstallmentFactory> */
    use HasFactory;

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return Attribute<InstallmentStatus, never> */
    protected function status(): Attribute
    {
        return Attribute::get(fn (): InstallmentStatus => InstallmentStatus::forDueDate(
            $this->due_date,
            CarbonImmutable::now(),
        ));
    }

    /** @param Builder<Installment> $query */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->whereHas(
            'purchase.card',
            fn (Builder $cards) => $cards->where('user_id', $user->id),
        );
    }

    /** @param Builder<Installment> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereDate('due_date', '>=', CarbonImmutable::now()->toDateString());
    }

    /** @param Builder<Installment> $query */
    public function scopePaid(Builder $query): void
    {
        $query->whereDate('due_date', '<', CarbonImmutable::now()->toDateString());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'immutable_date',
            'number' => 'integer',
            'amount_cents' => 'integer',
        ];
    }
}
```

Os scopes `open()` e `paid()` usam o mesmo limite do enum — vencimento no passado é pago, o resto está em aberto. Se um dia a regra mudar, muda nos dois lugares junto; o teste do Step 10 quebra se divergirem.

- [ ] **Step 8: Escrever as factories**

`database/factories/CardFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\CardBrand;
use App\Models\Card;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Nubank', 'Inter', 'XP Investimentos', 'Itaú', 'C6 Bank']),
            'brand' => fake()->randomElement(CardBrand::cases()),
            'label' => fake()->randomElement(['Platinum', 'Black', 'Gold', null]),
            'last_four_digits' => (string) fake()->numberBetween(1000, 9999),
            'credit_limit_cents' => fake()->numberBetween(100_000, 5_000_000),
            'closing_day' => fake()->numberBetween(1, 28),
            'due_day' => fake()->numberBetween(1, 28),
        ];
    }
}
```

`database/factories/PurchaseFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Actions\BuildInstallmentSchedule;
use App\Models\Card;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'description' => fake()->randomElement(['MacBook Pro M2', 'iPhone 15 Pro Max', 'Tênis Nike Air Max', 'Cadeira Herman Miller']),
            'merchant' => fake()->company(),
            'total_amount_cents' => fake()->numberBetween(10_000, 1_500_000),
            'installments_count' => fake()->numberBetween(1, 12),
            'purchased_at' => fake()->dateTimeBetween('-18 months', 'now'),
        ];
    }

    /**
     * Cria a compra com o numero de parcelas informado e gera as parcelas
     * pela Action real, nunca montando as linhas a mao.
     */
    public function parcelada(int $count): static
    {
        return $this->state(['installments_count' => $count])
            ->afterCreating(function (Purchase $purchase): void {
                $card = $purchase->card;

                $schedule = app(BuildInstallmentSchedule::class)->handle(
                    $purchase->total_amount_cents,
                    $purchase->installments_count,
                    $purchase->purchased_at,
                    $card->closing_day,
                    $card->due_day,
                );

                $purchase->installments()->createMany($schedule);
            });
    }
}
```

Uma fixture que replicasse a lógica de rateio faria o teste validar a si mesmo. Chamando a Action de verdade, se a regra quebrar, o teste quebra junto.

`database/factories/InstallmentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Installment;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Installment>
 */
class InstallmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_id' => Purchase::factory(),
            'number' => 1,
            'amount_cents' => fake()->numberBetween(1_000, 200_000),
            'due_date' => fake()->dateTimeBetween('-6 months', '+6 months'),
        ];
    }
}
```

- [ ] **Step 9: Escrever o teste de relações e cascata**

Criar `tests/Feature/ModelRelationshipsTest.php`:

```php
<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

it('apaga compras e parcelas ao apagar o cartao', function (): void {
    $card = Card::factory()->create();
    Purchase::factory()->parcelada(6)->for($card)->create();

    expect(Installment::count())->toBe(6);

    $card->delete();

    expect(Purchase::count())->toBe(0)
        ->and(Installment::count())->toBe(0);
});

it('apaga parcelas ao apagar a compra', function (): void {
    $purchase = Purchase::factory()->parcelada(4)->create();

    $purchase->delete();

    expect(Installment::count())->toBe(0);
});

it('alcanca as parcelas do cartao por hasManyThrough', function (): void {
    $card = Card::factory()->create();
    Purchase::factory()->parcelada(3)->for($card)->create();
    Purchase::factory()->parcelada(2)->for($card)->create();

    expect($card->installments()->count())->toBe(5);
});

it('filtra parcelas pelo dono', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    Purchase::factory()->parcelada(3)->for(Card::factory()->for($mine))->create();
    Purchase::factory()->parcelada(5)->for(Card::factory()->for($theirs))->create();

    expect(Installment::query()->forUser($mine)->count())->toBe(3);
});

it('impede duas parcelas com o mesmo numero na mesma compra', function (): void {
    $purchase = Purchase::factory()->parcelada(2)->create();

    Installment::factory()->for($purchase)->create(['number' => 1]);
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);

it('separa parcelas em aberto das pagas', function (): void {
    $purchase = Purchase::factory()->create();

    Installment::factory()->for($purchase)->create(['number' => 1, 'due_date' => now()->subMonth()]);
    Installment::factory()->for($purchase)->create(['number' => 2, 'due_date' => now()->addMonth()]);

    expect(Installment::query()->paid()->count())->toBe(1)
        ->and(Installment::query()->open()->count())->toBe(1);
});
```

- [ ] **Step 10: Rodar e ver passar**

Run: `php artisan test --compact --filter=ModelRelationships`
Expected: PASS, 6 testes.

- [ ] **Step 11: Formatar e checar tipos**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

Expected: PHPStan `[OK] No errors`. Se reclamar de `HasManyThrough` genérico, conferir a ordem dos parâmetros do PHPDoc: `<Related, Through, $this>`.

- [ ] **Step 12: Commit**

```bash
git add app/Enums app/Models database/migrations database/factories tests
git commit -m "$(cat <<'EOF'
feat: schema de cartões, compras e parcelas

Enums de bandeira e status, models com relações e scopes, factories
com o state parcelada() que gera parcelas pela Action real.

Claude-Session: https://claude.ai/code/session_0156ogwsuLM3WTFASr1rELTR
EOF
)"
```

---

## Task 3: Geração e rebuild de parcelas

**Files:**
- Create: `app/Actions/GenerateInstallments.php`
- Create: `app/Console/Commands/RebuildInstallments.php`
- Test: `tests/Feature/GenerateInstallmentsTest.php`, `tests/Feature/RebuildInstallmentsCommandTest.php`

**Interfaces:**
- Consumes: `App\Actions\BuildInstallmentSchedule::handle(int, int, CarbonImmutable, int, int): array<int, array{number: int, amount_cents: int, due_date: CarbonImmutable}>` (Task 1); models `Card`, `Purchase`, `Installment` (Task 2).
- Produces:
  - `App\Actions\GenerateInstallments::handle(Purchase $purchase): void` — apaga as parcelas existentes da compra e grava o cronograma atual. Idempotente.
  - Comando `installments:rebuild [--user=ID]`.

- [ ] **Step 1: Escrever o teste da geração**

Criar `tests/Feature/GenerateInstallmentsTest.php`:

```php
<?php

use App\Actions\GenerateInstallments;
use App\Models\Card;
use App\Models\Purchase;

it('grava uma parcela por prestacao com valores e datas corretos', function (): void {
    $card = Card::factory()->create(['closing_day' => 3, 'due_day' => 10]);

    $purchase = Purchase::factory()->for($card)->create([
        'total_amount_cents' => 100_000,
        'installments_count' => 3,
        'purchased_at' => '2026-03-02',
    ]);

    app(GenerateInstallments::class)->handle($purchase);

    $installments = $purchase->installments()->get();

    expect($installments)->toHaveCount(3)
        ->and($installments->pluck('amount_cents')->all())->toBe([33334, 33333, 33333])
        ->and($installments->pluck('due_date')->map->toDateString()->all())
        ->toBe(['2026-03-10', '2026-04-10', '2026-05-10']);
});

it('mantem a soma das parcelas igual ao total da compra', function (): void {
    $purchase = Purchase::factory()->create([
        'total_amount_cents' => 99_999,
        'installments_count' => 7,
    ]);

    app(GenerateInstallments::class)->handle($purchase);

    expect((int) $purchase->installments()->sum('amount_cents'))->toBe(99_999);
});

it('e idempotente', function (): void {
    $purchase = Purchase::factory()->create(['installments_count' => 5]);

    app(GenerateInstallments::class)->handle($purchase);
    $first = $purchase->installments()->get()->map->only(['number', 'amount_cents', 'due_date'])->toArray();

    app(GenerateInstallments::class)->handle($purchase);
    $second = $purchase->installments()->get()->map->only(['number', 'amount_cents', 'due_date'])->toArray();

    expect($purchase->installments()->count())->toBe(5)
        ->and($second)->toBe($first);
});

it('reflete a mudanca de vencimento do cartao ao regerar', function (): void {
    $card = Card::factory()->create(['closing_day' => 3, 'due_day' => 10]);
    $purchase = Purchase::factory()->for($card)->create([
        'installments_count' => 2,
        'purchased_at' => '2026-03-02',
    ]);

    app(GenerateInstallments::class)->handle($purchase);

    $card->update(['due_day' => 20]);
    app(GenerateInstallments::class)->handle($purchase->fresh());

    expect($purchase->installments()->get()->pluck('due_date')->map->toDateString()->all())
        ->toBe(['2026-03-20', '2026-04-20']);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter=GenerateInstallments`
Expected: FAIL com `Target class [App\Actions\GenerateInstallments] does not exist`.

- [ ] **Step 3: Implementar a geração**

Run: `php artisan make:class Actions/GenerateInstallments --no-interaction`

```php
<?php

namespace App\Actions;

use App\Models\Purchase;
use Illuminate\Support\Facades\DB;

class GenerateInstallments
{
    public function __construct(
        private BuildInstallmentSchedule $buildInstallmentSchedule,
    ) {}

    /**
     * Regrava as parcelas de uma compra a partir do cronograma atual.
     */
    public function handle(Purchase $purchase): void
    {
        $card = $purchase->card;

        $schedule = $this->buildInstallmentSchedule->handle(
            $purchase->total_amount_cents,
            $purchase->installments_count,
            $purchase->purchased_at,
            $card->closing_day,
            $card->due_day,
        );

        DB::transaction(function () use ($purchase, $schedule): void {
            $purchase->installments()->delete();
            $purchase->installments()->createMany($schedule);
        });
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --compact --filter=GenerateInstallments`
Expected: PASS, 4 testes.

- [ ] **Step 5: Escrever o teste do comando**

Criar `tests/Feature/RebuildInstallmentsCommandTest.php`:

```php
<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

it('regenera parcelas identicas quando nada mudou', function (): void {
    $purchase = Purchase::factory()->parcelada(6)->create();
    $before = $purchase->installments()->get()->map->only(['number', 'amount_cents', 'due_date'])->toArray();

    $this->artisan('installments:rebuild')->assertSuccessful();

    expect($purchase->installments()->get()->map->only(['number', 'amount_cents', 'due_date'])->toArray())
        ->toBe($before);
});

it('atualiza as datas depois de mudar o vencimento do cartao', function (): void {
    $card = Card::factory()->create(['closing_day' => 3, 'due_day' => 10]);
    $purchase = Purchase::factory()->parcelada(2)->for($card)->create(['purchased_at' => '2026-03-02']);

    $card->update(['due_day' => 25]);

    $this->artisan('installments:rebuild')->assertSuccessful();

    expect($purchase->installments()->get()->pluck('due_date')->map->toDateString()->all())
        ->toBe(['2026-03-25', '2026-04-25']);
});

it('limita o rebuild a um usuario quando pedido', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    $myCard = Card::factory()->for($mine)->create(['closing_day' => 3, 'due_day' => 10]);
    $theirCard = Card::factory()->for($theirs)->create(['closing_day' => 3, 'due_day' => 10]);

    $myPurchase = Purchase::factory()->parcelada(1)->for($myCard)->create(['purchased_at' => '2026-03-02']);
    $theirPurchase = Purchase::factory()->parcelada(1)->for($theirCard)->create(['purchased_at' => '2026-03-02']);

    $myCard->update(['due_day' => 25]);
    $theirCard->update(['due_day' => 25]);

    $this->artisan('installments:rebuild', ['--user' => $mine->id])->assertSuccessful();

    expect($myPurchase->installments()->first()->due_date->toDateString())->toBe('2026-03-25')
        ->and($theirPurchase->installments()->first()->due_date->toDateString())->toBe('2026-03-10');
});

it('informa quantas compras foram processadas', function (): void {
    Purchase::factory()->parcelada(3)->count(2)->create();

    $this->artisan('installments:rebuild')
        ->expectsOutputToContain('2 compras')
        ->assertSuccessful();
});

it('nao deixa parcelas orfas', function (): void {
    Purchase::factory()->parcelada(4)->create();

    $this->artisan('installments:rebuild')->assertSuccessful();

    expect(Installment::count())->toBe(4);
});
```

- [ ] **Step 6: Rodar e ver falhar**

Run: `php artisan test --compact --filter=RebuildInstallmentsCommand`
Expected: FAIL com `The command "installments:rebuild" does not exist`.

- [ ] **Step 7: Implementar o comando**

Run: `php artisan make:command RebuildInstallments --no-interaction`

```php
<?php

namespace App\Console\Commands;

use App\Actions\GenerateInstallments;
use App\Models\Purchase;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RebuildInstallments extends Command
{
    protected $signature = 'installments:rebuild {--user= : Limita o rebuild às compras deste usuário}';

    protected $description = 'Regera todas as parcelas a partir das compras e da configuração atual dos cartões';

    public function handle(GenerateInstallments $generateInstallments): int
    {
        $userId = $this->option('user');

        $query = Purchase::query()->with('card');

        if ($userId !== null) {
            $query->whereHas('card', fn (Builder $cards) => $cards->where('user_id', $userId));
        }

        $processed = 0;

        $query->chunkById(100, function ($purchases) use ($generateInstallments, &$processed): void {
            foreach ($purchases as $purchase) {
                $generateInstallments->handle($purchase);
                $processed++;
            }
        });

        $this->info("Parcelas regeradas para {$processed} compras.");

        return self::SUCCESS;
    }
}
```

`with('card')` evita uma query por compra dentro do laço — com centenas de compras a diferença é a suíte rodando em segundos em vez de minutos.

- [ ] **Step 8: Rodar e ver passar**

Run: `php artisan test --compact --filter=RebuildInstallmentsCommand`
Expected: PASS, 5 testes.

- [ ] **Step 9: Rodar a suíte inteira**

Run: `php artisan test --compact`
Expected: tudo verde, incluindo os testes de auth que já existiam.

- [ ] **Step 10: Formatar, checar tipos e commitar**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
git add app/Actions app/Console tests/Feature
git commit -m "$(cat <<'EOF'
feat: geração e rebuild de parcelas

GenerateInstallments persiste o cronograma de forma idempotente,
dentro de transação. Comando installments:rebuild regenera tudo a
partir da configuração atual dos cartões.

Claude-Session: https://claude.ai/code/session_0156ogwsuLM3WTFASr1rELTR
EOF
)"
```

---

## Task 4: Cartões — CRUD, autorização e telas

A tela de índice aqui lista os cartões sem métricas. As métricas (comprometido, disponível, parcelamento em destaque) chegam na Task 7, quando já existirem compras no sistema para alimentá-las. Isso é ordem de construção, não omissão.

**Files:**
- Create: `app/Policies/CardPolicy.php`
- Create: `app/Http/Requests/StoreCardRequest.php`, `app/Http/Requests/UpdateCardRequest.php`
- Create: `app/Http/Controllers/CardController.php`
- Create: `routes/parcelamentos.php`
- Modify: `routes/web.php`
- Create: `resources/js/lib/currency.ts`, `resources/js/types/parcelamentos.ts`
- Modify: `resources/js/types/index.ts`
- Create: `resources/js/components/empty-state.tsx`
- Create: `resources/js/pages/cards/card-form.tsx`, `cards/index.tsx`, `cards/create.tsx`, `cards/edit.tsx`
- Modify: `resources/js/components/app-sidebar.tsx`
- Test: `tests/Feature/CardTest.php`

**Interfaces:**
- Consumes: `App\Models\Card`, `App\Enums\CardBrand` (Task 2).
- Produces:
  - Rotas nomeadas `cards.index`, `cards.create`, `cards.store`, `cards.edit`, `cards.update`, `cards.destroy`
  - `resources/js/lib/currency.ts` → `formatBRL(cents: number): string`, `parseBRLToCents(input: string): number`, `formatMonthLabel(iso: string): string`, `formatDate(iso: string): string`
  - `resources/js/types/parcelamentos.ts` → `CardSummary`, `CardBrandOption`
  - Componente `EmptyState` com props `{ title: string; description: string; action?: ReactNode }`

- [ ] **Step 1: Escrever o teste de cartões**

Criar `tests/Feature/CardTest.php`:

```php
<?php

use App\Models\Card;
use App\Models\User;

it('lista apenas os cartoes do usuario autenticado', function (): void {
    $user = User::factory()->create();
    Card::factory()->count(2)->for($user)->create();
    Card::factory()->count(3)->create();

    $this->actingAs($user)
        ->get(route('cards.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('cards/index')
            ->has('cards', 2));
});

it('cria um cartao para o usuario autenticado', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cards.store'), [
            'name' => 'Nubank',
            'brand' => 'mastercard',
            'label' => 'Platinum',
            'last_four_digits' => '8291',
            'credit_limit_cents' => 5_000_000,
            'closing_day' => 3,
            'due_day' => 10,
        ])
        ->assertRedirect(route('cards.index'));

    expect($user->cards()->count())->toBe(1)
        ->and($user->cards()->first()->name)->toBe('Nubank');
});

it('rejeita dias fora da faixa de 1 a 31', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cards.store'), [
            'name' => 'Nubank',
            'brand' => 'visa',
            'last_four_digits' => '8291',
            'credit_limit_cents' => 100_000,
            'closing_day' => 0,
            'due_day' => 32,
        ])
        ->assertSessionHasErrors(['closing_day', 'due_day']);
});

it('exige exatamente quatro digitos finais', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cards.store'), [
            'name' => 'Nubank',
            'brand' => 'visa',
            'last_four_digits' => '82',
            'credit_limit_cents' => 100_000,
            'closing_day' => 3,
            'due_day' => 10,
        ])
        ->assertSessionHasErrors('last_four_digits');
});

it('rejeita bandeira desconhecida', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cards.store'), [
            'name' => 'Nubank',
            'brand' => 'diners',
            'last_four_digits' => '8291',
            'credit_limit_cents' => 100_000,
            'closing_day' => 3,
            'due_day' => 10,
        ])
        ->assertSessionHasErrors('brand');
});

it('atualiza um cartao proprio', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['name' => 'Antigo']);

    $this->actingAs($user)
        ->patch(route('cards.update', $card), [
            'name' => 'Novo',
            'brand' => $card->brand->value,
            'last_four_digits' => $card->last_four_digits,
            'credit_limit_cents' => $card->credit_limit_cents,
            'closing_day' => $card->closing_day,
            'due_day' => $card->due_day,
        ])
        ->assertRedirect(route('cards.index'));

    expect($card->fresh()->name)->toBe('Novo');
});

it('apaga um cartao proprio', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('cards.destroy', $card))
        ->assertRedirect(route('cards.index'));

    expect(Card::count())->toBe(0);
});

it('nega acesso a cartao de outro usuario', function (string $method, string $routeName): void {
    $user = User::factory()->create();
    $card = Card::factory()->create();

    $this->actingAs($user)
        ->call($method, route($routeName, $card))
        ->assertForbidden();
})->with([
    'edit' => ['get', 'cards.edit'],
    'update' => ['patch', 'cards.update'],
    'destroy' => ['delete', 'cards.destroy'],
]);

it('exige autenticacao', function (): void {
    $this->get(route('cards.index'))->assertRedirect(route('login'));
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter=CardTest`
Expected: FAIL com `Route [cards.index] not defined`.

- [ ] **Step 3: Adicionar a relação `cards` ao User**

Em `app/Models/User.php`, acrescentar o import de `HasMany` e o método:

```php
/** @return HasMany<Card, $this> */
public function cards(): HasMany
{
    return $this->hasMany(Card::class);
}
```

E na PHPDoc da classe: `@property-read \Illuminate\Database\Eloquent\Collection<int, Card> $cards`.

- [ ] **Step 4: Criar a Policy**

Run: `php artisan make:policy CardPolicy --model=Card --no-interaction`

Substituir o corpo gerado por:

```php
<?php

namespace App\Policies;

use App\Models\Card;
use App\Models\User;

class CardPolicy
{
    public function view(User $user, Card $card): bool
    {
        return $card->user_id === $user->id;
    }

    public function update(User $user, Card $card): bool
    {
        return $card->user_id === $user->id;
    }

    public function delete(User $user, Card $card): bool
    {
        return $card->user_id === $user->id;
    }
}
```

O Laravel descobre a policy por convenção de nome; não é preciso registrar nada.

- [ ] **Step 5: Criar os Form Requests**

```bash
php artisan make:request StoreCardRequest --no-interaction
php artisan make:request UpdateCardRequest --no-interaction
```

`app/Http/Requests/StoreCardRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\CardBrand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCardRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'brand' => ['required', Rule::enum(CardBrand::class)],
            'label' => ['nullable', 'string', 'max:40'],
            'last_four_digits' => ['required', 'digits:4'],
            'credit_limit_cents' => ['required', 'integer', 'min:1'],
            'closing_day' => ['required', 'integer', 'between:1,31'],
            'due_day' => ['required', 'integer', 'between:1,31'],
        ];
    }
}
```

`UpdateCardRequest` tem exatamente as mesmas regras. Manter as duas classes separadas — quando a edição divergir da criação, o lugar já existe.

- [ ] **Step 6: Criar o controller**

Run: `php artisan make:controller CardController --no-interaction`

```php
<?php

namespace App\Http\Controllers;

use App\Enums\CardBrand;
use App\Http\Requests\StoreCardRequest;
use App\Http\Requests\UpdateCardRequest;
use App\Models\Card;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CardController extends Controller
{
    public function index(Request $request): Response
    {
        $cards = $request->user()->cards()
            ->orderBy('name')
            ->get()
            ->map(fn (Card $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'brand' => $card->brand->value,
                'brand_label' => $card->brand->label(),
                'label' => $card->label,
                'last_four_digits' => $card->last_four_digits,
                'credit_limit_cents' => $card->credit_limit_cents,
                'closing_day' => $card->closing_day,
                'due_day' => $card->due_day,
            ]);

        return Inertia::render('cards/index', [
            'cards' => $cards,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('cards/create', [
            'brands' => $this->brandOptions(),
        ]);
    }

    public function store(StoreCardRequest $request): RedirectResponse
    {
        $request->user()->cards()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cartão cadastrado.']);

        return to_route('cards.index');
    }

    public function edit(Card $card): Response
    {
        $this->authorize('update', $card);

        return Inertia::render('cards/edit', [
            'card' => [
                'id' => $card->id,
                'name' => $card->name,
                'brand' => $card->brand->value,
                'label' => $card->label,
                'last_four_digits' => $card->last_four_digits,
                'credit_limit_cents' => $card->credit_limit_cents,
                'closing_day' => $card->closing_day,
                'due_day' => $card->due_day,
            ],
            'brands' => $this->brandOptions(),
        ]);
    }

    public function update(UpdateCardRequest $request, Card $card): RedirectResponse
    {
        $this->authorize('update', $card);

        $card->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'warning',
            'message' => 'Cartão atualizado. As parcelas já lançadas mantêm as datas antigas — rode installments:rebuild para recalculá-las.',
        ]);

        return to_route('cards.index');
    }

    public function destroy(Card $card): RedirectResponse
    {
        $this->authorize('delete', $card);

        $card->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cartão removido.']);

        return to_route('cards.index');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function brandOptions(): array
    {
        return array_map(
            fn (CardBrand $brand): array => ['value' => $brand->value, 'label' => $brand->label()],
            CardBrand::cases(),
        );
    }
}
```

O toast do `update` avisa sobre as parcelas existentes, exatamente como a spec exige: mudar `due_day` **não** regera nada em silêncio.

- [ ] **Step 7: Criar as rotas**

Criar `routes/parcelamentos.php`:

```php
<?php

use App\Http\Controllers\CardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('cartoes', [CardController::class, 'index'])->name('cards.index');
    Route::get('cartoes/novo', [CardController::class, 'create'])->name('cards.create');
    Route::post('cartoes', [CardController::class, 'store'])->name('cards.store');
    Route::get('cartoes/{card}/editar', [CardController::class, 'edit'])->name('cards.edit');
    Route::patch('cartoes/{card}', [CardController::class, 'update'])->name('cards.update');
    Route::delete('cartoes/{card}', [CardController::class, 'destroy'])->name('cards.destroy');
});
```

Em `routes/web.php`, acrescentar antes do `require` de settings:

```php
require __DIR__.'/parcelamentos.php';
```

- [ ] **Step 8: Rodar e ver passar**

Run: `php artisan test --compact --filter=CardTest`
Expected: PASS, 11 testes.

- [ ] **Step 9: Criar os helpers de formatação**

Criar `resources/js/lib/currency.ts`:

```ts
const BRL = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const MONTH = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const DATE = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

export function formatBRL(cents: number): string {
    return BRL.format(cents / 100);
}

export function parseBRLToCents(input: string): number {
    const digits = input.replace(/\D/g, '');

    return digits === '' ? 0 : Number.parseInt(digits, 10);
}

export function formatMonthLabel(iso: string): string {
    return MONTH.format(new Date(`${iso.slice(0, 7)}-01T00:00:00Z`));
}

export function formatDate(iso: string): string {
    return DATE.format(new Date(`${iso.slice(0, 10)}T00:00:00Z`));
}
```

`timeZone: 'UTC'` em tudo que formata data: as datas chegam como `YYYY-MM-DD` sem hora, e sem isso o navegador em Brasília renderiza o dia anterior.

`parseBRLToCents` só extrai dígitos — o usuário digita `1.234,56`, `1234,56` ou `123456` e todos viram `123456` centavos.

- [ ] **Step 10: Criar os tipos compartilhados**

Criar `resources/js/types/parcelamentos.ts`:

```ts
export type CardBrandOption = {
    value: string;
    label: string;
};

export type CardSummary = {
    id: number;
    name: string;
    brand: string;
    brand_label: string;
    label: string | null;
    last_four_digits: string;
    credit_limit_cents: number;
    closing_day: number;
    due_day: number;
};

export type CardFormValues = {
    id?: number;
    name: string;
    brand: string;
    label: string | null;
    last_four_digits: string;
    credit_limit_cents: number;
    closing_day: number;
    due_day: number;
};
```

Em `resources/js/types/index.ts`, acrescentar a linha:

```ts
export type * from './parcelamentos';
```

- [ ] **Step 11: Criar o componente de estado vazio**

Criar `resources/js/components/empty-state.tsx`:

```tsx
import type { ReactNode } from 'react';

type EmptyStateProps = {
    title: string;
    description: string;
    action?: ReactNode;
};

export function EmptyState({ title, description, action }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border">
            <h2 className="text-lg font-semibold">{title}</h2>
            <p className="max-w-sm text-sm text-muted-foreground">{description}</p>
            {action}
        </div>
    );
}
```

- [ ] **Step 12: Criar o formulário compartilhado de cartão**

Criar `resources/js/pages/cards/card-form.tsx`:

```tsx
import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBRL, parseBRLToCents } from '@/lib/currency';
import type { CardBrandOption, CardFormValues } from '@/types';

type CardFormProps = {
    action: Record<string, unknown>;
    brands: CardBrandOption[];
    card?: CardFormValues;
    submitLabel: string;
};

export function CardForm({ action, brands, card, submitLabel }: CardFormProps) {
    const [limitCents, setLimitCents] = useState(card?.credit_limit_cents ?? 0);
    const [brand, setBrand] = useState(card?.brand ?? brands[0].value);

    return (
        <Form {...action} options={{ preserveScroll: true }} className="space-y-6">
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Apelido do cartão</Label>
                        <Input id="name" name="name" defaultValue={card?.name} required placeholder="Nubank" />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="brand">Bandeira</Label>
                            <input type="hidden" name="brand" value={brand} />
                            <Select value={brand} onValueChange={setBrand}>
                                <SelectTrigger id="brand">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {brands.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.brand} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="label">Variante (opcional)</Label>
                            <Input id="label" name="label" defaultValue={card?.label ?? ''} placeholder="Platinum" />
                            <InputError message={errors.label} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="last_four_digits">Últimos 4 dígitos</Label>
                            <Input
                                id="last_four_digits"
                                name="last_four_digits"
                                defaultValue={card?.last_four_digits}
                                inputMode="numeric"
                                maxLength={4}
                                required
                                placeholder="8291"
                            />
                            <InputError message={errors.last_four_digits} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="credit_limit">Limite total</Label>
                            <input type="hidden" name="credit_limit_cents" value={limitCents} />
                            <Input
                                id="credit_limit"
                                inputMode="numeric"
                                value={formatBRL(limitCents)}
                                onChange={(event) => setLimitCents(parseBRLToCents(event.target.value))}
                            />
                            <InputError message={errors.credit_limit_cents} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="closing_day">Dia de fechamento</Label>
                            <Input
                                id="closing_day"
                                name="closing_day"
                                type="number"
                                min={1}
                                max={31}
                                defaultValue={card?.closing_day ?? 1}
                                required
                            />
                            <InputError message={errors.closing_day} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="due_day">Dia de vencimento</Label>
                            <Input
                                id="due_day"
                                name="due_day"
                                type="number"
                                min={1}
                                max={31}
                                defaultValue={card?.due_day ?? 10}
                                required
                            />
                            <InputError message={errors.due_day} />
                        </div>
                    </div>

                    <p className="text-sm text-muted-foreground">
                        Compras feitas depois do dia de fechamento entram na fatura do mês seguinte.
                    </p>

                    <Button type="submit" disabled={processing}>
                        {submitLabel}
                    </Button>
                </>
            )}
        </Form>
    );
}
```

O limite usa um input formatado que espelha o valor num `<input type="hidden">` em centavos. O servidor só vê o inteiro; o usuário só vê `R$ 50.000,00`.

- [ ] **Step 13: Criar as páginas de cartão**

`resources/js/pages/cards/create.tsx`:

```tsx
import { Head } from '@inertiajs/react';
import CardController from '@/actions/App/Http/Controllers/CardController';
import Heading from '@/components/heading';
import { index } from '@/routes/cards';
import type { CardBrandOption } from '@/types';
import { CardForm } from './card-form';

export default function CreateCard({ brands }: { brands: CardBrandOption[] }) {
    return (
        <>
            <Head title="Novo cartão" />

            <div className="max-w-2xl space-y-6 p-4">
                <Heading title="Novo cartão" description="Cadastre um cartão para começar a lançar parcelamentos" />
                <CardForm action={CardController.store.form()} brands={brands} submitLabel="Cadastrar cartão" />
            </div>
        </>
    );
}

CreateCard.layout = {
    breadcrumbs: [
        { title: 'Meus Cartões', href: index() },
        { title: 'Novo cartão', href: '' },
    ],
};
```

`resources/js/pages/cards/edit.tsx`:

```tsx
import { Head } from '@inertiajs/react';
import CardController from '@/actions/App/Http/Controllers/CardController';
import Heading from '@/components/heading';
import { index } from '@/routes/cards';
import type { CardBrandOption, CardFormValues } from '@/types';
import { CardForm } from './card-form';

export default function EditCard({ card, brands }: { card: CardFormValues; brands: CardBrandOption[] }) {
    return (
        <>
            <Head title={`Editar ${card.name}`} />

            <div className="max-w-2xl space-y-6 p-4">
                <Heading title="Editar cartão" description="Alterar fechamento ou vencimento não recalcula parcelas já lançadas" />
                <CardForm
                    action={CardController.update.form({ card: card.id! })}
                    brands={brands}
                    card={card}
                    submitLabel="Salvar alterações"
                />
            </div>
        </>
    );
}

EditCard.layout = {
    breadcrumbs: [
        { title: 'Meus Cartões', href: index() },
        { title: 'Editar', href: '' },
    ],
};
```

`resources/js/pages/cards/index.tsx`:

```tsx
import { Head, Link, router } from '@inertiajs/react';
import { CreditCard, Pencil, Trash2 } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { create, destroy, edit, index } from '@/routes/cards';
import { formatBRL } from '@/lib/currency';
import type { CardSummary } from '@/types';

export default function CardsIndex({ cards }: { cards: CardSummary[] }) {
    function remove(card: CardSummary) {
        if (!window.confirm(`Remover ${card.name}? Todas as compras e parcelas desse cartão serão apagadas.`)) {
            return;
        }

        router.delete(destroy(card.id).url, { preserveScroll: true });
    }

    return (
        <>
            <Head title="Meus Cartões" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-semibold tracking-tight">Meus Cartões</h1>
                    <Button asChild>
                        <Link href={create()}>Novo cartão</Link>
                    </Button>
                </div>

                {cards.length === 0 ? (
                    <EmptyState
                        title="Nenhum cartão cadastrado"
                        description="Cadastre seu primeiro cartão para começar a acompanhar seus parcelamentos."
                        action={
                            <Button asChild>
                                <Link href={create()}>Cadastrar cartão</Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {cards.map((card) => (
                            <Card key={card.id}>
                                <CardHeader className="flex-row items-start justify-between gap-2">
                                    <div>
                                        <CardTitle>{card.name}</CardTitle>
                                        <p className="text-xs uppercase tracking-wide text-muted-foreground">
                                            {[card.label, card.brand_label].filter(Boolean).join(' · ')}
                                        </p>
                                    </div>
                                    <CreditCard className="size-5 text-muted-foreground" />
                                </CardHeader>

                                <CardContent className="space-y-3">
                                    <p className="font-mono text-lg tabular-nums">•••• {card.last_four_digits}</p>

                                    <dl className="grid grid-cols-2 gap-2 text-sm">
                                        <div>
                                            <dt className="text-muted-foreground">Limite</dt>
                                            <dd className="tabular-nums">{formatBRL(card.credit_limit_cents)}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">Fecha / vence</dt>
                                            <dd className="tabular-nums">
                                                dia {card.closing_day} / dia {card.due_day}
                                            </dd>
                                        </div>
                                    </dl>

                                    <div className="flex gap-2">
                                        <Button variant="outline" size="sm" asChild>
                                            <Link href={edit(card.id)}>
                                                <Pencil /> Editar
                                            </Link>
                                        </Button>
                                        <Button variant="outline" size="sm" onClick={() => remove(card)}>
                                            <Trash2 /> Remover
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

CardsIndex.layout = {
    breadcrumbs: [{ title: 'Meus Cartões', href: index() }],
};
```

- [ ] **Step 14: Acrescentar os itens de navegação**

Em `resources/js/components/app-sidebar.tsx`, trocar o `mainNavItems` por:

```tsx
const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Meus Cartões',
        href: cards.index(),
        icon: CreditCard,
    },
];
```

Acrescentar `CreditCard` ao import de `lucide-react` e `import cards from '@/routes/cards';`. O item de Histórico entra na Task 8.

- [ ] **Step 15: Gerar as rotas do Wayfinder e checar o front**

```bash
npm run build
npm run lint:check
npm run format:check
npm run types:check
```

Expected: tudo verde. Se `@/routes/cards` não existir, é porque o build do Wayfinder não rodou — `npm run build` regenera.

- [ ] **Step 16: Ver a tela no navegador**

Run: `composer run dev`

Abrir a URL do Herd para o projeto, autenticar, e visitar `/cartoes`. Confirmar visualmente: estado vazio com CTA, cadastro de um cartão, edição, e o toast de aviso ao mudar o vencimento.

- [ ] **Step 17: Formatar, checar e commitar**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact
git add app routes resources tests
git commit -m "$(cat <<'EOF'
feat: CRUD de cartões com autorização por proprietário

Policy, form requests, controller e telas de listagem, cadastro e
edição. Helpers de formatação BRL e componente de estado vazio.

Claude-Session: https://claude.ai/code/session_0156ogwsuLM3WTFASr1rELTR
EOF
)"
```

---

## Task 5: Lançamentos — criar e apagar compras parceladas

A única entrada de dados do Spec 1. Não existe tela equivalente no Stitch: o layout é desenhado aqui.

**Files:**
- Create: `app/Policies/PurchasePolicy.php`
- Create: `app/Http/Requests/StorePurchaseRequest.php`
- Create: `app/Http/Controllers/PurchaseController.php`
- Modify: `routes/parcelamentos.php`
- Create: `resources/js/lib/installments.ts`
- Modify: `resources/js/types/parcelamentos.ts`
- Create: `resources/js/pages/purchases/create.tsx`
- Modify: `resources/js/components/app-sidebar.tsx`
- Test: `tests/Feature/PurchaseTest.php`, `tests/Feature/SchedulePreviewParityTest.php`

**Interfaces:**
- Consumes: `App\Actions\GenerateInstallments::handle(Purchase): void` (Task 3); `App\Models\Card`, `Purchase` (Task 2); `formatBRL`, `parseBRLToCents` (Task 4).
- Produces:
  - Rotas nomeadas `purchases.create`, `purchases.store`, `purchases.destroy`
  - `resources/js/lib/installments.ts` → `buildSchedulePreview(totalCents: number, count: number, purchasedAt: string, closingDay: number, dueDay: number): SchedulePreviewRow[]`
  - Tipo `SchedulePreviewRow = { number: number; amount_cents: number; due_date: string }`
  - Tipo `CardOption = { id: number; name: string; last_four_digits: string; closing_day: number; due_day: number }`

- [ ] **Step 1: Escrever o teste de lançamentos**

Criar `tests/Feature/PurchaseTest.php`:

```php
<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

it('redireciona para o cadastro de cartao quando nao ha nenhum', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('purchases.create'))
        ->assertRedirect(route('cards.create'));
});

it('mostra o formulario quando ha ao menos um cartao', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('purchases.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('purchases/create')->has('cards', 1));
});

it('cria a compra e gera as parcelas', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['closing_day' => 3, 'due_day' => 10]);

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'MacBook Pro M2',
            'merchant' => 'Apple Store',
            'total_amount_cents' => 100_000,
            'installments_count' => 3,
            'purchased_at' => '2026-03-02',
        ])
        ->assertRedirect(route('dashboard'));

    $purchase = Purchase::sole();

    expect($purchase->installments()->count())->toBe(3)
        ->and($purchase->installments()->pluck('amount_cents')->all())->toBe([33334, 33333, 33333])
        ->and($purchase->installments()->pluck('due_date')->map->toDateString()->all())
        ->toBe(['2026-03-10', '2026-04-10', '2026-05-10']);
});

it('aceita compra a vista como uma parcela', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Almoço',
            'total_amount_cents' => 5_000,
            'installments_count' => 1,
            'purchased_at' => '2026-03-02',
        ])
        ->assertRedirect(route('dashboard'));

    expect(Installment::count())->toBe(1);
});

it('recusa lancamento em cartao de outro usuario', function (): void {
    $user = User::factory()->create();
    $foreign = Card::factory()->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $foreign->id,
            'description' => 'Invasão',
            'total_amount_cents' => 5_000,
            'installments_count' => 1,
            'purchased_at' => '2026-03-02',
        ])
        ->assertSessionHasErrors('card_id');

    expect(Purchase::count())->toBe(0);
});

it('recusa compra com data no futuro', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Viagem no tempo',
            'total_amount_cents' => 5_000,
            'installments_count' => 1,
            'purchased_at' => now()->addDay()->toDateString(),
        ])
        ->assertSessionHasErrors('purchased_at');
});

it('recusa mais de 48 parcelas e valor zero', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Absurdo',
            'total_amount_cents' => 0,
            'installments_count' => 49,
            'purchased_at' => '2026-03-02',
        ])
        ->assertSessionHasErrors(['total_amount_cents', 'installments_count']);
});

it('apaga a compra e suas parcelas', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->parcelada(6)->for(Card::factory()->for($user))->create();

    $this->actingAs($user)
        ->delete(route('purchases.destroy', $purchase))
        ->assertRedirect();

    expect(Purchase::count())->toBe(0)
        ->and(Installment::count())->toBe(0);
});

it('nega apagar compra de outro usuario', function (): void {
    $user = User::factory()->create();
    $foreign = Purchase::factory()->parcelada(2)->create();

    $this->actingAs($user)
        ->delete(route('purchases.destroy', $foreign))
        ->assertForbidden();

    expect(Purchase::count())->toBe(1);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter=PurchaseTest`
Expected: FAIL com `Route [purchases.create] not defined`.

- [ ] **Step 3: Criar a Policy**

Run: `php artisan make:policy PurchasePolicy --model=Purchase --no-interaction`

```php
<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function view(User $user, Purchase $purchase): bool
    {
        return $purchase->card->user_id === $user->id;
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return $purchase->card->user_id === $user->id;
    }
}
```

- [ ] **Step 4: Criar o Form Request**

Run: `php artisan make:request StorePurchaseRequest --no-interaction`

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'card_id' => [
                'required',
                Rule::exists('cards', 'id')->where('user_id', $this->user()->id),
            ],
            'description' => ['required', 'string', 'max:120'],
            'merchant' => ['nullable', 'string', 'max:120'],
            'total_amount_cents' => ['required', 'integer', 'min:1'],
            'installments_count' => ['required', 'integer', 'between:1,48'],
            'purchased_at' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
```

A regra `exists` filtrada por `user_id` é o que impede lançar num cartão alheio — sem ela, a Policy não ajuda, porque a compra ainda não existe para ser autorizada.

- [ ] **Step 5: Criar o controller**

Run: `php artisan make:controller PurchaseController --no-interaction`

```php
<?php

namespace App\Http\Controllers;

use App\Actions\GenerateInstallments;
use App\Http\Requests\StorePurchaseRequest;
use App\Models\Card;
use App\Models\Purchase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $cards = $request->user()->cards()->orderBy('name')->get();

        if ($cards->isEmpty()) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => 'Cadastre um cartão antes de lançar uma compra.',
            ]);

            return to_route('cards.create');
        }

        return Inertia::render('purchases/create', [
            'cards' => $cards->map(fn (Card $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'last_four_digits' => $card->last_four_digits,
                'closing_day' => $card->closing_day,
                'due_day' => $card->due_day,
            ]),
        ]);
    }

    public function store(StorePurchaseRequest $request, GenerateInstallments $generateInstallments): RedirectResponse
    {
        DB::transaction(function () use ($request, $generateInstallments): void {
            $purchase = Purchase::create($request->validated());

            $generateInstallments->handle($purchase->load('card'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento registrado.']);

        return to_route('dashboard');
    }

    public function destroy(Purchase $purchase): RedirectResponse
    {
        $this->authorize('delete', $purchase);

        $purchase->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento removido.']);

        return back();
    }
}
```

- [ ] **Step 6: Acrescentar as rotas**

Em `routes/parcelamentos.php`, dentro do grupo existente:

```php
Route::get('lancamentos/novo', [PurchaseController::class, 'create'])->name('purchases.create');
Route::post('lancamentos', [PurchaseController::class, 'store'])->name('purchases.store');
Route::delete('lancamentos/{purchase}', [PurchaseController::class, 'destroy'])->name('purchases.destroy');
```

Mais o `use App\Http\Controllers\PurchaseController;` no topo.

- [ ] **Step 7: Rodar e ver passar**

Run: `php artisan test --compact --filter=PurchaseTest`
Expected: PASS, 9 testes.

- [ ] **Step 8: Escrever o espelho do cronograma no cliente**

Criar `resources/js/lib/installments.ts`:

```ts
import type { SchedulePreviewRow } from '@/types';

function daysInMonth(year: number, month: number): number {
    return new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
}

function onDay(year: number, month: number, day: number): string {
    const clamped = Math.min(day, daysInMonth(year, month));

    return new Date(Date.UTC(year, month, clamped)).toISOString().slice(0, 10);
}

function firstDueMonth(purchasedAt: string, closingDay: number, dueDay: number): [number, number] {
    const purchased = new Date(`${purchasedAt}T00:00:00Z`);

    let year = purchased.getUTCFullYear();
    let month = purchased.getUTCMonth();

    if (purchased.getUTCDate() > closingDay) {
        month += 1;
    }

    if (dueDay <= closingDay) {
        month += 1;
    }

    year += Math.floor(month / 12);
    month = ((month % 12) + 12) % 12;

    return [year, month];
}

export function buildSchedulePreview(
    totalCents: number,
    count: number,
    purchasedAt: string,
    closingDay: number,
    dueDay: number,
): SchedulePreviewRow[] {
    if (totalCents <= 0 || count <= 0 || purchasedAt === '') {
        return [];
    }

    const base = Math.floor(totalCents / count);
    const remainder = totalCents % count;
    const [year, month] = firstDueMonth(purchasedAt, closingDay, dueDay);

    return Array.from({ length: count }, (_, index) => ({
        number: index + 1,
        amount_cents: index === 0 ? base + remainder : base,
        due_date: onDay(year, month + index, dueDay),
    }));
}
```

`onDay(year, month + index, ...)` deixa o `Date.UTC` normalizar meses acima de 11 — não é preciso tratar a virada de ano à mão.

Acrescentar a `resources/js/types/parcelamentos.ts`:

```ts
export type SchedulePreviewRow = {
    number: number;
    amount_cents: number;
    due_date: string;
};

export type CardOption = {
    id: number;
    name: string;
    last_four_digits: string;
    closing_day: number;
    due_day: number;
};
```

- [ ] **Step 9: Escrever o teste de paridade entre servidor e cliente**

Este espelho no cliente é duplicação deliberada, e duplicação sem teste diverge. Não há runner de testes JS configurado neste projeto, então a paridade é verificada por um teste PHP que executa o código TypeScript compilado via Node.

Criar `tests/Feature/SchedulePreviewParityTest.php`:

```php
<?php

use App\Actions\BuildInstallmentSchedule;
use App\Actions\CalculateFirstDueDate;
use App\Actions\SplitIntoInstallments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

it('o preview no cliente concorda com o calculo do servidor', function (
    int $total,
    int $count,
    string $purchasedAt,
    int $closingDay,
    int $dueDay,
): void {
    $server = (new BuildInstallmentSchedule(new SplitIntoInstallments, new CalculateFirstDueDate))
        ->handle($total, $count, CarbonImmutable::parse($purchasedAt), $closingDay, $dueDay);

    $expected = array_map(fn (array $row): array => [
        'number' => $row['number'],
        'amount_cents' => $row['amount_cents'],
        'due_date' => $row['due_date']->toDateString(),
    ], $server);

    $script = <<<JS
    import { buildSchedulePreview } from './resources/js/lib/installments.ts';
    console.log(JSON.stringify(buildSchedulePreview({$total}, {$count}, '{$purchasedAt}', {$closingDay}, {$dueDay})));
    JS;

    $result = Process::path(base_path())->run([
        'node', '--experimental-strip-types', '--input-type=module', '--eval', $script,
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(json_decode(trim($result->output()), true))->toBe($expected);
})->with([
    [100000, 3, '2026-03-02', 3, 10],
    [100000, 3, '2026-03-05', 3, 10],
    [100000, 3, '2026-03-02', 28, 5],
    [30000, 3, '2025-12-15', 10, 31],
    [30000, 3, '2026-11-02', 3, 10],
    [50000, 1, '2026-03-02', 3, 10],
    [99999, 7, '2026-01-31', 15, 20],
]);
```

O import com extensão `.ts` e a flag `--experimental-strip-types` exigem Node 22.6+. Se a versão do Node for menor, marcar o teste com `->skip()` e registrar no commit — a alternativa (configurar Vitest) é escopo novo e não está neste plano.

- [ ] **Step 10: Rodar e ver passar**

Run: `php artisan test --compact --filter=SchedulePreviewParity`
Expected: PASS, 7 testes. Se falhar, a divergência aponta exatamente qual combinação de entrada o cliente calcula diferente.

- [ ] **Step 11: Criar a página de novo lançamento**

Criar `resources/js/pages/purchases/create.tsx`:

```tsx
import { Form, Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import PurchaseController from '@/actions/App/Http/Controllers/PurchaseController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { buildSchedulePreview } from '@/lib/installments';
import { formatBRL, formatDate, parseBRLToCents } from '@/lib/currency';
import { dashboard } from '@/routes';
import type { CardOption } from '@/types';

export default function CreatePurchase({ cards }: { cards: CardOption[] }) {
    const today = new Date().toISOString().slice(0, 10);

    const [cardId, setCardId] = useState(String(cards[0].id));
    const [totalCents, setTotalCents] = useState(0);
    const [count, setCount] = useState(1);
    const [purchasedAt, setPurchasedAt] = useState(today);

    const selectedCard = cards.find((card) => String(card.id) === cardId) ?? cards[0];

    const preview = useMemo(
        () => buildSchedulePreview(totalCents, count, purchasedAt, selectedCard.closing_day, selectedCard.due_day),
        [totalCents, count, purchasedAt, selectedCard],
    );

    return (
        <>
            <Head title="Novo lançamento" />

            <div className="grid gap-6 p-4 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div className="space-y-6">
                    <Heading title="Novo lançamento" description="Registre uma compra parcelada ou à vista" />

                    <Form {...PurchaseController.store.form()} options={{ preserveScroll: true }} className="space-y-6">
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="card_id">Cartão</Label>
                                    <input type="hidden" name="card_id" value={cardId} />
                                    <Select value={cardId} onValueChange={setCardId}>
                                        <SelectTrigger id="card_id">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {cards.map((card) => (
                                                <SelectItem key={card.id} value={String(card.id)}>
                                                    {card.name} •••• {card.last_four_digits}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.card_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="description">Descrição</Label>
                                    <Input id="description" name="description" required placeholder="MacBook Pro M2" />
                                    <InputError message={errors.description} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="merchant">Estabelecimento (opcional)</Label>
                                    <Input id="merchant" name="merchant" placeholder="Apple Store - Shopping Morumbi" />
                                    <InputError message={errors.merchant} />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="total">Valor total</Label>
                                        <input type="hidden" name="total_amount_cents" value={totalCents} />
                                        <Input
                                            id="total"
                                            inputMode="numeric"
                                            value={formatBRL(totalCents)}
                                            onChange={(event) => setTotalCents(parseBRLToCents(event.target.value))}
                                        />
                                        <InputError message={errors.total_amount_cents} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="installments_count">Parcelas</Label>
                                        <Input
                                            id="installments_count"
                                            name="installments_count"
                                            type="number"
                                            min={1}
                                            max={48}
                                            value={count}
                                            onChange={(event) => setCount(Number(event.target.value))}
                                            required
                                        />
                                        <InputError message={errors.installments_count} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="purchased_at">Data da compra</Label>
                                        <Input
                                            id="purchased_at"
                                            name="purchased_at"
                                            type="date"
                                            max={today}
                                            value={purchasedAt}
                                            onChange={(event) => setPurchasedAt(event.target.value)}
                                            required
                                        />
                                        <InputError message={errors.purchased_at} />
                                    </div>
                                </div>

                                <Button type="submit" disabled={processing}>
                                    Lançar compra
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                <Card className="h-fit">
                    <CardHeader>
                        <CardTitle>Parcelas que serão geradas</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {preview.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Informe o valor e o número de parcelas para ver o cronograma.
                            </p>
                        ) : (
                            <ol className="divide-y text-sm">
                                {preview.map((row) => (
                                    <li key={row.number} className="flex items-baseline justify-between gap-4 py-2">
                                        <span className="text-muted-foreground tabular-nums">
                                            {row.number}/{preview.length}
                                        </span>
                                        <span className="tabular-nums">{formatBRL(row.amount_cents)}</span>
                                        <span className="text-muted-foreground">{formatDate(row.due_date)}</span>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

CreatePurchase.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Novo lançamento', href: '' },
    ],
};
```

- [ ] **Step 12: Acrescentar o botão no sidebar**

Em `resources/js/components/app-sidebar.tsx`, dentro de `<SidebarContent>`, abaixo de `<NavMain items={mainNavItems} />`:

```tsx
<div className="px-2 pt-2">
    <Button asChild className="w-full">
        <Link href={purchases.create()}>
            <Plus /> Novo Lançamento
        </Link>
    </Button>
</div>
```

Com `import purchases from '@/routes/purchases';`, `import { Button } from '@/components/ui/button';` e `Plus` no import de `lucide-react`.

- [ ] **Step 13: Verificar o front e ver no navegador**

```bash
npm run build
npm run lint:check
npm run format:check
npm run types:check
composer run dev
```

Abrir `/lancamentos/novo`, digitar R$ 1.000,00 em 3x e conferir que o painel lateral mostra `33334 / 33333 / 33333` com as datas certas. Salvar e conferir que o banco tem exatamente essas parcelas.

- [ ] **Step 14: Formatar, checar e commitar**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact
git add app routes resources tests
git commit -m "$(cat <<'EOF'
feat: lançamento manual de compras parceladas

Policy, form request e controller de compras, com geração de parcelas
em transação. Tela nova com preview ao vivo do cronograma e teste de
paridade entre o cálculo do cliente e o do servidor.

Claude-Session: https://claude.ai/code/session_0156ogwsuLM3WTFASr1rELTR
EOF
)"
```

---

## Task 6: Dashboard — agregações, gráfico e tela

**Files:**
- Create: `app/Actions/BuildDashboardSummary.php`, `app/Actions/BuildMonthlyProjection.php`
- Create: `app/Http/Controllers/DashboardController.php`
- Modify: `routes/web.php` (trocar o `Route::inertia('dashboard', ...)`)
- Modify: `package.json` (recharts)
- Create: `resources/js/components/ui/chart.tsx`, `resources/js/components/projection-chart.tsx`
- Modify: `resources/js/types/parcelamentos.ts`
- Modify: `resources/js/pages/dashboard.tsx`
- Test: `tests/Feature/DashboardTest.php` (o arquivo já existe — estender, não substituir)

**Interfaces:**
- Consumes: `App\Models\Installment` com scopes `forUser`, `open`, `paid` (Task 2); `formatBRL`, `formatMonthLabel`, `formatDate` (Task 4).
- Produces:
  - `BuildDashboardSummary::handle(User $user): array{cards_count: int, open_installments_count: int, total_due_cents: int, next_due_date: string|null, next_due_cents: int}`
  - `BuildMonthlyProjection::handle(User $user, int $months = 6): array<int, array{month: string, total_cents: int}>` — `month` no formato `YYYY-MM`, sempre com `$months` entradas, incluindo meses de valor zero
  - `ProjectionChart` com prop `{ months: MonthlyProjectionRow[] }`

- [ ] **Step 1: Escrever os testes das agregações**

Substituir o conteúdo de `tests/Feature/DashboardTest.php` mantendo o teste de autenticação que já existe lá, e acrescentar:

```php
<?php

use App\Actions\BuildDashboardSummary;
use App\Actions\BuildMonthlyProjection;
use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

function installmentOn(User $user, string $dueDate, int $amountCents, int $number = 1): Installment
{
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create();

    return Installment::factory()->for($purchase)->create([
        'number' => $number,
        'due_date' => $dueDate,
        'amount_cents' => $amountCents,
    ]);
}

it('exige autenticacao no dashboard', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('resume apenas as parcelas do usuario', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-07-20', 100_00);
    installmentOn($user, '2026-08-20', 200_00);
    installmentOn(User::factory()->create(), '2026-08-20', 999_00);

    $summary = app(BuildDashboardSummary::class)->handle($user);

    expect($summary['total_due_cents'])->toBe(300_00)
        ->and($summary['open_installments_count'])->toBe(2)
        ->and($summary['cards_count'])->toBe(2);
});

it('ignora parcelas vencidas no total devido', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-06-20', 500_00);
    installmentOn($user, '2026-08-20', 100_00);

    $summary = app(BuildDashboardSummary::class)->handle($user);

    expect($summary['total_due_cents'])->toBe(100_00)
        ->and($summary['open_installments_count'])->toBe(1);
});

it('soma o proximo vencimento agrupando parcelas da mesma data', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-07-20', 100_00);
    installmentOn($user, '2026-07-20', 250_00);
    installmentOn($user, '2026-08-20', 900_00);

    $summary = app(BuildDashboardSummary::class)->handle($user);

    expect($summary['next_due_date'])->toBe('2026-07-20')
        ->and($summary['next_due_cents'])->toBe(350_00);
});

it('devolve resumo zerado quando nao ha nada', function (): void {
    $summary = app(BuildDashboardSummary::class)->handle(User::factory()->create());

    expect($summary)->toBe([
        'cards_count' => 0,
        'open_installments_count' => 0,
        'total_due_cents' => 0,
        'next_due_date' => null,
        'next_due_cents' => 0,
    ]);
});

it('projeta seis meses a partir do corrente', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-07-20', 100_00);
    installmentOn($user, '2026-09-20', 300_00);

    $projection = app(BuildMonthlyProjection::class)->handle($user);

    expect($projection)->toHaveCount(6)
        ->and(array_column($projection, 'month'))
        ->toBe(['2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'])
        ->and(array_column($projection, 'total_cents'))->toBe([100_00, 0, 300_00, 0, 0, 0]);
});

it('exclui da projecao o que ja venceu no mes corrente', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-07-01', 500_00);
    installmentOn($user, '2026-07-20', 100_00);

    $projection = app(BuildMonthlyProjection::class)->handle($user);

    expect($projection[0]['total_cents'])->toBe(100_00);
});

it('atravessa a virada de ano na projecao', function (): void {
    $this->travelTo('2026-11-15');
    $user = User::factory()->create();

    $projection = app(BuildMonthlyProjection::class)->handle($user);

    expect(array_column($projection, 'month'))
        ->toBe(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04']);
});

it('entrega o resumo e a projecao para a tela', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-08-20', 100_00);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('summary')
            ->has('projection', 6));
});
```

O teste "exclui o que já venceu no mês corrente" trava uma decisão que a spec define pela tabela de métricas: a projeção soma parcelas **em aberto**, então o mês corrente mostra o que ainda vai sair, não o que já saiu.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter=DashboardTest`
Expected: FAIL com `Target class [App\Actions\BuildDashboardSummary] does not exist`.

- [ ] **Step 3: Implementar as agregações**

```bash
php artisan make:class Actions/BuildDashboardSummary --no-interaction
php artisan make:class Actions/BuildMonthlyProjection --no-interaction
```

`app/Actions/BuildDashboardSummary.php`:

```php
<?php

namespace App\Actions;

use App\Models\Installment;
use App\Models\User;

class BuildDashboardSummary
{
    /**
     * @return array{
     *     cards_count: int,
     *     open_installments_count: int,
     *     total_due_cents: int,
     *     next_due_date: string|null,
     *     next_due_cents: int,
     * }
     */
    public function handle(User $user): array
    {
        $open = Installment::query()->forUser($user)->open();

        $nextDueDate = (clone $open)->min('due_date');

        $nextDueCents = $nextDueDate === null
            ? 0
            : (int) Installment::query()->forUser($user)->whereDate('due_date', $nextDueDate)->sum('amount_cents');

        return [
            'cards_count' => $user->cards()->count(),
            'open_installments_count' => (clone $open)->count(),
            'total_due_cents' => (int) (clone $open)->sum('amount_cents'),
            'next_due_date' => $nextDueDate === null ? null : substr((string) $nextDueDate, 0, 10),
            'next_due_cents' => $nextDueCents,
        ];
    }
}
```

`app/Actions/BuildMonthlyProjection.php`:

```php
<?php

namespace App\Actions;

use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;

class BuildMonthlyProjection
{
    /**
     * Total em aberto por mes, comecando no mes corrente.
     *
     * @return array<int, array{month: string, total_cents: int}>
     */
    public function handle(User $user, int $months = 6): array
    {
        $start = CarbonImmutable::now()->startOfMonth();
        $end = $start->addMonths($months)->subDay();

        $totals = Installment::query()
            ->forUser($user)
            ->open()
            ->whereDate('due_date', '<=', $end->toDateString())
            ->get(['due_date', 'amount_cents'])
            ->groupBy(fn (Installment $installment): string => $installment->due_date->format('Y-m'))
            ->map(fn ($group): int => (int) $group->sum('amount_cents'));

        return array_map(
            function (int $offset) use ($start, $totals): array {
                $month = $start->addMonths($offset)->format('Y-m');

                return ['month' => $month, 'total_cents' => $totals->get($month, 0)];
            },
            range(0, $months - 1),
        );
    }
}
```

O agrupamento acontece em PHP, não em SQL, porque `strftime` do SQLite e `DATE_FORMAT` do MySQL não são a mesma função — agrupar na coleção mantém o código independente do banco, e o conjunto aqui é de dezenas de linhas.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --compact --filter=DashboardTest`
Expected: os testes de Action passam; o último (`entrega o resumo e a projecao`) ainda falha porque o controller não existe.

- [ ] **Step 5: Criar o controller e trocar a rota**

Run: `php artisan make:controller DashboardController --no-interaction`

```php
<?php

namespace App\Http\Controllers;

use App\Actions\BuildDashboardSummary;
use App\Actions\BuildMonthlyProjection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        BuildDashboardSummary $buildDashboardSummary,
        BuildMonthlyProjection $buildMonthlyProjection,
    ): Response {
        $user = $request->user();

        return Inertia::render('dashboard', [
            'summary' => $buildDashboardSummary->handle($user),
            'projection' => $buildMonthlyProjection->handle($user),
        ]);
    }
}
```

Em `routes/web.php`, trocar o grupo existente por:

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
```

Com `use App\Http\Controllers\DashboardController;` no topo.

- [ ] **Step 6: Rodar e ver passar**

Run: `php artisan test --compact --filter=DashboardTest`
Expected: PASS, 10 testes.

- [ ] **Step 7: Instalar recharts e o componente de gráfico**

```bash
npm install recharts
npx shadcn@latest add chart
```

Se o `shadcn add chart` pedir confirmação para sobrescrever arquivos existentes, recusar tudo que não seja `components/ui/chart.tsx`.

- [ ] **Step 8: Criar o componente de projeção**

Acrescentar a `resources/js/types/parcelamentos.ts`:

```ts
export type MonthlyProjectionRow = {
    month: string;
    total_cents: number;
};

export type DashboardSummary = {
    cards_count: number;
    open_installments_count: number;
    total_due_cents: number;
    next_due_date: string | null;
    next_due_cents: number;
};
```

Criar `resources/js/components/projection-chart.tsx`:

```tsx
import { Bar, BarChart, CartesianGrid, XAxis } from 'recharts';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';
import { formatBRL, formatMonthLabel } from '@/lib/currency';
import type { MonthlyProjectionRow } from '@/types';

const config = {
    total: {
        label: 'Parcelas',
        color: 'var(--color-chart-1)',
    },
} satisfies ChartConfig;

export function ProjectionChart({ months }: { months: MonthlyProjectionRow[] }) {
    const data = months.map((row) => ({
        month: formatMonthLabel(row.month).split(' de ')[0],
        total: row.total_cents / 100,
    }));

    return (
        <ChartContainer config={config} className="h-[220px] w-full">
            <BarChart data={data} accessibilityLayer>
                <CartesianGrid vertical={false} />
                <XAxis dataKey="month" tickLine={false} axisLine={false} tickMargin={8} />
                <ChartTooltip
                    content={
                        <ChartTooltipContent
                            formatter={(value) => formatBRL(Number(value) * 100)}
                        />
                    }
                />
                <Bar dataKey="total" fill="var(--color-total)" radius={4} />
            </BarChart>
        </ChartContainer>
    );
}
```

- [ ] **Step 9: Reescrever a página do dashboard**

Substituir `resources/js/pages/dashboard.tsx`:

```tsx
import { Head, Link } from '@inertiajs/react';
import { EmptyState } from '@/components/empty-state';
import { ProjectionChart } from '@/components/projection-chart';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatBRL, formatDate, formatMonthLabel } from '@/lib/currency';
import { dashboard } from '@/routes';
import cards from '@/routes/cards';
import purchases from '@/routes/purchases';
import type { DashboardSummary, MonthlyProjectionRow } from '@/types';

type DashboardProps = {
    summary: DashboardSummary;
    projection: MonthlyProjectionRow[];
};

export default function Dashboard({ summary, projection }: DashboardProps) {
    if (summary.cards_count === 0) {
        return (
            <>
                <Head title="Dashboard" />
                <div className="p-4">
                    <EmptyState
                        title="Cadastre seu primeiro cartão"
                        description="Assim que houver um cartão, seus parcelamentos aparecem aqui com a projeção dos próximos meses."
                        action={
                            <Button asChild>
                                <Link href={cards.create()}>Cadastrar cartão</Link>
                            </Button>
                        }
                    />
                </div>
            </>
        );
    }

    const hasFutureInstallments = projection.some((row) => row.total_cents > 0);

    return (
        <>
            <Head title="Dashboard" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Visão Geral</h1>
                        <p className="text-sm text-muted-foreground">Sua saúde financeira nos próximos 6 meses</p>
                    </div>
                    <Button asChild>
                        <Link href={purchases.create()}>Novo lançamento</Link>
                    </Button>
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader><CardTitle className="text-sm font-medium text-muted-foreground">Total de cartões</CardTitle></CardHeader>
                        <CardContent><p className="text-3xl font-bold tabular-nums">{summary.cards_count}</p></CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-sm font-medium text-muted-foreground">Parcelas em aberto</CardTitle></CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold tabular-nums">{summary.open_installments_count}</p>
                            {summary.next_due_date && (
                                <p className="text-xs text-muted-foreground">
                                    Próximo vencimento {formatDate(summary.next_due_date)} · {formatBRL(summary.next_due_cents)}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-sm font-medium text-muted-foreground">Valor total devido</CardTitle></CardHeader>
                        <CardContent><p className="text-3xl font-bold tabular-nums">{formatBRL(summary.total_due_cents)}</p></CardContent>
                    </Card>
                </div>

                {hasFutureInstallments && (
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <Card>
                            <CardHeader><CardTitle>Projeção 6 meses</CardTitle></CardHeader>
                            <CardContent><ProjectionChart months={projection} /></CardContent>
                        </Card>

                        <Card>
                            <CardHeader><CardTitle>Resumo mensal</CardTitle></CardHeader>
                            <CardContent>
                                <ul className="divide-y">
                                    {projection.map((row, index) => (
                                        <li key={row.month} className="flex items-center justify-between gap-3 py-3">
                                            <div>
                                                <p className="text-sm font-medium capitalize">{formatMonthLabel(row.month)}</p>
                                                <p className="text-xs text-muted-foreground tabular-nums">{formatBRL(row.total_cents)}</p>
                                            </div>
                                            {index === 0 && <Badge>Atual</Badge>}
                                            {index === 1 && <Badge variant="secondary">Próximo</Badge>}
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
```

- [ ] **Step 10: Verificar o front e ver no navegador**

```bash
npm run build
npm run lint:check
npm run format:check
npm run types:check
composer run dev
```

Abrir `/dashboard` sem cartão (estado vazio), com cartão e sem compras (cards zerados, sem gráfico), e com algumas compras lançadas (gráfico e resumo mensal).

- [ ] **Step 11: Formatar, checar e commitar**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact
git add app routes resources tests package.json package-lock.json
git commit -m "$(cat <<'EOF'
feat: dashboard com resumo e projeção de 6 meses

Actions de agregação, controller e tela. Gráfico de barras com
recharts sobre o componente chart do shadcn.

Claude-Session: https://claude.ai/code/session_0156ogwsuLM3WTFASr1rELTR
EOF
)"
```

---

## Task 7: Meus Cartões — comprometido, disponível e parcelamento em destaque

Enriquece a tela criada na Task 4, agora que existem compras no sistema.

**Files:**
- Create: `app/Actions/BuildCardsOverview.php`
- Modify: `app/Http/Controllers/CardController.php` (método `index`)
- Modify: `resources/js/types/parcelamentos.ts`
- Modify: `resources/js/pages/cards/index.tsx`
- Test: `tests/Feature/CardsOverviewTest.php`

**Interfaces:**
- Consumes: `App\Models\Card`, `Installment` (Task 2); `Installment::scopeOpen` (Task 2).
- Produces:
  - `BuildCardsOverview::handle(User $user): array<int, CardOverviewRow>` onde `CardOverviewRow` é
    `array{id: int, name: string, brand: string, brand_label: string, label: string|null, last_four_digits: string, credit_limit_cents: int, closing_day: int, due_day: int, committed_cents: int, available_cents: int, committed_ratio: float, next_due_date: string|null, next_due_cents: int, featured: array{description: string, merchant: string|null, total_amount_cents: int, paid_cents: int, remaining_cents: int, current_number: int, installments_count: int}|null}`

Regra do parcelamento em destaque, conforme a spec: entre as compras do cartão que ainda têm parcela em aberto, a de **maior valor restante**; empate resolvido pela compra mais recente (`purchased_at` maior, depois `id` maior). `current_number` é o número da primeira parcela em aberto.

- [ ] **Step 1: Escrever o teste**

Criar `tests/Feature/CardsOverviewTest.php`:

```php
<?php

use App\Actions\BuildCardsOverview;
use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

it('calcula comprometido, disponivel e proporcao', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['credit_limit_cents' => 1_000_00]);
    $purchase = Purchase::factory()->for($card)->create();

    Installment::factory()->for($purchase)->create(['number' => 1, 'due_date' => '2026-06-10', 'amount_cents' => 500_00]);
    Installment::factory()->for($purchase)->create(['number' => 2, 'due_date' => '2026-08-10', 'amount_cents' => 250_00]);

    $overview = app(BuildCardsOverview::class)->handle($user);

    expect($overview[0]['committed_cents'])->toBe(250_00)
        ->and($overview[0]['available_cents'])->toBe(750_00)
        ->and($overview[0]['committed_ratio'])->toBe(0.25);
});

it('agrupa o proximo vencimento por data', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $a = Purchase::factory()->for($card)->create();
    $b = Purchase::factory()->for($card)->create();

    Installment::factory()->for($a)->create(['number' => 1, 'due_date' => '2026-08-10', 'amount_cents' => 100_00]);
    Installment::factory()->for($b)->create(['number' => 1, 'due_date' => '2026-08-10', 'amount_cents' => 200_00]);
    Installment::factory()->for($b)->create(['number' => 2, 'due_date' => '2026-09-10', 'amount_cents' => 900_00]);

    $overview = app(BuildCardsOverview::class)->handle($user);

    expect($overview[0]['next_due_date'])->toBe('2026-08-10')
        ->and($overview[0]['next_due_cents'])->toBe(300_00);
});

it('destaca o parcelamento com maior valor restante', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $small = Purchase::factory()->for($card)->create(['description' => 'Tênis', 'total_amount_cents' => 300_00]);
    Installment::factory()->for($small)->create(['number' => 1, 'due_date' => '2026-08-10', 'amount_cents' => 300_00]);

    $big = Purchase::factory()->for($card)->create(['description' => 'MacBook', 'total_amount_cents' => 1_200_00]);
    Installment::factory()->for($big)->create(['number' => 1, 'due_date' => '2026-06-10', 'amount_cents' => 600_00]);
    Installment::factory()->for($big)->create(['number' => 2, 'due_date' => '2026-08-10', 'amount_cents' => 600_00]);

    $featured = app(BuildCardsOverview::class)->handle($user)[0]['featured'];

    expect($featured['description'])->toBe('MacBook')
        ->and($featured['remaining_cents'])->toBe(600_00)
        ->and($featured['paid_cents'])->toBe(600_00)
        ->and($featured['current_number'])->toBe(2)
        ->and($featured['installments_count'])->toBe(2);
});

it('desempata pelo parcelamento mais recente', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $older = Purchase::factory()->for($card)->create(['description' => 'Antigo', 'purchased_at' => '2026-01-10']);
    Installment::factory()->for($older)->create(['number' => 1, 'due_date' => '2026-08-10', 'amount_cents' => 500_00]);

    $newer = Purchase::factory()->for($card)->create(['description' => 'Recente', 'purchased_at' => '2026-05-10']);
    Installment::factory()->for($newer)->create(['number' => 1, 'due_date' => '2026-08-10', 'amount_cents' => 500_00]);

    expect(app(BuildCardsOverview::class)->handle($user)[0]['featured']['description'])->toBe('Recente');
});

it('nao destaca nada quando tudo ja foi pago', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();
    $purchase = Purchase::factory()->for($card)->create();

    Installment::factory()->for($purchase)->create(['number' => 1, 'due_date' => '2026-06-10', 'amount_cents' => 500_00]);

    $overview = app(BuildCardsOverview::class)->handle($user);

    expect($overview[0]['featured'])->toBeNull()
        ->and($overview[0]['next_due_date'])->toBeNull()
        ->and($overview[0]['committed_cents'])->toBe(0);
});

it('devolve cartao sem compras com tudo zerado', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['credit_limit_cents' => 500_00]);

    $overview = app(BuildCardsOverview::class)->handle($user);

    expect($overview[0]['committed_cents'])->toBe(0)
        ->and($overview[0]['available_cents'])->toBe(500_00)
        ->and($overview[0]['committed_ratio'])->toBe(0.0)
        ->and($overview[0]['featured'])->toBeNull();
});

it('nao vaza cartoes de outro usuario', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();
    Card::factory()->count(3)->create();

    expect(app(BuildCardsOverview::class)->handle($user))->toHaveCount(1);
});

it('entrega a visao enriquecida para a tela', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('cards.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('cards/index')
            ->has('cards.0.committed_cents')
            ->has('cards.0.available_cents'));
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter=CardsOverview`
Expected: FAIL com `Target class [App\Actions\BuildCardsOverview] does not exist`.

- [ ] **Step 3: Implementar a visão**

Run: `php artisan make:class Actions/BuildCardsOverview --no-interaction`

```php
<?php

namespace App\Actions;

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Collection;

class BuildCardsOverview
{
    /**
     * Visao por cartao da tela Meus Cartoes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(User $user): array
    {
        $cards = $user->cards()
            ->with(['purchases.installments'])
            ->orderBy('name')
            ->get();

        return $cards->map(fn (Card $card): array => $this->forCard($card))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function forCard(Card $card): array
    {
        /** @var Collection<int, Installment> $open */
        $open = $card->purchases
            ->flatMap(fn (Purchase $purchase): Collection => $purchase->installments)
            ->filter(fn (Installment $installment): bool => $installment->status->isOpen());

        $committed = (int) $open->sum('amount_cents');
        $nextDueDate = $open->min('due_date');

        return [
            'id' => $card->id,
            'name' => $card->name,
            'brand' => $card->brand->value,
            'brand_label' => $card->brand->label(),
            'label' => $card->label,
            'last_four_digits' => $card->last_four_digits,
            'credit_limit_cents' => $card->credit_limit_cents,
            'closing_day' => $card->closing_day,
            'due_day' => $card->due_day,
            'committed_cents' => $committed,
            'available_cents' => $card->credit_limit_cents - $committed,
            'committed_ratio' => $card->credit_limit_cents > 0
                ? round($committed / $card->credit_limit_cents, 4)
                : 0.0,
            'next_due_date' => $nextDueDate?->toDateString(),
            'next_due_cents' => $nextDueDate === null
                ? 0
                : (int) $open->where('due_date', $nextDueDate)->sum('amount_cents'),
            'featured' => $this->featured($card),
        ];
    }

    /**
     * O parcelamento ativo com maior valor restante; empate pelo mais recente.
     *
     * @return array<string, mixed>|null
     */
    private function featured(Card $card): ?array
    {
        $candidates = $card->purchases
            ->map(function (Purchase $purchase): ?array {
                $open = $purchase->installments
                    ->filter(fn (Installment $installment): bool => $installment->status->isOpen());

                if ($open->isEmpty()) {
                    return null;
                }

                return [
                    'purchase' => $purchase,
                    'remaining_cents' => (int) $open->sum('amount_cents'),
                    'current_number' => (int) $open->min('number'),
                ];
            })
            ->filter()
            ->sortBy([
                ['remaining_cents', 'desc'],
                fn (array $a, array $b): int => $b['purchase']->purchased_at <=> $a['purchase']->purchased_at
                    ?: $b['purchase']->id <=> $a['purchase']->id,
            ]);

        $winner = $candidates->first();

        if ($winner === null) {
            return null;
        }

        /** @var Purchase $purchase */
        $purchase = $winner['purchase'];

        return [
            'description' => $purchase->description,
            'merchant' => $purchase->merchant,
            'total_amount_cents' => $purchase->total_amount_cents,
            'paid_cents' => $purchase->total_amount_cents - $winner['remaining_cents'],
            'remaining_cents' => $winner['remaining_cents'],
            'current_number' => $winner['current_number'],
            'installments_count' => $purchase->installments_count,
        ];
    }
}
```

O `with(['purchases.installments'])` é obrigatório: sem ele, um usuário com 8 cartões dispara dezenas de queries. Com ele são três.

- [ ] **Step 4: Trocar o `index` do controller**

Em `app/Http/Controllers/CardController.php`, substituir o corpo de `index` por:

```php
public function index(Request $request, BuildCardsOverview $buildCardsOverview): Response
{
    return Inertia::render('cards/index', [
        'cards' => $buildCardsOverview->handle($request->user()),
    ]);
}
```

Acrescentar `use App\Actions\BuildCardsOverview;` e remover o `use App\Models\Card;` se ficar sem uso no arquivo (o binding de rota ainda precisa dele nos outros métodos, então provavelmente permanece).

- [ ] **Step 5: Rodar e ver passar**

Run: `php artisan test --compact --filter="CardsOverview|CardTest"`
Expected: PASS. O teste `lista apenas os cartoes do usuario autenticado` da Task 4 continua válido.

- [ ] **Step 6: Atualizar o tipo no cliente**

Em `resources/js/types/parcelamentos.ts`, acrescentar:

```ts
export type FeaturedPurchase = {
    description: string;
    merchant: string | null;
    total_amount_cents: number;
    paid_cents: number;
    remaining_cents: number;
    current_number: number;
    installments_count: number;
};

export type CardOverview = CardSummary & {
    committed_cents: number;
    available_cents: number;
    committed_ratio: number;
    next_due_date: string | null;
    next_due_cents: number;
    featured: FeaturedPurchase | null;
};
```

- [ ] **Step 7: Enriquecer a tela**

Em `resources/js/pages/cards/index.tsx`, trocar o tipo do prop de `CardSummary[]` para `CardOverview[]`, acrescentar a faixa de totais acima da grade e o bloco de destaque dentro de cada cartão.

Faixa de totais, logo abaixo do cabeçalho:

```tsx
const totals = cards.reduce(
    (acc, card) => ({
        committed: acc.committed + card.committed_cents,
        limit: acc.limit + card.credit_limit_cents,
    }),
    { committed: 0, limit: 0 },
);

const ratio = totals.limit > 0 ? totals.committed / totals.limit : 0;
```

```tsx
{cards.length > 0 && (
    <Card>
        <CardContent className="space-y-2 pt-6">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Comprometido total</p>
            <p className="text-3xl font-bold tabular-nums">{formatBRL(totals.committed)}</p>
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                <div className="h-full bg-primary" style={{ width: `${Math.min(ratio * 100, 100)}%` }} />
            </div>
            <div className="flex justify-between text-xs text-muted-foreground tabular-nums">
                <span>Disponível {formatBRL(totals.limit - totals.committed)}</span>
                <span>{Math.round(ratio * 100)}% de {formatBRL(totals.limit)}</span>
            </div>
        </CardContent>
    </Card>
)}
```

Dentro do `<CardContent>` de cada cartão, entre a lista de dados e os botões:

```tsx
{card.featured ? (
    <div className="space-y-2 rounded-md bg-muted/50 p-3">
        <div className="flex items-baseline justify-between gap-2">
            <p className="text-sm font-medium">{card.featured.description}</p>
            <span className="text-xs text-muted-foreground tabular-nums">
                {card.featured.current_number} de {card.featured.installments_count}
            </span>
        </div>
        <div className="h-1.5 w-full overflow-hidden rounded-full bg-background">
            <div
                className="h-full bg-primary"
                style={{ width: `${(card.featured.paid_cents / card.featured.total_amount_cents) * 100}%` }}
            />
        </div>
        <div className="flex justify-between text-xs tabular-nums">
            <span className="text-muted-foreground">Pago {formatBRL(card.featured.paid_cents)}</span>
            <span>Restante {formatBRL(card.featured.remaining_cents)}</span>
        </div>
    </div>
) : (
    <p className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">Sem parcelamentos em aberto.</p>
)}

{card.next_due_date && (
    <p className="text-xs text-muted-foreground">
        Próxima fatura {formatDate(card.next_due_date)} · {formatBRL(card.next_due_cents)}
    </p>
)}
```

O terceiro botão da linha de ações passa a ser o link para o histórico filtrado — a rota `history.index` só existe na Task 8, então este botão entra lá. Por ora, manter apenas Editar e Remover.

Ajustar os imports: `formatDate` de `@/lib/currency`, `CardOverview` de `@/types`.

- [ ] **Step 8: Verificar o front e commitar**

```bash
npm run build && npm run lint:check && npm run format:check && npm run types:check
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact
git add app resources tests
git commit -m "$(cat <<'EOF'
feat: visão de comprometido e destaque por cartão

BuildCardsOverview calcula comprometido, disponível, próxima fatura e
o parcelamento ativo de maior valor restante por cartão.

Claude-Session: https://claude.ai/code/session_0156ogwsuLM3WTFASr1rELTR
EOF
)"
```

---

## Task 8: Histórico — tabela filtrável, paginação e dica de finanças

**Files:**
- Modify: `app/Actions/BuildDashboardSummary.php` (acrescentar `paid_this_month_cents`)
- Modify: `tests/Feature/DashboardTest.php` (o teste do resumo zerado assere a forma exata do array)
- Create: `app/Actions/BuildFinanceTip.php`
- Create: `app/Http/Controllers/HistoryController.php`
- Modify: `routes/parcelamentos.php`, `resources/js/components/app-sidebar.tsx`, `resources/js/pages/cards/index.tsx`
- Create: `resources/js/components/status-chip.tsx`, `resources/js/pages/history/index.tsx`
- Modify: `resources/js/types/parcelamentos.ts`
- Test: `tests/Feature/HistoryTest.php`, `tests/Feature/FinanceTipTest.php`

**Interfaces:**
- Consumes: `Installment` com `forUser`/`open`/`paid` e accessor `status` (Task 2); `BuildDashboardSummary` (Task 6); `EmptyState` (Task 4).
- Produces:
  - Rota nomeada `history.index`, aceitando query string `month` (`YYYY-MM` ou `all`), `status` (`paid|upcoming|pending|all`), `card` (id ou `all`)
  - `BuildFinanceTip::handle(User $user): array{count: int, freed_cents: int, next_month: string}|null`
  - `BuildDashboardSummary` passa a devolver também `paid_this_month_cents: int`
  - Componente `StatusChip` com prop `{ status: 'paid' | 'upcoming' | 'pending' }`

O histórico reaproveita `BuildDashboardSummary` para os três cards do topo em vez de recalcular — a spec exige que nenhuma métrica seja computada de duas formas.

- [ ] **Step 1: Escrever o teste da dica de finanças**

Criar `tests/Feature/FinanceTipTest.php`:

```php
<?php

use App\Actions\BuildFinanceTip;
use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

function lastInstallmentDueOn(User $user, string $dueDate, int $amountCents, int $count = 3): Purchase
{
    $purchase = Purchase::factory()
        ->for(Card::factory()->for($user))
        ->create(['installments_count' => $count]);

    Installment::factory()->for($purchase)->create([
        'number' => $count,
        'due_date' => $dueDate,
        'amount_cents' => $amountCents,
    ]);

    return $purchase;
}

it('devolve null quando nenhum parcelamento termina este mes', function (): void {
    $user = User::factory()->create();
    lastInstallmentDueOn($user, '2026-09-10', 100_00);

    expect(app(BuildFinanceTip::class)->handle($user))->toBeNull();
});

it('conta os parcelamentos que terminam no mes corrente', function (): void {
    $user = User::factory()->create();
    lastInstallmentDueOn($user, '2026-07-20', 200_00);
    lastInstallmentDueOn($user, '2026-07-28', 480_00);

    $tip = app(BuildFinanceTip::class)->handle($user);

    expect($tip['count'])->toBe(2)
        ->and($tip['freed_cents'])->toBe(680_00)
        ->and($tip['next_month'])->toBe('2026-08');
});

it('ignora parcelas do meio do parcelamento', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create(['installments_count' => 5]);

    Installment::factory()->for($purchase)->create(['number' => 2, 'due_date' => '2026-07-20', 'amount_cents' => 100_00]);

    expect(app(BuildFinanceTip::class)->handle($user))->toBeNull();
});

it('ignora parcelamentos de outro usuario', function (): void {
    $user = User::factory()->create();
    lastInstallmentDueOn(User::factory()->create(), '2026-07-20', 500_00);

    expect(app(BuildFinanceTip::class)->handle($user))->toBeNull();
});

it('atravessa a virada de ano no mes seguinte', function (): void {
    $this->travelTo('2026-12-15');
    $user = User::factory()->create();
    lastInstallmentDueOn($user, '2026-12-20', 100_00);

    expect(app(BuildFinanceTip::class)->handle($user)['next_month'])->toBe('2027-01');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter=FinanceTip`
Expected: FAIL com `Target class [App\Actions\BuildFinanceTip] does not exist`.

- [ ] **Step 3: Implementar a dica**

Run: `php artisan make:class Actions/BuildFinanceTip --no-interaction`

```php
<?php

namespace App\Actions;

use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class BuildFinanceTip
{
    /**
     * Parcelamentos cuja ultima parcela vence no mes corrente.
     *
     * @return array{count: int, freed_cents: int, next_month: string}|null
     */
    public function handle(User $user): ?array
    {
        $now = CarbonImmutable::now();

        $installments = Installment::query()
            ->forUser($user)
            ->whereBetween('due_date', [
                $now->startOfMonth()->toDateString(),
                $now->endOfMonth()->toDateString(),
            ])
            ->whereHas(
                'purchase',
                fn (Builder $purchases) => $purchases->whereColumn('purchases.installments_count', 'installments.number'),
            )
            ->get(['amount_cents']);

        if ($installments->isEmpty()) {
            return null;
        }

        return [
            'count' => $installments->count(),
            'freed_cents' => (int) $installments->sum('amount_cents'),
            'next_month' => $now->addMonthNoOverflow()->format('Y-m'),
        ];
    }
}
```

`whereColumn('purchases.installments_count', 'installments.number')` é o que identifica a última parcela sem precisar de subquery de máximo.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --compact --filter=FinanceTip`
Expected: PASS, 5 testes.

- [ ] **Step 5: Acrescentar `paid_this_month_cents` ao resumo**

Em `app/Actions/BuildDashboardSummary.php`, acrescentar ao array devolvido e à PHPDoc:

```php
'paid_this_month_cents' => (int) Installment::query()
    ->forUser($user)
    ->paid()
    ->whereBetween('due_date', [
        CarbonImmutable::now()->startOfMonth()->toDateString(),
        CarbonImmutable::now()->endOfMonth()->toDateString(),
    ])
    ->sum('amount_cents'),
```

Com `use Carbon\CarbonImmutable;` no topo. A PHPDoc do retorno ganha `paid_this_month_cents: int`.

Em `tests/Feature/DashboardTest.php`, o teste `devolve resumo zerado quando nao ha nada` passa a esperar:

```php
expect($summary)->toBe([
    'cards_count' => 0,
    'open_installments_count' => 0,
    'total_due_cents' => 0,
    'next_due_date' => null,
    'next_due_cents' => 0,
    'paid_this_month_cents' => 0,
]);
```

E acrescentar um teste novo:

```php
it('soma o que ja venceu no mes corrente', function (): void {
    $user = User::factory()->create();
    installmentOn($user, '2026-07-01', 245_00);
    installmentOn($user, '2026-07-20', 100_00);
    installmentOn($user, '2026-06-01', 999_00);

    expect(app(BuildDashboardSummary::class)->handle($user)['paid_this_month_cents'])->toBe(245_00);
});
```

Também acrescentar `paid_this_month_cents` ao tipo `DashboardSummary` em `resources/js/types/parcelamentos.ts`.

- [ ] **Step 6: Escrever o teste do histórico**

Criar `tests/Feature/HistoryTest.php`:

```php
<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

function historyUser(): User
{
    return User::factory()->create();
}

function installmentFor(User $user, string $dueDate, int $amountCents, ?Card $card = null): Installment
{
    $purchase = Purchase::factory()->for($card ?? Card::factory()->for($user))->create();

    return Installment::factory()->for($purchase)->create([
        'number' => 1,
        'due_date' => $dueDate,
        'amount_cents' => $amountCents,
    ]);
}

it('filtra pelo mes corrente por padrao', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-10', 100_00);
    installmentFor($user, '2026-08-10', 100_00);

    $this->actingAs($user)
        ->get(route('history.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('history/index')
            ->has('installments.data', 1)
            ->where('filters.month', '2026-07'));
});

it('mostra todos os meses quando pedido', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-10', 100_00);
    installmentFor($user, '2026-08-10', 100_00);
    installmentFor($user, '2025-01-10', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['month' => 'all']))
        ->assertInertia(fn ($page) => $page->has('installments.data', 3));
});

it('filtra por status', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-06-10', 100_00);
    installmentFor($user, '2026-07-20', 100_00);
    installmentFor($user, '2026-09-10', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['month' => 'all', 'status' => 'paid']))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1)
            ->where('installments.data.0.status', 'paid'));

    $this->actingAs($user)
        ->get(route('history.index', ['month' => 'all', 'status' => 'upcoming']))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));

    $this->actingAs($user)
        ->get(route('history.index', ['month' => 'all', 'status' => 'pending']))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));
});

it('filtra por cartao', function (): void {
    $user = historyUser();
    $card = Card::factory()->for($user)->create();
    installmentFor($user, '2026-07-10', 100_00, $card);
    installmentFor($user, '2026-07-10', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['card' => $card->id]))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));
});

it('pagina em vinte e preserva os filtros', function (): void {
    $user = historyUser();
    $card = Card::factory()->for($user)->create();

    for ($i = 1; $i <= 25; $i++) {
        installmentFor($user, '2026-07-10', 100_00, $card);
    }

    $this->actingAs($user)
        ->get(route('history.index', ['card' => $card->id, 'page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('installments.data', 5)
            ->where('installments.total', 25));
});

it('ordena por vencimento ascendente', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-28', 100_00);
    installmentFor($user, '2026-07-02', 100_00);

    $this->actingAs($user)
        ->get(route('history.index'))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.due_date', '2026-07-02'));
});

it('nao vaza parcelas de outro usuario', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-10', 100_00);
    installmentFor(historyUser(), '2026-07-10', 999_00);

    $this->actingAs($user)
        ->get(route('history.index'))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));
});

it('mantem os totais do topo globais, sem responder ao filtro', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-20', 100_00);
    installmentFor($user, '2026-11-10', 700_00);

    $this->actingAs($user)
        ->get(route('history.index', ['month' => '2026-07']))
        ->assertInertia(fn ($page) => $page
            ->has('installments.data', 1)
            ->where('summary.total_due_cents', 800_00));
});

it('exige autenticacao', function (): void {
    $this->get(route('history.index'))->assertRedirect(route('login'));
});
```

O último teste de verdade — "totais do topo globais" — é o que trava a regra da spec de que filtro é da tabela, não dos cards.

- [ ] **Step 7: Rodar e ver falhar**

Run: `php artisan test --compact --filter=HistoryTest`
Expected: FAIL com `Route [history.index] not defined`.

- [ ] **Step 8: Implementar o controller**

Run: `php artisan make:controller HistoryController --no-interaction`

```php
<?php

namespace App\Http\Controllers;

use App\Actions\BuildDashboardSummary;
use App\Actions\BuildFinanceTip;
use App\Actions\BuildMonthlyProjection;
use App\Enums\InstallmentStatus;
use App\Models\Card;
use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function index(
        Request $request,
        BuildDashboardSummary $buildDashboardSummary,
        BuildMonthlyProjection $buildMonthlyProjection,
        BuildFinanceTip $buildFinanceTip,
    ): Response {
        $user = $request->user();
        $filters = $this->filters($request);

        $installments = $this->query($user, $filters)
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Installment $installment): array => [
                'id' => $installment->id,
                'number' => $installment->number,
                'amount_cents' => $installment->amount_cents,
                'due_date' => $installment->due_date->toDateString(),
                'status' => $installment->status->value,
                'purchase' => [
                    'id' => $installment->purchase->id,
                    'description' => $installment->purchase->description,
                    'merchant' => $installment->purchase->merchant,
                    'total_amount_cents' => $installment->purchase->total_amount_cents,
                    'installments_count' => $installment->purchase->installments_count,
                ],
                'card' => [
                    'name' => $installment->purchase->card->name,
                    'last_four_digits' => $installment->purchase->card->last_four_digits,
                ],
            ]);

        return Inertia::render('history/index', [
            'installments' => $installments,
            'filters' => $filters,
            'summary' => $buildDashboardSummary->handle($user),
            'projection' => $buildMonthlyProjection->handle($user),
            'tip' => $buildFinanceTip->handle($user),
            'cards' => $user->cards()->orderBy('name')->get()
                ->map(fn (Card $card): array => [
                    'id' => $card->id,
                    'name' => $card->name,
                    'last_four_digits' => $card->last_four_digits,
                ]),
            'months' => $this->monthOptions($user),
        ]);
    }

    /**
     * @return array{month: string, status: string, card: string}
     */
    private function filters(Request $request): array
    {
        return [
            'month' => (string) $request->query('month', CarbonImmutable::now()->format('Y-m')),
            'status' => (string) $request->query('status', 'all'),
            'card' => (string) $request->query('card', 'all'),
        ];
    }

    /**
     * @param  array{month: string, status: string, card: string}  $filters
     * @return Builder<Installment>
     */
    private function query(User $user, array $filters): Builder
    {
        $query = Installment::query()
            ->forUser($user)
            ->with(['purchase.card']);

        if ($filters['month'] !== 'all' && preg_match('/^\d{4}-\d{2}$/', $filters['month']) === 1) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $filters['month'].'-01');

            $query->whereBetween('due_date', [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ]);
        }

        if ($filters['card'] !== 'all') {
            $query->whereHas(
                'purchase',
                fn ($purchases) => $purchases->where('card_id', (int) $filters['card']),
            );
        }

        $today = CarbonImmutable::now();

        match (InstallmentStatus::tryFrom($filters['status'])) {
            InstallmentStatus::Paid => $query->whereDate('due_date', '<', $today->toDateString()),
            InstallmentStatus::Upcoming => $query
                ->whereDate('due_date', '>=', $today->toDateString())
                ->whereDate('due_date', '<=', $today->endOfMonth()->toDateString()),
            InstallmentStatus::Pending => $query->whereDate('due_date', '>', $today->endOfMonth()->toDateString()),
            null => null,
        };

        return $query;
    }

    /**
     * Meses que efetivamente tem parcelas, para popular o filtro.
     *
     * @return array<int, string>
     */
    private function monthOptions(User $user): array
    {
        return Installment::query()
            ->forUser($user)
            ->orderBy('due_date')
            ->pluck('due_date')
            ->map(fn (CarbonImmutable $date): string => $date->format('Y-m'))
            ->unique()
            ->values()
            ->all();
    }
}
```

O `match` sobre `InstallmentStatus::tryFrom` traduz cada status para o intervalo de datas equivalente — é a mesma regra do enum, expressa em SQL. Um valor inválido na query string cai em `null` e não filtra nada, em vez de estourar.

- [ ] **Step 9: Acrescentar a rota e o item de menu**

Em `routes/parcelamentos.php`:

```php
Route::get('historico', [HistoryController::class, 'index'])->name('history.index');
```

Em `app-sidebar.tsx`, acrescentar ao `mainNavItems`:

```tsx
{
    title: 'Histórico',
    href: history.index(),
    icon: History,
},
```

Com `import history from '@/routes/history';` e `History` no import de `lucide-react`.

- [ ] **Step 10: Rodar e ver passar**

Run: `php artisan test --compact --filter=HistoryTest`
Expected: PASS, 9 testes.

- [ ] **Step 11: Criar o chip de status e a tela**

Criar `resources/js/components/status-chip.tsx`:

```tsx
import { Badge } from '@/components/ui/badge';

const LABELS = {
    paid: 'Pago',
    upcoming: 'Próximo',
    pending: 'Pendente',
} as const;

const CLASSES = {
    paid: 'bg-secondary/15 text-secondary-foreground border-secondary/30',
    upcoming: 'bg-accent/15 text-accent-foreground border-accent/30',
    pending: 'bg-muted text-muted-foreground border-border',
} as const;

export function StatusChip({ status }: { status: keyof typeof LABELS }) {
    return (
        <Badge variant="outline" className={`rounded-full ${CLASSES[status]}`}>
            {LABELS[status]}
        </Badge>
    );
}
```

Acrescentar a `resources/js/types/parcelamentos.ts`:

```ts
export type InstallmentStatus = 'paid' | 'upcoming' | 'pending';

export type HistoryRow = {
    id: number;
    number: number;
    amount_cents: number;
    due_date: string;
    status: InstallmentStatus;
    purchase: {
        id: number;
        description: string;
        merchant: string | null;
        total_amount_cents: number;
        installments_count: number;
    };
    card: {
        name: string;
        last_four_digits: string;
    };
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export type HistoryFilters = {
    month: string;
    status: string;
    card: string;
};

export type FinanceTip = {
    count: number;
    freed_cents: number;
    next_month: string;
};
```

Criar `resources/js/pages/history/index.tsx`:

```tsx
import { Head, Link, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { ProjectionChart } from '@/components/projection-chart';
import { StatusChip } from '@/components/status-chip';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBRL, formatDate, formatMonthLabel } from '@/lib/currency';
import { index } from '@/routes/history';
import purchases from '@/routes/purchases';
import type {
    CardOption,
    DashboardSummary,
    FinanceTip,
    HistoryFilters,
    HistoryRow,
    MonthlyProjectionRow,
    Paginated,
} from '@/types';

type HistoryProps = {
    installments: Paginated<HistoryRow>;
    filters: HistoryFilters;
    summary: DashboardSummary;
    projection: MonthlyProjectionRow[];
    tip: FinanceTip | null;
    cards: Pick<CardOption, 'id' | 'name' | 'last_four_digits'>[];
    months: string[];
};

export default function History({ installments, filters, summary, projection, tip, cards, months }: HistoryProps) {
    function applyFilter(key: keyof HistoryFilters, value: string) {
        router.get(index().url, { ...filters, [key]: value }, { preserveState: true, replace: true });
    }

    function removePurchase(row: HistoryRow) {
        const message = `Remover "${row.purchase.description}"? Isso apaga as ${row.purchase.installments_count} parcelas dessa compra.`;

        if (window.confirm(message)) {
            router.delete(purchases.destroy(row.purchase.id).url, { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Histórico de Parcelas" />

            <div className="space-y-6 p-4">
                <h1 className="text-2xl font-semibold tracking-tight">Histórico de Parcelas</h1>

                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader><CardTitle className="text-sm font-medium text-muted-foreground">Total em parcelas</CardTitle></CardHeader>
                        <CardContent><p className="text-2xl font-bold tabular-nums">{formatBRL(summary.total_due_cents)}</p></CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle className="text-sm font-medium text-muted-foreground">Pago este mês</CardTitle></CardHeader>
                        <CardContent><p className="text-2xl font-bold tabular-nums">{formatBRL(summary.paid_this_month_cents)}</p></CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle className="text-sm font-medium text-muted-foreground">Próximo vencimento</CardTitle></CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold tabular-nums">{formatBRL(summary.next_due_cents)}</p>
                            {summary.next_due_date && (
                                <p className="text-xs text-muted-foreground">Vence em {formatDate(summary.next_due_date)}</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap gap-3">
                    <Select value={filters.month} onValueChange={(value) => applyFilter('month', value)}>
                        <SelectTrigger className="w-[200px]"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos os meses</SelectItem>
                            {months.map((month) => (
                                <SelectItem key={month} value={month} className="capitalize">
                                    {formatMonthLabel(month)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.status} onValueChange={(value) => applyFilter('status', value)}>
                        <SelectTrigger className="w-[180px]"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos os status</SelectItem>
                            <SelectItem value="paid">Pago</SelectItem>
                            <SelectItem value="upcoming">Próximo</SelectItem>
                            <SelectItem value="pending">Pendente</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select value={filters.card} onValueChange={(value) => applyFilter('card', value)}>
                        <SelectTrigger className="w-[220px]"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos os cartões</SelectItem>
                            {cards.map((card) => (
                                <SelectItem key={card.id} value={String(card.id)}>
                                    {card.name} •••• {card.last_four_digits}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {installments.data.length === 0 ? (
                    <EmptyState
                        title="Nenhuma parcela encontrada"
                        description="Ajuste os filtros ou registre um novo lançamento."
                        action={<Button asChild><Link href={purchases.create()}>Novo lançamento</Link></Button>}
                    />
                ) : (
                    <Card>
                        <CardContent className="overflow-x-auto p-0">
                            <table className="w-full text-sm">
                                <thead className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <tr>
                                        <th className="p-3">Item</th>
                                        <th className="p-3">Valor total</th>
                                        <th className="p-3">Cartão</th>
                                        <th className="p-3">Parcela</th>
                                        <th className="p-3">Vencimento</th>
                                        <th className="p-3">Status</th>
                                        <th className="p-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {installments.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="p-3">
                                                <p className="font-medium">{row.purchase.description}</p>
                                                {row.purchase.merchant && (
                                                    <p className="text-xs text-muted-foreground">{row.purchase.merchant}</p>
                                                )}
                                            </td>
                                            <td className="p-3 tabular-nums">{formatBRL(row.purchase.total_amount_cents)}</td>
                                            <td className="p-3">{row.card.name} •••• {row.card.last_four_digits}</td>
                                            <td className="p-3 tabular-nums">
                                                {row.number}/{row.purchase.installments_count}
                                                <span className="block text-xs text-muted-foreground">{formatBRL(row.amount_cents)}</span>
                                            </td>
                                            <td className="p-3 tabular-nums">{formatDate(row.due_date)}</td>
                                            <td className="p-3"><StatusChip status={row.status} /></td>
                                            <td className="p-3">
                                                <Button variant="ghost" size="sm" onClick={() => removePurchase(row)}>
                                                    <Trash2 />
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}

                {installments.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <p className="text-muted-foreground">
                            Mostrando {installments.data.length} de {installments.total} parcelas
                        </p>
                        <div className="flex gap-1">
                            {installments.links.map((link, position) => (
                                <Button
                                    key={position}
                                    variant={link.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={link.url === null}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <Card>
                        <CardHeader><CardTitle>Projeção de gastos</CardTitle></CardHeader>
                        <CardContent><ProjectionChart months={projection} /></CardContent>
                    </Card>

                    {tip && (
                        <Card>
                            <CardHeader><CardTitle>Dica de finanças</CardTitle></CardHeader>
                            <CardContent>
                                <p className="text-sm">
                                    Você tem <strong>{tip.count}</strong>{' '}
                                    {tip.count === 1 ? 'parcelamento que termina' : 'parcelamentos que terminam'} este mês,
                                    liberando <strong>{formatBRL(tip.freed_cents)}</strong> a partir de{' '}
                                    <span className="capitalize">{formatMonthLabel(tip.next_month)}</span>.
                                </p>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}

History.layout = {
    breadcrumbs: [{ title: 'Histórico', href: index() }],
};
```

- [ ] **Step 12: Ligar o "Detalhes" do cartão ao histórico filtrado**

Em `resources/js/pages/cards/index.tsx`, acrescentar o terceiro botão na linha de ações de cada cartão:

```tsx
<Button variant="outline" size="sm" asChild>
    <Link href={history.index({ query: { card: String(card.id), month: 'all' } })}>Detalhes</Link>
</Button>
```

Com `import history from '@/routes/history';`.

- [ ] **Step 13: Verificar tudo e commitar**

```bash
npm run build && npm run lint:check && npm run format:check && npm run types:check
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact
git add app routes resources tests
git commit -m "$(cat <<'EOF'
feat: histórico de parcelas com filtros e dica de finanças

Tabela paginada por parcela, filtros de mês, status e cartão, e a
regra única de dica sobre parcelamentos que terminam no mês.

Claude-Session: https://claude.ai/code/session_0156ogwsuLM3WTFASr1rELTR
EOF
)"
```

---

## Task 9: Exportação CSV

A montagem da query filtrada sai do `HistoryController` para uma Action, porque agora dois controllers precisam exatamente da mesma query. Se ela ficasse duplicada, o CSV divergiria da tela no primeiro filtro novo.

**Files:**
- Create: `app/Actions/BuildHistoryQuery.php`
- Modify: `app/Http/Controllers/HistoryController.php` (usar a Action; remover os métodos `filters` e `query` privados)
- Create: `app/Http/Controllers/ExportHistoryController.php`
- Modify: `routes/parcelamentos.php`, `resources/js/pages/history/index.tsx`
- Test: `tests/Feature/ExportHistoryTest.php`

**Interfaces:**
- Consumes: `Installment` com scope `forUser` (Task 2); `InstallmentStatus` (Task 2).
- Produces:
  - `BuildHistoryQuery::filters(Request $request): array{month: string, status: string, card: string}`
  - `BuildHistoryQuery::handle(User $user, array $filters): Builder<Installment>`
  - Rota nomeada `history.export`

- [ ] **Step 1: Extrair a Action**

Run: `php artisan make:class Actions/BuildHistoryQuery --no-interaction`

Mover para `app/Actions/BuildHistoryQuery.php` os métodos `filters()` e `query()` do `HistoryController`, tornando-os públicos e renomeando `query` para `handle`. O corpo é idêntico ao já escrito na Task 8, Step 8 — nada muda na lógica.

```php
<?php

namespace App\Actions;

use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class BuildHistoryQuery
{
    /**
     * @return array{month: string, status: string, card: string}
     */
    public function filters(Request $request): array
    {
        return [
            'month' => (string) $request->query('month', CarbonImmutable::now()->format('Y-m')),
            'status' => (string) $request->query('status', 'all'),
            'card' => (string) $request->query('card', 'all'),
        ];
    }

    /**
     * @param  array{month: string, status: string, card: string}  $filters
     * @return Builder<Installment>
     */
    public function handle(User $user, array $filters): Builder
    {
        $query = Installment::query()
            ->forUser($user)
            ->with(['purchase.card']);

        if ($filters['month'] !== 'all' && preg_match('/^\d{4}-\d{2}$/', $filters['month']) === 1) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $filters['month'].'-01');

            $query->whereBetween('due_date', [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ]);
        }

        if ($filters['card'] !== 'all') {
            $query->whereHas(
                'purchase',
                fn ($purchases) => $purchases->where('card_id', (int) $filters['card']),
            );
        }

        $today = CarbonImmutable::now();

        match (InstallmentStatus::tryFrom($filters['status'])) {
            InstallmentStatus::Paid => $query->whereDate('due_date', '<', $today->toDateString()),
            InstallmentStatus::Upcoming => $query
                ->whereDate('due_date', '>=', $today->toDateString())
                ->whereDate('due_date', '<=', $today->endOfMonth()->toDateString()),
            InstallmentStatus::Pending => $query->whereDate('due_date', '>', $today->endOfMonth()->toDateString()),
            null => null,
        };

        return $query;
    }
}
```

No `HistoryController`, injetar `BuildHistoryQuery $buildHistoryQuery` no `index`, trocar `$this->filters($request)` por `$buildHistoryQuery->filters($request)` e `$this->query($user, $filters)` por `$buildHistoryQuery->handle($user, $filters)`, e apagar os dois métodos privados.

- [ ] **Step 2: Rodar os testes do histórico**

Run: `php artisan test --compact --filter=HistoryTest`
Expected: PASS, 9 testes — a extração não muda comportamento nenhum.

- [ ] **Step 3: Escrever o teste da exportação**

Criar `tests/Feature/ExportHistoryTest.php`:

```php
<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

beforeEach(function (): void {
    $this->travelTo('2026-07-15');
});

function csvFrom(\Illuminate\Testing\TestResponse $response): string
{
    return $response->streamedContent();
}

function exportInstallment(User $user, string $dueDate, int $amountCents, ?Card $card = null): Installment
{
    $purchase = Purchase::factory()->for($card ?? Card::factory()->for($user))->create([
        'description' => 'MacBook Pro M2',
        'merchant' => 'Apple Store',
        'total_amount_cents' => 1_200_00,
        'installments_count' => 12,
    ]);

    return Installment::factory()->for($purchase)->create([
        'number' => 1,
        'due_date' => $dueDate,
        'amount_cents' => $amountCents,
    ]);
}

it('comeca com BOM e usa ponto e virgula', function (): void {
    $user = User::factory()->create();
    exportInstallment($user, '2026-07-10', 100_00);

    $response = $this->actingAs($user)->get(route('history.export'));
    $response->assertOk();

    $csv = csvFrom($response);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('cartao;descricao;estabelecimento;valor_total_compra;parcela;total_parcelas;valor_parcela;vencimento;status');
});

it('exporta uma linha por parcela do filtro ativo', function (): void {
    $user = User::factory()->create();
    exportInstallment($user, '2026-07-10', 100_00);
    exportInstallment($user, '2026-11-10', 100_00);

    $csv = csvFrom($this->actingAs($user)->get(route('history.export')));

    expect(substr_count(trim($csv), "\n"))->toBe(1);
});

it('respeita o filtro de todos os meses', function (): void {
    $user = User::factory()->create();
    exportInstallment($user, '2026-07-10', 100_00);
    exportInstallment($user, '2026-11-10', 100_00);

    $csv = csvFrom($this->actingAs($user)->get(route('history.export', ['month' => 'all'])));

    expect(substr_count(trim($csv), "\n"))->toBe(2);
});

it('escreve os campos com os valores corretos', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['name' => 'Nubank', 'last_four_digits' => '8291']);
    exportInstallment($user, '2026-07-10', 100_00, $card);

    $csv = csvFrom($this->actingAs($user)->get(route('history.export')));

    expect($csv)->toContain('Nubank •••• 8291;MacBook Pro M2;Apple Store;1200,00;1;12;100,00;10/07/2026;Pago');
});

it('nao exporta parcelas de outro usuario', function (): void {
    $user = User::factory()->create();
    exportInstallment(User::factory()->create(), '2026-07-10', 999_00);

    $csv = csvFrom($this->actingAs($user)->get(route('history.export', ['month' => 'all'])));

    expect(trim($csv))->not->toContain('999');
});

it('exige autenticacao', function (): void {
    $this->get(route('history.export'))->assertRedirect(route('login'));
});
```

A parcela de 10/07 com "hoje" em 15/07 tem status `Pago` — daí o valor esperado na última coluna.

- [ ] **Step 4: Rodar e ver falhar**

Run: `php artisan test --compact --filter=ExportHistory`
Expected: FAIL com `Route [history.export] not defined`.

- [ ] **Step 5: Implementar o controller de exportação**

Run: `php artisan make:controller ExportHistoryController --invokable --no-interaction`

```php
<?php

namespace App\Http\Controllers;

use App\Actions\BuildHistoryQuery;
use App\Models\Installment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportHistoryController extends Controller
{
    public function __invoke(Request $request, BuildHistoryQuery $buildHistoryQuery): StreamedResponse
    {
        $query = $buildHistoryQuery
            ->handle($request->user(), $buildHistoryQuery->filters($request))
            ->orderBy('due_date')
            ->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            // BOM: sem ele o Excel em pt-BR corrompe os acentos.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'cartao', 'descricao', 'estabelecimento', 'valor_total_compra',
                'parcela', 'total_parcelas', 'valor_parcela', 'vencimento', 'status',
            ], ';', '"', '');

            foreach ($query->cursor() as $installment) {
                fputcsv($handle, $this->row($installment), ';', '"', '');
            }

            fclose($handle);
        }, 'historico-parcelas.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function row(Installment $installment): array
    {
        $purchase = $installment->purchase;
        $card = $purchase->card;

        return [
            "{$card->name} •••• {$card->last_four_digits}",
            $purchase->description,
            $purchase->merchant ?? '',
            $this->money($purchase->total_amount_cents),
            (string) $installment->number,
            (string) $purchase->installments_count,
            $this->money($installment->amount_cents),
            $installment->due_date->format('d/m/Y'),
            $installment->status->label(),
        ];
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }
}
```

Os valores saem com vírgula decimal e **sem** separador de milhar: com `1.200,00` e separador `;`, o Excel em pt-BR ainda acerta, mas planilhas configuradas em outro locale quebram. `1200,00` é o formato que sobrevive aos dois.

- [ ] **Step 6: Acrescentar a rota e o botão**

Em `routes/parcelamentos.php`:

```php
Route::get('historico/exportar', ExportHistoryController::class)->name('history.export');
```

Atenção à ordem: esta rota tem que vir **depois** de `historico`, mas como não há parâmetro dinâmico conflitante, qualquer posição dentro do grupo funciona.

Na tela do histórico, ao lado do `<h1>`, acrescentar:

```tsx
<Button variant="outline" asChild>
    <a href={exportRoute({ query: filters }).url}>Exportar CSV</a>
</Button>
```

`export` é palavra reservada em JavaScript, então o Wayfinder não pode gerar uma função com esse nome como export nomeado. Importar o namespace inteiro e acessar pela propriedade:

```tsx
import history from '@/routes/history';

const exportRoute = history.export;
```

Se depois do `npm run build` o arquivo `resources/js/routes/history/index.ts` não tiver a propriedade `export`, renomear a rota para `history.download` em `routes/parcelamentos.php` e ajustar os testes que a referenciam por nome.

Usar `<a>` e não `<Link>`: é download de arquivo, não navegação Inertia.

O mesmo botão entra no dashboard, apontando para `exportRoute()` sem query — o "Exportar Relatório" do design do Stitch.

- [ ] **Step 7: Rodar e ver passar**

Run: `php artisan test --compact --filter=ExportHistory`
Expected: PASS, 6 testes.

- [ ] **Step 8: Baixar o arquivo de verdade**

Run: `composer run dev`

Abrir o histórico, clicar em Exportar CSV, abrir o arquivo baixado numa planilha e confirmar que as colunas estão separadas e os acentos corretos.

- [ ] **Step 9: Formatar, checar e commitar**

```bash
npm run build && npm run lint:check && npm run format:check && npm run types:check
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact
git add app routes resources tests
git commit -m "$(cat <<'EOF'
feat: exportação do histórico em CSV

Query filtrada extraída para BuildHistoryQuery e compartilhada entre a
tela e o export. Streaming com cursor, BOM UTF-8 e separador ponto e
vírgula para abrir corretamente no Excel em pt-BR.

Claude-Session: https://claude.ai/code/session_0156ogwsuLM3WTFASr1rELTR
EOF
)"
```

---

## Task 10: Tema visual e seeder

**Files:**
- Modify: `resources/css/app.css`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/DatabaseSeederTest.php`

**Interfaces:**
- Consumes: `Card`, `Purchase` factories com o state `parcelada` (Task 2).
- Produces: nenhuma API nova. O seeder cria um usuário `demo@exemplo.test` / senha `password` com três cartões e compras plausíveis.

- [ ] **Step 1: Aplicar a paleta**

Em `resources/css/app.css`, dentro do bloco `:root`, substituir os valores das variáveis de cor por:

```css
--background: oklch(0.98 0.005 250);
--foreground: oklch(0.25 0.04 260);
--card: oklch(1 0 0);
--card-foreground: oklch(0.25 0.04 260);
--popover: oklch(1 0 0);
--popover-foreground: oklch(0.25 0.04 260);
--primary: oklch(0.32 0.06 262);
--primary-foreground: oklch(0.98 0.005 250);
--secondary: oklch(0.52 0.14 148);
--secondary-foreground: oklch(0.98 0.005 250);
--muted: oklch(0.95 0.01 250);
--muted-foreground: oklch(0.52 0.02 258);
--accent: oklch(0.77 0.16 70);
--accent-foreground: oklch(0.25 0.04 260);
--destructive: oklch(0.58 0.21 27);
--border: oklch(0.91 0.01 250);
--input: oklch(0.91 0.01 250);
--ring: oklch(0.32 0.06 262);
--chart-1: oklch(0.32 0.06 262);
--chart-2: oklch(0.77 0.16 70);
--chart-3: oklch(0.52 0.14 148);
--chart-4: oklch(0.62 0.05 258);
--chart-5: oklch(0.82 0.03 250);
```

Correspondência com o `design.md` do Stitch: `--primary` é o navy `#1A2B4C`, `--accent` é o dourado `#F59E0B`, `--secondary` é o verde `#2E7D32`, `--background` é `#F8FAFC`.

No bloco `.dark`, manter as variáveis existentes mas alinhar `--primary` para o navy claro `oklch(0.72 0.08 265)` e `--accent` para o mesmo dourado — o modo escuro do starter continua funcionando.

Trocar a fonte no `@theme`:

```css
--font-sans:
    'Inter', ui-sans-serif, system-ui, sans-serif,
    'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol',
    'Noto Color Emoji';
```

E, em `resources/views/app.blade.php`, trocar o preload da fonte de Instrument Sans para Inter, mantendo a mesma estrutura de tags que já está lá.

- [ ] **Step 2: Aplicar números tabulares**

Acrescentar ao fim de `resources/css/app.css`:

```css
.tabular-nums {
    font-variant-numeric: tabular-nums;
}
```

O Tailwind já entrega essa classe; a declaração explícita garante que ela vença qualquer reset. Todas as colunas de valor das telas anteriores já a usam.

- [ ] **Step 3: Escrever o teste do seeder**

Criar `tests/Feature/DatabaseSeederTest.php`:

```php
<?php

use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;

it('cria um usuario demo com cartoes e parcelamentos', function (): void {
    $this->seed();

    $user = User::where('email', 'demo@exemplo.test')->sole();

    expect($user->cards()->count())->toBe(3)
        ->and(Purchase::count())->toBeGreaterThan(0)
        ->and(Installment::count())->toBeGreaterThan(0);
});

it('gera parcelas coerentes com o total de cada compra', function (): void {
    $this->seed();

    Purchase::with('installments')->get()->each(function (Purchase $purchase): void {
        expect($purchase->installments)->toHaveCount($purchase->installments_count)
            ->and((int) $purchase->installments->sum('amount_cents'))->toBe($purchase->total_amount_cents);
    });
});

it('e idempotente ao rodar duas vezes', function (): void {
    $this->seed();
    $this->seed();

    expect(User::where('email', 'demo@exemplo.test')->count())->toBe(1)
        ->and(Card::count())->toBe(3);
});
```

- [ ] **Step 4: Rodar e ver falhar**

Run: `php artisan test --compact --filter=DatabaseSeeder`
Expected: FAIL — o seeder padrão do starter cria outro usuário.

- [ ] **Step 5: Escrever o seeder**

`database/seeders/DatabaseSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Actions\GenerateInstallments;
use App\Enums\CardBrand;
use App\Models\Card;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@exemplo.test'],
            ['name' => 'Marcos', 'password' => 'password', 'email_verified_at' => now()],
        );

        if ($user->cards()->exists()) {
            return;
        }

        $cards = [
            ['name' => 'Nubank', 'brand' => CardBrand::Mastercard, 'label' => 'Platinum', 'last_four_digits' => '8291', 'credit_limit_cents' => 2_000_000, 'closing_day' => 3, 'due_day' => 10],
            ['name' => 'Inter', 'brand' => CardBrand::Visa, 'label' => 'Black', 'last_four_digits' => '4402', 'credit_limit_cents' => 1_500_000, 'closing_day' => 28, 'due_day' => 5],
            ['name' => 'XP Investimentos', 'brand' => CardBrand::Visa, 'label' => 'Infinite', 'last_four_digits' => '9901', 'credit_limit_cents' => 1_500_000, 'closing_day' => 18, 'due_day' => 25],
        ];

        $purchases = [
            [0, 'MacBook Pro M2', 'Apple Store - Shopping Morumbi', 1_200_000, 24, '-12 months'],
            [0, 'iPhone 15 Pro Max', 'Apple Store - Shopping Morumbi', 849_900, 12, '-3 months'],
            [1, 'Tênis Nike Air Max', 'Nike Store Brasil', 89_990, 6, '-5 months'],
            [1, 'Geladeira Brastemp', 'Fast Shop Online', 420_000, 10, '-2 months'],
            [2, 'Cadeira Herman Miller', 'Herman Miller BR', 1_500_000, 24, '-8 months'],
            [2, 'Passagem GRU-LIS', 'TAP Air Portugal', 680_000, 8, '-1 month'],
        ];

        $created = array_map(
            fn (array $attributes): Card => $user->cards()->create($attributes),
            $cards,
        );

        $generateInstallments = app(GenerateInstallments::class);

        foreach ($purchases as [$cardIndex, $description, $merchant, $totalCents, $count, $when]) {
            $purchase = Purchase::create([
                'card_id' => $created[$cardIndex]->id,
                'description' => $description,
                'merchant' => $merchant,
                'total_amount_cents' => $totalCents,
                'installments_count' => $count,
                'purchased_at' => now()->modify($when)->toDateString(),
            ]);

            $generateInstallments->handle($purchase->load('card'));
        }
    }
}
```

As parcelas saem da Action real, então o seeder também serve de verificação de que o cálculo funciona com dados realistas.

- [ ] **Step 6: Rodar e ver passar**

Run: `php artisan test --compact --filter=DatabaseSeeder`
Expected: PASS, 3 testes.

- [ ] **Step 7: Popular o banco local e olhar a aplicação**

```bash
php artisan migrate:fresh --seed
npm run build
composer run dev
```

Entrar com `demo@exemplo.test` / `password` e percorrer as quatro telas. Conferir: navy no sidebar e nos botões primários, dourado nos selos de "Próximo", verde nos de "Pago", números alinhados nas colunas de valor.

- [ ] **Step 8: Rodar a verificação completa e commitar**

```bash
composer run ci:check
```

Expected: lint, format, types e a suíte inteira verdes. Corrigir o que aparecer antes de commitar — inclusive falhas que não vieram desta task.

```bash
git add resources database tests
git commit -m "$(cat <<'EOF'
feat: paleta navy/dourado e seeder de demonstração

Tokens do design system do Stitch aplicados sobre as variáveis do
shadcn, mantendo o modo escuro. Seeder idempotente com três cartões e
seis parcelamentos gerados pela Action real.

Claude-Session: https://claude.ai/code/session_0156ogwsuLM3WTFASr1rELTR
EOF
)"
```

---

## Cobertura da spec

| Requisito da spec | Task |
|---|---|
| Regra de fechamento/vencimento com os 4 exemplos normativos | 1 |
| Rateio com resto na primeira parcela | 1 |
| Corte de dia sem propagação | 1 |
| Timezone `America/Sao_Paulo` | 1 |
| Tabelas `cards`, `purchases`, `installments` com índices | 2 |
| Dinheiro em `integer` de centavos | 2 |
| Status derivado, nunca persistido | 2 |
| Escopo por usuário via join | 2 |
| Factories com `parcelada()` usando a Action real | 2 |
| Parcelas materializadas na criação da compra | 3, 5 |
| `installments:rebuild` | 3 |
| Mudar cartão não regera parcelas em silêncio | 3 (nota), 4 (toast) |
| CRUD de cartões + Policy 403 | 4 |
| Form Requests com todas as regras especificadas | 4, 5 |
| Lançamento manual com preview | 5 |
| `card_id` restrito aos cartões do usuário | 5 |
| Compra à vista como `installments_count = 1` | 5 |
| Sem edição de compra | 5 (só create/destroy) |
| Métricas do Dashboard | 6 |
| Projeção de 6 meses + gráfico recharts | 6 |
| Comprometido / disponível / percentual | 7 |
| Parcelamento em destaque | 7 |
| Próximos vencimentos por cartão | 7 |
| "Detalhes" leva ao histórico filtrado | 8 |
| Histórico: 1 linha por parcela, filtros, paginação 20 | 8 |
| Ação de apagar a compra inteira com confirmação | 8 |
| Cards do topo globais, não respondem ao filtro | 8 |
| Dica de finanças (regra única) | 8 |
| CSV com BOM, `;` e as 9 colunas | 9 |
| Paleta navy/dourado, Inter, números tabulares | 10 |
| Estados vazios | 4, 6, 8 |
| Navegação no sidebar | 4, 5, 8 |
| Seeder | 10 |

Fora de escopo por decisão da spec, sem task correspondente: OCR, categorias, gastos fixos, planos/billing, PDF, marcação manual de pago, tela de detalhe de cartão, arquivos de tradução `pt_BR`.
