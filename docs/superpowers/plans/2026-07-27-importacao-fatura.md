# Importação de Fatura em PDF — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Importar parcelamentos a partir do PDF da fatura do cartão (Nubank, Mercado Pago, Itaú, Renner), com revisão obrigatória antes de gravar.

**Architecture:** `pdftotext` extrai o texto do PDF; um parser por emissor transforma o texto em DTOs; `ReconcileInvoiceLine` inverte `CalculateFirstDueDate` para resolver a data da compra a partir do vencimento da fatura e valida por round-trip no pipeline existente; o usuário revisa e confirma; `GenerateInstallments` grava as parcelas.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia v3, React 19, TypeScript, Tailwind v4, Pest v4, poppler (`pdftotext`).

**Spec:** `docs/superpowers/specs/2026-07-27-importacao-fatura-design.md`

## Global Constraints

- Nenhuma migration. Nenhuma tabela nova. O dedup sai de `purchases`.
- Nenhum `Purchase` gravado sem confirmação explícita do usuário.
- O PDF nunca é escrito em `storage/`. É lido do arquivo temporário do upload e descartado no mesmo request.
- PDFs reais de fatura **não entram no repositório**. Fixtures são texto anonimizado em `tests/Fixtures/faturas/*.txt`.
- `try/catch` apenas na fronteira do `pdftotext`. O resto sobe.
- Todo código PHP roda `vendor/bin/pint --dirty --format agent` antes do commit.
- PHPStan nível 7 tem que passar: `composer types:check`.
- Rotas em português (`importar`, `importar/revisao`), nomes de rota em inglês (`invoice-imports.*`), seguindo `routes/parcelamentos.php`.
- Textos de UI em português.
- Comparação de vencimento é por **mês**, nunca por data exata (emissores deslocam vencimento de fim de semana).
- Só linhas com marcação de parcela viram candidato. Compra à vista é ignorada.

## Mapa de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `app/Invoices/ExtractsInvoiceText.php` | Interface de extração de texto |
| `app/Invoices/PopplerTextExtractor.php` | Implementação via `pdftotext -layout` |
| `app/Invoices/ParsedInvoice.php` | DTO: emissor, vencimento, 4 dígitos, linhas |
| `app/Invoices/ParsedInvoiceLine.php` | DTO: uma linha parcelada da fatura |
| `app/Invoices/CandidateStatus.php` | Enum: matched, duplicate, divergent |
| `app/Invoices/InvoiceCandidate.php` | DTO: compra candidata + status |
| `app/Invoices/InvoiceParser.php` | Interface dos parsers |
| `app/Invoices/ResolveInvoiceParser.php` | Escolhe o parser pelo texto |
| `app/Invoices/ReconcileInvoiceLine.php` | Inverte a data e valida por round-trip |
| `app/Invoices/BuildInvoiceCandidates.php` | Orquestra reconciliação + dedup |
| `app/Invoices/Parsers/NubankInvoiceParser.php` | Parser Nubank |
| `app/Invoices/Parsers/MercadoPagoInvoiceParser.php` | Parser Mercado Pago |
| `app/Invoices/Parsers/ItauInvoiceParser.php` | Parser Itaú |
| `app/Invoices/Parsers/RennerInvoiceParser.php` | Parser Renner |
| `app/Http/Controllers/InvoiceImportController.php` | Upload, revisão, confirmação |
| `app/Http/Requests/AnalyzeInvoiceRequest.php` | Validação do upload |
| `app/Http/Requests/ConfirmInvoiceImportRequest.php` | Validação da confirmação |
| `app/Providers/AppServiceProvider.php` | Registro dos bindings |
| `routes/parcelamentos.php` | Três rotas novas |
| `resources/js/pages/invoices/import.tsx` | Tela de upload |
| `resources/js/pages/invoices/review.tsx` | Tela de revisão |
| `resources/js/components/candidate-status-chip.tsx` | Chip de status do candidato |
| `resources/js/types/parcelamentos.ts` | Tipos novos |

---

### Task 1: Extração de texto do PDF

**Files:**
- Create: `app/Invoices/ExtractsInvoiceText.php`
- Create: `app/Invoices/PopplerTextExtractor.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/PopplerTextExtractorTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `ExtractsInvoiceText::extract(string $path, ?string $password = null): string`. Lança `RuntimeException` quando o PDF não abre.

- [ ] **Step 1: Instalar o poppler**

```bash
brew install poppler
which pdftotext
```

Esperado: caminho do binário. Se já estiver instalado, o `brew` não faz nada.

- [ ] **Step 2: Escrever o teste que falha**

`tests/Feature/PopplerTextExtractorTest.php`:

```php
<?php

use App\Invoices\PopplerTextExtractor;

function minimalPdfBytes(): string
{
    $content = "BT /F1 12 Tf 20 100 Td (FATURA TESTE) Tj ET\n";

    return "%PDF-1.4\n"
        ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]"
        ."/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
        .'4 0 obj<</Length '.strlen($content).">>stream\n"
        .$content
        ."endstream\nendobj\n"
        ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
        ."trailer<</Root 1 0 R/Size 6>>\n"
        ."%%EOF\n";
}

it('extrai o texto de um pdf', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fatura').'.pdf';
    file_put_contents($path, minimalPdfBytes());

    $text = (new PopplerTextExtractor)->extract($path);

    unlink($path);

    expect($text)->toContain('FATURA TESTE');
})->skip(fn (): bool => exec('command -v pdftotext') === '', 'pdftotext nao instalado');

it('falha com mensagem clara quando o arquivo nao e um pdf', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fatura').'.pdf';
    file_put_contents($path, 'isto nao e um pdf');

    expect(fn (): string => (new PopplerTextExtractor)->extract($path))
        ->toThrow(RuntimeException::class);

    unlink($path);
})->skip(fn (): bool => exec('command -v pdftotext') === '', 'pdftotext nao instalado');
```

- [ ] **Step 3: Rodar e confirmar que falha**

```bash
php artisan test --compact --filter=PopplerTextExtractor
```

Esperado: FAIL, `Class "App\Invoices\PopplerTextExtractor" not found`.

- [ ] **Step 4: Escrever a interface**

`app/Invoices/ExtractsInvoiceText.php`:

```php
<?php

namespace App\Invoices;

interface ExtractsInvoiceText
{
    /**
     * Extrai o texto de um PDF de fatura.
     *
     * @throws \RuntimeException quando o PDF nao pode ser lido.
     */
    public function extract(string $path, ?string $password = null): string;
}
```

- [ ] **Step 5: Escrever a implementação**

`app/Invoices/PopplerTextExtractor.php`:

```php
<?php

namespace App\Invoices;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class PopplerTextExtractor implements ExtractsInvoiceText
{
    public function extract(string $path, ?string $password = null): string
    {
        $command = ['pdftotext', '-layout', '-enc', 'UTF-8'];

        if ($password !== null && $password !== '') {
            $command[] = '-upw';
            $command[] = $password;
        }

        $command[] = $path;
        $command[] = '-';

        $result = Process::run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                'Nao foi possivel ler o PDF: '.trim($result->errorOutput()),
            );
        }

        return $result->output();
    }
}
```

O `try/catch` fica de fora aqui de propósito: esta classe *é* a fronteira, e ela converte a falha do processo em exceção. Quem trata é o controller.

- [ ] **Step 6: Registrar o binding**

Em `app/Providers/AppServiceProvider.php`, dentro de `register()`:

```php
$this->app->bind(
    \App\Invoices\ExtractsInvoiceText::class,
    \App\Invoices\PopplerTextExtractor::class,
);
```

- [ ] **Step 7: Rodar e confirmar que passa**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=PopplerTextExtractor
```

Esperado: PASS (ou SKIP se o poppler não estiver instalado).

- [ ] **Step 8: Commit**

```bash
git add app/Invoices app/Providers/AppServiceProvider.php tests/Feature/PopplerTextExtractorTest.php
git commit -m "feat: extração de texto de fatura em PDF via poppler"
```

---

### Task 2: DTOs da fatura

**Files:**
- Create: `app/Invoices/ParsedInvoiceLine.php`
- Create: `app/Invoices/ParsedInvoice.php`
- Create: `app/Invoices/CandidateStatus.php`
- Create: `app/Invoices/InvoiceCandidate.php`
- Test: `tests/Unit/InvoiceCandidateTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `ParsedInvoiceLine(string $description, ?string $merchant, int $transactionDay, int $installmentNumber, int $installmentsCount, int $amountCents)`
  - `ParsedInvoice(string $issuer, CarbonImmutable $dueDate, ?string $lastFourDigits, array $lines)`
  - `CandidateStatus::Matched|Duplicate|Divergent`, com `label(): string` e `isImportable(): bool`
  - `InvoiceCandidate(...)` com `toArray(): array` e `static fromArray(array $data): self`

- [ ] **Step 1: Escrever o teste que falha**

`tests/Unit/InvoiceCandidateTest.php`:

```php
<?php

use App\Invoices\CandidateStatus;
use App\Invoices\InvoiceCandidate;
use Carbon\CarbonImmutable;

it('sobrevive a ida e volta para array', function (): void {
    $candidate = new InvoiceCandidate(
        description: 'MERCADOLIVRE*ABC',
        merchant: 'Mercado Livre',
        totalAmountCents: 129_900,
        installmentsCount: 10,
        installmentNumber: 3,
        installmentAmountCents: 12_990,
        purchasedAt: CarbonImmutable::parse('2026-03-15'),
        status: CandidateStatus::Matched,
    );

    $restored = InvoiceCandidate::fromArray($candidate->toArray());

    expect($restored->description)->toBe('MERCADOLIVRE*ABC')
        ->and($restored->merchant)->toBe('Mercado Livre')
        ->and($restored->totalAmountCents)->toBe(129_900)
        ->and($restored->installmentsCount)->toBe(10)
        ->and($restored->installmentNumber)->toBe(3)
        ->and($restored->installmentAmountCents)->toBe(12_990)
        ->and($restored->purchasedAt->toDateString())->toBe('2026-03-15')
        ->and($restored->status)->toBe(CandidateStatus::Matched);
});

it('so considera importavel o candidato casado ou divergente', function (): void {
    expect(CandidateStatus::Matched->isImportable())->toBeTrue()
        ->and(CandidateStatus::Divergent->isImportable())->toBeTrue()
        ->and(CandidateStatus::Duplicate->isImportable())->toBeFalse();
});
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact --filter=InvoiceCandidate
```

Esperado: FAIL, `Class "App\Invoices\InvoiceCandidate" not found`.

- [ ] **Step 3: Escrever os DTOs**

`app/Invoices/ParsedInvoiceLine.php`:

```php
<?php

namespace App\Invoices;

readonly class ParsedInvoiceLine
{
    public function __construct(
        public string $description,
        public ?string $merchant,
        public int $transactionDay,
        public int $installmentNumber,
        public int $installmentsCount,
        public int $amountCents,
    ) {}
}
```

`app/Invoices/ParsedInvoice.php`:

```php
<?php

namespace App\Invoices;

use Carbon\CarbonImmutable;

readonly class ParsedInvoice
{
    /**
     * @param  array<int, ParsedInvoiceLine>  $lines
     */
    public function __construct(
        public string $issuer,
        public CarbonImmutable $dueDate,
        public ?string $lastFourDigits,
        public array $lines,
    ) {}
}
```

`app/Invoices/CandidateStatus.php`:

```php
<?php

namespace App\Invoices;

enum CandidateStatus: string
{
    case Matched = 'matched';
    case Duplicate = 'duplicate';
    case Divergent = 'divergent';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Pronto',
            self::Duplicate => 'Já importado',
            self::Divergent => 'Conferir data',
        };
    }

    /**
     * Duplicata nunca e importada; divergente pode ser, apos correcao.
     */
    public function isImportable(): bool
    {
        return $this !== self::Duplicate;
    }
}
```

`app/Invoices/InvoiceCandidate.php`:

```php
<?php

namespace App\Invoices;

use Carbon\CarbonImmutable;

readonly class InvoiceCandidate
{
    public function __construct(
        public string $description,
        public ?string $merchant,
        public int $totalAmountCents,
        public int $installmentsCount,
        public int $installmentNumber,
        public int $installmentAmountCents,
        public CarbonImmutable $purchasedAt,
        public CandidateStatus $status,
    ) {}

    /**
     * @return array{description: string, merchant: string|null, total_amount_cents: int,
     *     installments_count: int, installment_number: int, installment_amount_cents: int,
     *     purchased_at: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'merchant' => $this->merchant,
            'total_amount_cents' => $this->totalAmountCents,
            'installments_count' => $this->installmentsCount,
            'installment_number' => $this->installmentNumber,
            'installment_amount_cents' => $this->installmentAmountCents,
            'purchased_at' => $this->purchasedAt->toDateString(),
            'status' => $this->status->value,
        ];
    }

    /**
     * @param  array{description: string, merchant: string|null, total_amount_cents: int,
     *     installments_count: int, installment_number: int, installment_amount_cents: int,
     *     purchased_at: string, status: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            description: $data['description'],
            merchant: $data['merchant'],
            totalAmountCents: $data['total_amount_cents'],
            installmentsCount: $data['installments_count'],
            installmentNumber: $data['installment_number'],
            installmentAmountCents: $data['installment_amount_cents'],
            purchasedAt: CarbonImmutable::parse($data['purchased_at']),
            status: CandidateStatus::from($data['status']),
        );
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=InvoiceCandidate
composer types:check
```

Esperado: PASS, PHPStan sem erros.

- [ ] **Step 5: Commit**

```bash
git add app/Invoices tests/Unit/InvoiceCandidateTest.php
git commit -m "feat: DTOs de fatura importada"
```

---

### Task 3: Reconciliação — inverter a data da compra

Este é o núcleo do plano. É onde mora o risco de gravar dado errado.

**Files:**
- Create: `app/Invoices/ReconcileInvoiceLine.php`
- Test: `tests/Unit/ReconcileInvoiceLineTest.php`

**Interfaces:**
- Consumes: `ParsedInvoiceLine`, `InvoiceCandidate`, `CandidateStatus` (Task 2); `BuildInstallmentSchedule::handle(int $totalCents, int $count, CarbonImmutable $purchasedAt, int $closingDay, int $dueDay): array` (existente).
- Produces: `ReconcileInvoiceLine::handle(ParsedInvoiceLine $line, CarbonImmutable $invoiceDueDate, int $closingDay, int $dueDay): InvoiceCandidate`, sempre com status `Matched` ou `Divergent`.

Recebe `closingDay`/`dueDay` soltos em vez do `Card` inteiro: mantém a classe testável sem banco e sem factory.

- [ ] **Step 1: Escrever o teste que falha**

`tests/Unit/ReconcileInvoiceLineTest.php`:

```php
<?php

use App\Actions\BuildInstallmentSchedule;
use App\Actions\CalculateFirstDueDate;
use App\Actions\SplitIntoInstallments;
use App\Invoices\CandidateStatus;
use App\Invoices\ParsedInvoiceLine;
use App\Invoices\ReconcileInvoiceLine;
use Carbon\CarbonImmutable;

/**
 * tests/Unit nao sobe o container do Laravel (o Pest.php so estende
 * TestCase em Feature), entao a dependencia e montada a mao.
 */
function reconciler(): ReconcileInvoiceLine
{
    return new ReconcileInvoiceLine(
        new BuildInstallmentSchedule(new SplitIntoInstallments, new CalculateFirstDueDate),
    );
}

function invoiceLine(int $number, int $count, int $day, int $amountCents = 12_990): ParsedInvoiceLine
{
    return new ParsedInvoiceLine(
        description: 'MERCADOLIVRE*ABC',
        merchant: 'Mercado Livre',
        transactionDay: $day,
        installmentNumber: $number,
        installmentsCount: $count,
        amountCents: $amountCents,
    );
}

it('resolve a data da compra a partir do vencimento da fatura', function (
    int $number,
    int $count,
    int $transactionDay,
    string $invoiceDueDate,
    int $closingDay,
    int $dueDay,
    string $expectedPurchasedAt,
): void {
    $candidate = reconciler()->handle(
        invoiceLine($number, $count, $transactionDay),
        CarbonImmutable::parse($invoiceDueDate),
        $closingDay,
        $dueDay,
    );

    expect($candidate->purchasedAt->toDateString())->toBe($expectedPurchasedAt)
        ->and($candidate->status)->toBe(CandidateStatus::Matched);
})->with([
    'primeira parcela, compra antes do fechamento' => [1, 10, 2, '2026-03-10', 3, 10, '2026-03-02'],
    'primeira parcela, compra depois do fechamento' => [1, 10, 5, '2026-04-10', 3, 10, '2026-03-05'],
    'terceira parcela recua dois meses' => [3, 10, 2, '2026-05-10', 3, 10, '2026-03-02'],
    'ultima parcela' => [10, 10, 2, '2026-12-10', 3, 10, '2026-03-02'],
    'vencimento antes do fechamento' => [1, 6, 2, '2026-04-05', 28, 5, '2026-03-02'],
    'fechamento e vencimento no mesmo dia' => [1, 3, 1, '2026-04-10', 10, 10, '2026-03-01'],
    'virada de ano' => [3, 12, 20, '2027-01-15', 10, 15, '2026-11-20'],
    'vencimento deslocado para o dia seguinte' => [2, 6, 2, '2026-05-11', 3, 10, '2026-03-02'],
]);

it('calcula o total como valor da parcela vezes a quantidade', function (): void {
    $candidate = reconciler()->handle(
        invoiceLine(3, 10, 2, 12_990),
        CarbonImmutable::parse('2026-05-10'),
        3,
        10,
    );

    expect($candidate->totalAmountCents)->toBe(129_900)
        ->and($candidate->installmentAmountCents)->toBe(12_990)
        ->and($candidate->installmentsCount)->toBe(10)
        ->and($candidate->installmentNumber)->toBe(3);
});

it('marca como divergente quando o round-trip nao fecha', function (): void {
    $candidate = reconciler()->handle(
        invoiceLine(1, 3, 31),
        CarbonImmutable::parse('2026-05-10'),
        30,
        10,
    );

    expect($candidate->status)->toBe(CandidateStatus::Divergent);
});

it('resolver e reprocessar sempre devolve o mes de vencimento alvo', function (): void {
    $reconcile = reconciler();

    foreach ([1, 3, 10, 15, 28] as $closingDay) {
        foreach ([1, 5, 10, 20, 28] as $dueDay) {
            foreach ([1, 2, 6, 12] as $number) {
                foreach ([1, 15, 28] as $transactionDay) {
                    $candidate = $reconcile->handle(
                        invoiceLine($number, 12, $transactionDay),
                        CarbonImmutable::create(2026, 6, $dueDay),
                        $closingDay,
                        $dueDay,
                    );

                    expect($candidate->status)
                        ->toBe(CandidateStatus::Matched, "closing={$closingDay} due={$dueDay} n={$number} day={$transactionDay}");
                }
            }
        }
    }
});
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact --filter=ReconcileInvoiceLine
```

Esperado: FAIL, `Class "App\Invoices\ReconcileInvoiceLine" not found`.

- [ ] **Step 3: Escrever a implementação**

`app/Invoices/ReconcileInvoiceLine.php`:

```php
<?php

namespace App\Invoices;

use App\Actions\BuildInstallmentSchedule;
use Carbon\CarbonImmutable;

class ReconcileInvoiceLine
{
    public function __construct(
        private BuildInstallmentSchedule $buildInstallmentSchedule,
    ) {}

    /**
     * Resolve a compra que originou uma linha de fatura e confere o resultado
     * reprocessando o cronograma pelo pipeline oficial.
     */
    public function handle(
        ParsedInvoiceLine $line,
        CarbonImmutable $invoiceDueDate,
        int $closingDay,
        int $dueDay,
    ): InvoiceCandidate {
        $purchasedAt = $this->resolvePurchaseDate($line, $invoiceDueDate, $closingDay, $dueDay);
        $totalCents = $line->amountCents * $line->installmentsCount;

        $schedule = $this->buildInstallmentSchedule->handle(
            $totalCents,
            $line->installmentsCount,
            $purchasedAt,
            $closingDay,
            $dueDay,
        );

        $reprocessed = $schedule[$line->installmentNumber - 1]['due_date'];

        return new InvoiceCandidate(
            description: $line->description,
            merchant: $line->merchant,
            totalAmountCents: $totalCents,
            installmentsCount: $line->installmentsCount,
            installmentNumber: $line->installmentNumber,
            installmentAmountCents: $line->amountCents,
            purchasedAt: $purchasedAt,
            status: $reprocessed->isSameMonth($invoiceDueDate, true)
                ? CandidateStatus::Matched
                : CandidateStatus::Divergent,
        );
    }

    /**
     * Inverte CalculateFirstDueDate: parte do mes de vencimento da fatura,
     * recua ate o mes da primeira parcela e dai ate o mes da compra.
     *
     * A conta e feita em meses e o dia so e reconstruido no fim: subtrair
     * meses de uma data ja truncada (28/02 vinda de due_day 31) perde
     * informacao.
     */
    private function resolvePurchaseDate(
        ParsedInvoiceLine $line,
        CarbonImmutable $invoiceDueDate,
        int $closingDay,
        int $dueDay,
    ): CarbonImmutable {
        $firstDueMonth = $invoiceDueDate
            ->startOfMonth()
            ->subMonths($line->installmentNumber - 1);

        $closingMonth = $dueDay > $closingDay
            ? $firstDueMonth
            : $firstDueMonth->subMonth();

        $purchasedMonth = $line->transactionDay > $closingDay
            ? $closingMonth->subMonth()
            : $closingMonth;

        return $purchasedMonth->addDays(
            min($line->transactionDay, $purchasedMonth->daysInMonth) - 1,
        );
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=ReconcileInvoiceLine
composer types:check
```

Esperado: PASS.

Se o teste de varredura falhar em alguma combinação, **não relaxe a asserção**. A falha indica que a inversão está errada naquele caso, e é exatamente o dado que importa. Investigue o caso concreto que o `expect` reportou.

- [ ] **Step 5: Commit**

```bash
git add app/Invoices/ReconcileInvoiceLine.php tests/Unit/ReconcileInvoiceLineTest.php
git commit -m "feat: reconciliação de linha de fatura com verificação por round-trip"
```

---

### Task 4: Deduplicação e montagem dos candidatos

**Files:**
- Create: `app/Invoices/BuildInvoiceCandidates.php`
- Test: `tests/Feature/BuildInvoiceCandidatesTest.php`

**Interfaces:**
- Consumes: `ReconcileInvoiceLine` (Task 3), `ParsedInvoice` (Task 2), `Card`.
- Produces: `BuildInvoiceCandidates::handle(ParsedInvoice $invoice, Card $card): array<int, InvoiceCandidate>`.

- [ ] **Step 1: Escrever o teste que falha**

`tests/Feature/BuildInvoiceCandidatesTest.php`:

```php
<?php

use App\Actions\GenerateInstallments;
use App\Invoices\BuildInvoiceCandidates;
use App\Invoices\CandidateStatus;
use App\Invoices\ParsedInvoice;
use App\Invoices\ParsedInvoiceLine;
use App\Models\Card;
use App\Models\Purchase;
use App\Models\User;
use Carbon\CarbonImmutable;

function parsedInvoice(string $dueDate, ParsedInvoiceLine ...$lines): ParsedInvoice
{
    return new ParsedInvoice(
        issuer: 'nubank',
        dueDate: CarbonImmutable::parse($dueDate),
        lastFourDigits: '8291',
        lines: $lines,
    );
}

function parsedLine(int $number, int $count, int $day, int $amountCents): ParsedInvoiceLine
{
    return new ParsedInvoiceLine(
        description: 'MERCADOLIVRE*ABC',
        merchant: 'Mercado Livre',
        transactionDay: $day,
        installmentNumber: $number,
        installmentsCount: $count,
        amountCents: $amountCents,
    );
}

it('monta um candidato por linha', function (): void {
    $card = Card::factory()->for(User::factory())->create(['closing_day' => 3, 'due_day' => 10]);

    $candidates = app(BuildInvoiceCandidates::class)->handle(
        parsedInvoice('2026-05-10', parsedLine(3, 10, 2, 12_990), parsedLine(1, 4, 2, 5_000)),
        $card,
    );

    expect($candidates)->toHaveCount(2)
        ->and($candidates[0]->status)->toBe(CandidateStatus::Matched)
        ->and($candidates[0]->totalAmountCents)->toBe(129_900)
        ->and($candidates[1]->totalAmountCents)->toBe(20_000);
});

it('marca como duplicata a compra que ja existe', function (): void {
    $card = Card::factory()->for(User::factory())->create(['closing_day' => 3, 'due_day' => 10]);

    $purchase = Purchase::create([
        'card_id' => $card->id,
        'description' => 'Mercado Livre',
        'merchant' => 'Mercado Livre',
        'total_amount_cents' => 129_900,
        'installments_count' => 10,
        'purchased_at' => '2026-03-02',
    ]);

    app(GenerateInstallments::class)->handle($purchase->load('card'));

    $candidates = app(BuildInvoiceCandidates::class)->handle(
        parsedInvoice('2026-05-10', parsedLine(3, 10, 2, 12_990)),
        $card,
    );

    expect($candidates[0]->status)->toBe(CandidateStatus::Duplicate);
});

it('nao confunde compra de outro cartao com duplicata', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['closing_day' => 3, 'due_day' => 10]);
    $other = Card::factory()->for($user)->create(['closing_day' => 3, 'due_day' => 10]);

    Purchase::create([
        'card_id' => $other->id,
        'description' => 'Mercado Livre',
        'merchant' => 'Mercado Livre',
        'total_amount_cents' => 129_900,
        'installments_count' => 10,
        'purchased_at' => '2026-03-02',
    ]);

    $candidates = app(BuildInvoiceCandidates::class)->handle(
        parsedInvoice('2026-05-10', parsedLine(3, 10, 2, 12_990)),
        $card,
    );

    expect($candidates[0]->status)->toBe(CandidateStatus::Matched);
});
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact --filter=BuildInvoiceCandidates
```

Esperado: FAIL, `Target class [App\Invoices\BuildInvoiceCandidates] does not exist`.

- [ ] **Step 3: Escrever a implementação**

`app/Invoices/BuildInvoiceCandidates.php`:

```php
<?php

namespace App\Invoices;

use App\Models\Card;
use App\Models\Purchase;

class BuildInvoiceCandidates
{
    public function __construct(
        private ReconcileInvoiceLine $reconcileInvoiceLine,
    ) {}

    /**
     * @return array<int, InvoiceCandidate>
     */
    public function handle(ParsedInvoice $invoice, Card $card): array
    {
        return array_map(
            fn (ParsedInvoiceLine $line): InvoiceCandidate => $this->markDuplicate(
                $this->reconcileInvoiceLine->handle(
                    $line,
                    $invoice->dueDate,
                    $card->closing_day,
                    $card->due_day,
                ),
                $card,
            ),
            array_values($invoice->lines),
        );
    }

    /**
     * A mesma compra aparece em faturas consecutivas como 3/10 e 4/10, mas
     * ambas resolvem para a mesma assinatura. A descricao fica de fora: o
     * texto do estabelecimento varia entre faturas.
     */
    private function markDuplicate(InvoiceCandidate $candidate, Card $card): InvoiceCandidate
    {
        $exists = Purchase::query()
            ->where('card_id', $card->id)
            ->where('total_amount_cents', $candidate->totalAmountCents)
            ->where('installments_count', $candidate->installmentsCount)
            ->whereDate('purchased_at', $candidate->purchasedAt->toDateString())
            ->exists();

        if (! $exists) {
            return $candidate;
        }

        return new InvoiceCandidate(
            description: $candidate->description,
            merchant: $candidate->merchant,
            totalAmountCents: $candidate->totalAmountCents,
            installmentsCount: $candidate->installmentsCount,
            installmentNumber: $candidate->installmentNumber,
            installmentAmountCents: $candidate->installmentAmountCents,
            purchasedAt: $candidate->purchasedAt,
            status: CandidateStatus::Duplicate,
        );
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=BuildInvoiceCandidates
composer types:check
```

Esperado: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Invoices/BuildInvoiceCandidates.php tests/Feature/BuildInvoiceCandidatesTest.php
git commit -m "feat: deduplicação de compras já importadas"
```

---

### Task 5: Registro e resolução de parsers

**Files:**
- Create: `app/Invoices/InvoiceParser.php`
- Create: `app/Invoices/ResolveInvoiceParser.php`
- Test: `tests/Unit/ResolveInvoiceParserTest.php`

**Interfaces:**
- Consumes: `ParsedInvoice` (Task 2).
- Produces:
  - `InvoiceParser::supports(string $text): bool` e `InvoiceParser::parse(string $text): ParsedInvoice`
  - `ResolveInvoiceParser::handle(string $text): InvoiceParser`, lançando `UnsupportedInvoiceException` quando nenhum parser reconhece
  - `App\Invoices\UnsupportedInvoiceException`

- [ ] **Step 1: Escrever o teste que falha**

`tests/Unit/ResolveInvoiceParserTest.php`:

```php
<?php

use App\Invoices\InvoiceParser;
use App\Invoices\ParsedInvoice;
use App\Invoices\ResolveInvoiceParser;
use App\Invoices\UnsupportedInvoiceException;
use Carbon\CarbonImmutable;

function fakeParser(string $needle): InvoiceParser
{
    return new class($needle) implements InvoiceParser
    {
        public function __construct(private string $needle) {}

        public function supports(string $text): bool
        {
            return str_contains($text, $this->needle);
        }

        public function parse(string $text): ParsedInvoice
        {
            return new ParsedInvoice($this->needle, CarbonImmutable::parse('2026-05-10'), '0000', []);
        }
    };
}

it('escolhe o primeiro parser que reconhece o texto', function (): void {
    $resolve = new ResolveInvoiceParser([fakeParser('nubank'), fakeParser('itau')]);

    expect($resolve->handle('fatura itau de maio')->parse('')->issuer)->toBe('itau');
});

it('recusa texto que nenhum parser reconhece', function (): void {
    $resolve = new ResolveInvoiceParser([fakeParser('nubank')]);

    expect(fn (): InvoiceParser => $resolve->handle('fatura do banco desconhecido'))
        ->toThrow(UnsupportedInvoiceException::class);
});
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact --filter=ResolveInvoiceParser
```

Esperado: FAIL, `Interface "App\Invoices\InvoiceParser" not found`.

- [ ] **Step 3: Escrever interface, exceção e resolver**

`app/Invoices/InvoiceParser.php`:

```php
<?php

namespace App\Invoices;

interface InvoiceParser
{
    /**
     * Reconhece o emissor pela assinatura no texto extraido.
     */
    public function supports(string $text): bool;

    /**
     * @throws \App\Invoices\UnreadableInvoiceException quando falta vencimento
     *     ou nao ha linha parcelada.
     */
    public function parse(string $text): ParsedInvoice;
}
```

`app/Invoices/UnsupportedInvoiceException.php`:

```php
<?php

namespace App\Invoices;

use RuntimeException;

class UnsupportedInvoiceException extends RuntimeException {}
```

`app/Invoices/UnreadableInvoiceException.php`:

```php
<?php

namespace App\Invoices;

use RuntimeException;

class UnreadableInvoiceException extends RuntimeException {}
```

`app/Invoices/ResolveInvoiceParser.php`:

```php
<?php

namespace App\Invoices;

class ResolveInvoiceParser
{
    /**
     * @param  array<int, InvoiceParser>  $parsers
     */
    public function __construct(private array $parsers) {}

    /**
     * @throws UnsupportedInvoiceException
     */
    public function handle(string $text): InvoiceParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($text)) {
                return $parser;
            }
        }

        throw new UnsupportedInvoiceException(
            'Emissor não reconhecido. Suportamos Nubank, Mercado Pago, Itaú e Renner.',
        );
    }
}
```

Não existe parser genérico de fallback. Um parser que adivinha é o que produz importação silenciosamente errada.

- [ ] **Step 4: Rodar e confirmar que passa**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=ResolveInvoiceParser
composer types:check
```

Esperado: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Invoices tests/Unit/ResolveInvoiceParserTest.php
git commit -m "feat: registro e resolução de parsers de fatura"
```

---

### Task 6: Upload e análise da fatura

**Files:**
- Create: `app/Http/Requests/AnalyzeInvoiceRequest.php`
- Create: `app/Http/Controllers/InvoiceImportController.php`
- Create: `resources/js/pages/invoices/import.tsx`
- Modify: `routes/parcelamentos.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `resources/js/types/parcelamentos.ts`
- Test: `tests/Feature/InvoiceImportTest.php`

**Interfaces:**
- Consumes: `ExtractsInvoiceText` (Task 1), `ResolveInvoiceParser` (Task 5), `BuildInvoiceCandidates` (Task 4).
- Produces: rotas `invoice-imports.create`, `invoice-imports.store`, `invoice-imports.review`. Chave de sessão `invoice_import`, com o shape `{card_id: int, issuer: string, due_date: string, candidates: array}`.

- [ ] **Step 1: Escrever o teste que falha**

`tests/Feature/InvoiceImportTest.php`:

```php
<?php

use App\Invoices\ExtractsInvoiceText;
use App\Invoices\InvoiceParser;
use App\Invoices\ParsedInvoice;
use App\Invoices\ParsedInvoiceLine;
use App\Invoices\ResolveInvoiceParser;
use App\Models\Card;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

function fakeExtractorReturning(string $text): void
{
    app()->bind(ExtractsInvoiceText::class, fn (): ExtractsInvoiceText => new class($text) implements ExtractsInvoiceText
    {
        public function __construct(private string $text) {}

        public function extract(string $path, ?string $password = null): string
        {
            return $this->text;
        }
    });
}

function fakeParserFor(ParsedInvoice $invoice): void
{
    app()->bind(ResolveInvoiceParser::class, fn (): ResolveInvoiceParser => new ResolveInvoiceParser([
        new class($invoice) implements InvoiceParser
        {
            public function __construct(private ParsedInvoice $invoice) {}

            public function supports(string $text): bool
            {
                return true;
            }

            public function parse(string $text): ParsedInvoice
            {
                return $this->invoice;
            }
        },
    ]));
}

function invoiceWithOneLine(string $lastFour = '8291'): ParsedInvoice
{
    return new ParsedInvoice(
        issuer: 'Nubank',
        dueDate: CarbonImmutable::parse('2026-05-10'),
        lastFourDigits: $lastFour,
        lines: [new ParsedInvoiceLine('MERCADOLIVRE*ABC', 'Mercado Livre', 2, 3, 10, 12_990)],
    );
}

it('mostra o formulario de upload', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('invoice-imports.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('invoices/import'));
});

it('redireciona para o cadastro de cartao quando nao ha nenhum', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('invoice-imports.create'))
        ->assertRedirect(route('cards.create'));
});

it('analisa a fatura e leva para a revisao', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine());

    $this->actingAs($user)
        ->post(route('invoice-imports.store'), [
            'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
        ])
        ->assertRedirect(route('invoice-imports.review'))
        ->assertSessionHas('invoice_import.card_id', $card->id);

    $this->actingAs($user)
        ->get(route('invoice-imports.review'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('invoices/review')
            ->has('candidates', 1)
            ->where('candidates.0.status', 'matched')
            ->where('candidates.0.total_amount_cents', 129_900));
});

it('recusa arquivo que nao e pdf', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('invoice-imports.store'), [
            'invoice' => UploadedFile::fake()->create('fatura.txt', 10, 'text/plain'),
        ])
        ->assertSessionHasErrors('invoice');
});

it('recusa fatura de emissor desconhecido', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();

    fakeExtractorReturning('texto qualquer');
    app()->bind(ResolveInvoiceParser::class, fn (): ResolveInvoiceParser => new ResolveInvoiceParser([]));

    $this->actingAs($user)
        ->post(route('invoice-imports.store'), [
            'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
        ])
        ->assertSessionHasErrors('invoice');
});

it('recusa fatura sem nenhuma linha parcelada', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(new ParsedInvoice('Nubank', CarbonImmutable::parse('2026-05-10'), '8291', []));

    $this->actingAs($user)
        ->post(route('invoice-imports.store'), [
            'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
        ])
        ->assertSessionHasErrors('invoice');
});

it('pede o cartao quando os quatro digitos nao casam', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['last_four_digits' => '1111']);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine('9999'));

    $this->actingAs($user)
        ->post(route('invoice-imports.store'), [
            'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
        ])
        ->assertSessionHasErrors('card_id');
});

it('aceita cartao informado manualmente', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '1111',
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine('9999'));

    $this->actingAs($user)
        ->post(route('invoice-imports.store'), [
            'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
            'card_id' => $card->id,
        ])
        ->assertRedirect(route('invoice-imports.review'));
});

it('nao aceita cartao de outro usuario', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['last_four_digits' => '1111']);
    $foreign = Card::factory()->for(User::factory())->create();

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine('9999'));

    $this->actingAs($user)
        ->post(route('invoice-imports.store'), [
            'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
            'card_id' => $foreign->id,
        ])
        ->assertSessionHasErrors('card_id');
});

it('exige autenticacao', function (): void {
    $this->get(route('invoice-imports.create'))->assertRedirect(route('login'));
});
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact --filter=InvoiceImport
```

Esperado: FAIL, rota `invoice-imports.create` não definida.

- [ ] **Step 3: Escrever o FormRequest**

`app/Http/Requests/AnalyzeInvoiceRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalyzeInvoiceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'invoice' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'password' => ['nullable', 'string', 'max:60'],
            'card_id' => [
                'nullable',
                Rule::exists('cards', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'invoice.mimes' => 'O arquivo precisa ser um PDF.',
            'invoice.max' => 'O PDF pode ter no máximo 10 MB.',
            'card_id.exists' => 'Selecione um cartão válido.',
        ];
    }
}
```

- [ ] **Step 4: Escrever o controller**

`app/Http/Controllers/InvoiceImportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyzeInvoiceRequest;
use App\Invoices\BuildInvoiceCandidates;
use App\Invoices\ExtractsInvoiceText;
use App\Invoices\InvoiceCandidate;
use App\Invoices\ParsedInvoice;
use App\Invoices\ResolveInvoiceParser;
use App\Invoices\UnreadableInvoiceException;
use App\Invoices\UnsupportedInvoiceException;
use App\Models\Card;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class InvoiceImportController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $cards = $this->cardOptions($request);

        if ($cards->isEmpty()) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => 'Cadastre um cartão antes de importar uma fatura.',
            ]);

            return to_route('cards.create');
        }

        return Inertia::render('invoices/import', ['cards' => $cards]);
    }

    public function store(
        AnalyzeInvoiceRequest $request,
        ExtractsInvoiceText $extractor,
        ResolveInvoiceParser $resolveParser,
        BuildInvoiceCandidates $buildCandidates,
    ): RedirectResponse {
        $text = $this->extractText($extractor, $request);

        try {
            $invoice = $resolveParser->handle($text)->parse($text);
        } catch (UnsupportedInvoiceException|UnreadableInvoiceException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }

        if ($invoice->lines === []) {
            throw ValidationException::withMessages([
                'invoice' => 'Nenhuma compra parcelada encontrada nesta fatura.',
            ]);
        }

        $card = $this->resolveCard($request, $invoice);
        $candidates = $buildCandidates->handle($invoice, $card);

        $request->session()->put('invoice_import', [
            'card_id' => $card->id,
            'issuer' => $invoice->issuer,
            'due_date' => $invoice->dueDate->toDateString(),
            'candidates' => array_map(
                fn (InvoiceCandidate $candidate): array => $candidate->toArray(),
                $candidates,
            ),
        ]);

        return to_route('invoice-imports.review');
    }

    public function review(Request $request): Response|RedirectResponse
    {
        $import = $request->session()->get('invoice_import');

        if (! is_array($import)) {
            return to_route('invoice-imports.create');
        }

        $card = Card::query()->findOrFail($import['card_id']);

        return Inertia::render('invoices/review', [
            'issuer' => $import['issuer'],
            'due_date' => $import['due_date'],
            'card' => [
                'id' => $card->id,
                'name' => $card->name,
                'last_four_digits' => $card->last_four_digits,
            ],
            'candidates' => $import['candidates'],
        ]);
    }

    /**
     * A extracao e a fronteira com o processo externo: e o unico ponto onde
     * a falha vira mensagem de validacao em vez de subir.
     */
    private function extractText(ExtractsInvoiceText $extractor, AnalyzeInvoiceRequest $request): string
    {
        try {
            return $extractor->extract(
                $request->file('invoice')->getRealPath(),
                $request->string('password')->value(),
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'invoice' => 'Não foi possível ler o PDF. Se ele tiver senha, informe-a abaixo.',
            ]);
        }
    }

    private function resolveCard(AnalyzeInvoiceRequest $request, ParsedInvoice $invoice): Card
    {
        if ($request->filled('card_id')) {
            return Card::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($request->integer('card_id'));
        }

        $card = Card::query()
            ->where('user_id', $request->user()->id)
            ->where('last_four_digits', $invoice->lastFourDigits)
            ->first();

        if ($card === null) {
            throw ValidationException::withMessages([
                'card_id' => 'Não identificamos o cartão desta fatura. Selecione qual é.',
            ]);
        }

        return $card;
    }

    /**
     * @return Collection<int, array{id: int, name: string, last_four_digits: string}>
     */
    private function cardOptions(Request $request): Collection
    {
        return $request->user()->cards()->orderBy('name')->get()->map(
            fn (Card $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'last_four_digits' => $card->last_four_digits,
            ],
        );
    }
}
```

- [ ] **Step 5: Registrar o binding do resolver**

Em `app/Providers/AppServiceProvider.php`, em `register()`:

```php
$this->app->bind(\App\Invoices\ResolveInvoiceParser::class, fn (): \App\Invoices\ResolveInvoiceParser => new \App\Invoices\ResolveInvoiceParser([
    new \App\Invoices\Parsers\NubankInvoiceParser,
    new \App\Invoices\Parsers\MercadoPagoInvoiceParser,
    new \App\Invoices\Parsers\ItauInvoiceParser,
    new \App\Invoices\Parsers\RennerInvoiceParser,
]));
```

As quatro classes são criadas nas Tasks 9–12. Até lá, registre a lista **vazia** (`new ResolveInvoiceParser([])`) para o código compilar, e troque pela lista acima na Task 12.

- [ ] **Step 6: Adicionar as rotas**

Em `routes/parcelamentos.php`, dentro do grupo `auth`/`verified`, depois das rotas de `lancamentos`:

```php
Route::get('importar', [InvoiceImportController::class, 'create'])->name('invoice-imports.create');
Route::post('importar', [InvoiceImportController::class, 'store'])->name('invoice-imports.store');
Route::get('importar/revisao', [InvoiceImportController::class, 'review'])->name('invoice-imports.review');
```

E o `use App\Http\Controllers\InvoiceImportController;` no topo.

- [ ] **Step 7: Escrever a tela de upload**

`resources/js/pages/invoices/import.tsx`:

```tsx
import { Form, Head } from '@inertiajs/react';
import InvoiceImportController from '@/actions/App/Http/Controllers/InvoiceImportController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { CardOption } from '@/types';

export default function ImportInvoice({ cards }: { cards: CardOption[] }) {
    return (
        <>
            <Head title="Importar fatura" />

            <div className="max-w-2xl space-y-6 p-4">
                <Heading
                    title="Importar fatura"
                    description="Envie o PDF da fatura para lançar os parcelamentos de uma vez"
                />

                <Card>
                    <CardContent className="pt-6">
                        <Form
                            {...InvoiceImportController.store.form()}
                            options={{ preserveScroll: true }}
                            className="space-y-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="invoice">
                                            PDF da fatura
                                        </Label>
                                        <Input
                                            id="invoice"
                                            name="invoice"
                                            type="file"
                                            accept="application/pdf"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Nubank, Mercado Pago, Itaú e Renner.
                                            O arquivo não é armazenado.
                                        </p>
                                        <InputError message={errors.invoice} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            Senha do PDF (se houver)
                                        </Label>
                                        <Input
                                            id="password"
                                            name="password"
                                            type="password"
                                            autoComplete="off"
                                        />
                                        <InputError message={errors.password} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="card_id">
                                            Cartão (opcional)
                                        </Label>
                                        <Select name="card_id">
                                            <SelectTrigger id="card_id">
                                                <SelectValue placeholder="Identificar pelo PDF" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {cards.map((card) => (
                                                    <SelectItem
                                                        key={card.id}
                                                        value={String(card.id)}
                                                    >
                                                        {card.name} ••••{' '}
                                                        {card.last_four_digits}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.card_id} />
                                    </div>

                                    <Button type="submit" disabled={processing}>
                                        Analisar fatura
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
```

- [ ] **Step 8: Rodar e confirmar que passa**

```bash
npm run build
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=InvoiceImport
composer types:check
```

Esperado: PASS. O `npm run build` é necessário porque a página nova precisa entrar no manifest do Vite.

- [ ] **Step 9: Commit**

```bash
git add app resources routes tests
git commit -m "feat: upload e análise de fatura em PDF"
```

---

### Task 7: Tela de revisão

**Files:**
- Create: `resources/js/components/candidate-status-chip.tsx`
- Create: `resources/js/pages/invoices/review.tsx`
- Modify: `resources/js/types/parcelamentos.ts`

**Interfaces:**
- Consumes: props da rota `invoice-imports.review` (Task 6): `issuer`, `due_date`, `card`, `candidates`.
- Produces: form que posta para `invoice-imports.confirm` (Task 8) com `lines[]` de `{index, purchased_at}`.

- [ ] **Step 1: Adicionar os tipos**

Em `resources/js/types/parcelamentos.ts`:

```ts
export type CandidateStatus = 'matched' | 'duplicate' | 'divergent';

export type InvoiceCandidate = {
    description: string;
    merchant: string | null;
    total_amount_cents: number;
    installments_count: number;
    installment_number: number;
    installment_amount_cents: number;
    purchased_at: string;
    status: CandidateStatus;
};
```

- [ ] **Step 2: Escrever o chip de status**

`resources/js/components/candidate-status-chip.tsx`:

```tsx
import { Badge } from '@/components/ui/badge';
import type { CandidateStatus } from '@/types/parcelamentos';

const LABELS: Record<CandidateStatus, string> = {
    matched: 'Pronto',
    duplicate: 'Já importado',
    divergent: 'Conferir data',
};

const CLASSES: Record<CandidateStatus, string> = {
    matched: 'bg-secondary/10 text-secondary border-transparent',
    duplicate: 'bg-muted text-muted-foreground border-transparent',
    divergent: 'bg-primary/20 text-status-upcoming border-transparent',
};

export function CandidateStatusChip({ status }: { status: CandidateStatus }) {
    return (
        <Badge variant="outline" className={`rounded-full ${CLASSES[status]}`}>
            {LABELS[status]}
        </Badge>
    );
}
```

Componente próprio, e não reuso de `StatusChip`: `Pago/Próximo/Pendente` e `matched/duplicate/divergent` são vocabulários diferentes, e sobrepor os dois confunde as duas telas.

- [ ] **Step 3: Escrever a tela de revisão**

`resources/js/pages/invoices/review.tsx`:

```tsx
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CandidateStatusChip } from '@/components/candidate-status-chip';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { formatBRL, formatDate } from '@/lib/currency';
import type { InvoiceCandidate } from '@/types/parcelamentos';

type ReviewProps = {
    issuer: string;
    due_date: string;
    card: { id: number; name: string; last_four_digits: string };
    candidates: InvoiceCandidate[];
};

export default function ReviewInvoice({
    issuer,
    due_date,
    card,
    candidates,
}: ReviewProps) {
    const [selected, setSelected] = useState<boolean[]>(() =>
        candidates.map((candidate) => candidate.status === 'matched'),
    );
    const [dates, setDates] = useState<string[]>(() =>
        candidates.map((candidate) => candidate.purchased_at),
    );

    const form = useForm({});

    const submit = () => {
        form.transform(() => ({
            lines: candidates
                .map((_, index) => ({ index, purchased_at: dates[index] }))
                .filter((_, index) => selected[index]),
        }));

        form.post('/importar/confirmar');
    };

    const selectedCount = selected.filter(Boolean).length;

    return (
        <>
            <Head title="Revisar importação" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Revisar importação"
                    description={`${issuer} · vencimento ${formatDate(due_date)} · ${card.name} •••• ${card.last_four_digits}`}
                />

                <Card>
                    <CardContent className="overflow-x-auto p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b text-left text-xs tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th className="p-3" />
                                    <th className="p-3">Item</th>
                                    <th className="p-3">Parcela</th>
                                    <th className="p-3">Valor da parcela</th>
                                    <th className="p-3">Total</th>
                                    <th className="p-3">Data da compra</th>
                                    <th className="p-3">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {candidates.map((candidate, index) => (
                                    <tr key={index} className="border-b">
                                        <td className="p-3">
                                            <Checkbox
                                                checked={selected[index]}
                                                disabled={
                                                    candidate.status ===
                                                    'duplicate'
                                                }
                                                onCheckedChange={(checked) =>
                                                    setSelected((current) =>
                                                        current.map((value, i) =>
                                                            i === index
                                                                ? checked === true
                                                                : value,
                                                        ),
                                                    )
                                                }
                                                aria-label={`Importar ${candidate.description}`}
                                            />
                                        </td>
                                        <td className="p-3">
                                            {candidate.description}
                                        </td>
                                        <td className="p-3 tabular-nums">
                                            {candidate.installment_number}/
                                            {candidate.installments_count}
                                        </td>
                                        <td className="p-3 tabular-nums">
                                            {formatBRL(
                                                candidate.installment_amount_cents,
                                            )}
                                        </td>
                                        <td className="p-3 tabular-nums">
                                            {formatBRL(
                                                candidate.total_amount_cents,
                                            )}
                                        </td>
                                        <td className="p-3">
                                            <Input
                                                type="date"
                                                value={dates[index]}
                                                className="w-40"
                                                onChange={(event) =>
                                                    setDates((current) =>
                                                        current.map((value, i) =>
                                                            i === index
                                                                ? event.target
                                                                      .value
                                                                : value,
                                                        ),
                                                    )
                                                }
                                            />
                                        </td>
                                        <td className="p-3">
                                            <CandidateStatusChip
                                                status={candidate.status}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <Button
                    onClick={submit}
                    disabled={selectedCount === 0 || form.processing}
                >
                    Importar {selectedCount}{' '}
                    {selectedCount === 1 ? 'compra' : 'compras'}
                </Button>
            </div>
        </>
    );
}
```

- [ ] **Step 4: Verificar tipos e formatação**

```bash
npm run build
npm run types:check
npm run format
npm run lint
```

Esperado: sem erros. `formatDate(iso: string)` e `formatBRL(cents: number)` já existem em `resources/js/lib/currency.ts` e aceitam exatamente os formatos usados acima.

- [ ] **Step 5: Commit**

```bash
git add resources/js
git commit -m "feat: tela de revisão da fatura importada"
```

---

### Task 8: Confirmação da importação

**Files:**
- Create: `app/Http/Requests/ConfirmInvoiceImportRequest.php`
- Modify: `app/Http/Controllers/InvoiceImportController.php`
- Modify: `routes/parcelamentos.php`
- Test: `tests/Feature/InvoiceImportTest.php`

**Interfaces:**
- Consumes: sessão `invoice_import` (Task 6), `GenerateInstallments` (existente).
- Produces: rota `invoice-imports.confirm`, que cria os `Purchase` e limpa a sessão.

- [ ] **Step 1: Escrever o teste que falha**

Adicione ao final de `tests/Feature/InvoiceImportTest.php`:

```php
it('confirma a importacao e grava as compras com as parcelas', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
        ])
        ->assertRedirect(route('history.index'));

    $purchase = \App\Models\Purchase::sole();

    expect($purchase->card_id)->toBe($card->id)
        ->and($purchase->total_amount_cents)->toBe(129_900)
        ->and($purchase->installments_count)->toBe(10)
        ->and($purchase->purchased_at->toDateString())->toBe('2026-03-02')
        ->and($purchase->installments()->count())->toBe(10)
        ->and($purchase->installments()->where('number', 3)->value('due_date'))
        ->toEqual(CarbonImmutable::parse('2026-05-10'));
});

it('respeita a data corrigida na revisao', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)->post(route('invoice-imports.confirm'), [
        'lines' => [['index' => 0, 'purchased_at' => '2026-02-20']],
    ]);

    expect(\App\Models\Purchase::sole()->purchased_at->toDateString())->toBe('2026-02-20');
});

it('nao importa duas vezes a mesma fatura', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);
    $this->actingAs($user)->post(route('invoice-imports.confirm'), [
        'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
    ]);

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)
        ->get(route('invoice-imports.review'))
        ->assertInertia(fn ($page) => $page->where('candidates.0.status', 'duplicate'));
});

it('recusa confirmacao sem sessao de importacao', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
        ])
        ->assertRedirect(route('invoice-imports.create'));
});

it('recusa indice fora da lista de candidatos', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 99, 'purchased_at' => '2026-03-02']],
        ])
        ->assertSessionHasErrors('lines.0.index');
});
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact --filter=InvoiceImport
```

Esperado: FAIL, rota `invoice-imports.confirm` não definida.

- [ ] **Step 3: Escrever o FormRequest**

`app/Http/Requests/ConfirmInvoiceImportRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmInvoiceImportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $import = $this->session()->get('invoice_import');
        $total = is_array($import) ? count($import['candidates']) : 0;

        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.index' => ['required', 'integer', 'min:0', 'lt:'.max($total, 1)],
            'lines.*.purchased_at' => ['required', 'date'],
        ];
    }
}
```

- [ ] **Step 4: Adicionar o método `confirm` ao controller**

Em `app/Http/Controllers/InvoiceImportController.php`, adicione o import `use App\Actions\GenerateInstallments;`, `use App\Http\Requests\ConfirmInvoiceImportRequest;`, `use App\Models\Purchase;`, `use Illuminate\Support\Facades\DB;` e o método:

```php
public function confirm(
    ConfirmInvoiceImportRequest $request,
    GenerateInstallments $generateInstallments,
): RedirectResponse {
    $import = $request->session()->get('invoice_import');

    if (! is_array($import)) {
        return to_route('invoice-imports.create');
    }

    $card = Card::query()
        ->where('user_id', $request->user()->id)
        ->findOrFail($import['card_id']);

    /** @var array<int, array{index: int, purchased_at: string}> $lines */
    $lines = $request->validated('lines');

    DB::transaction(function () use ($lines, $import, $card, $generateInstallments): void {
        foreach ($lines as $line) {
            $candidate = InvoiceCandidate::fromArray($import['candidates'][$line['index']]);

            if (! $candidate->status->isImportable()) {
                continue;
            }

            $purchase = Purchase::create([
                'card_id' => $card->id,
                'description' => $candidate->description,
                'merchant' => $candidate->merchant,
                'total_amount_cents' => $candidate->totalAmountCents,
                'installments_count' => $candidate->installmentsCount,
                'purchased_at' => $line['purchased_at'],
            ]);

            $generateInstallments->handle($purchase->setRelation('card', $card));
        }
    });

    $request->session()->forget('invoice_import');

    Inertia::flash('toast', [
        'type' => 'success',
        'message' => 'Importação concluída.',
    ]);

    return to_route('history.index');
}
```

Note o `setRelation('card', $card)` em vez de `load('card')`: evita uma query por linha importada.

- [ ] **Step 5: Adicionar a rota**

Em `routes/parcelamentos.php`, junto das outras de importação:

```php
Route::post('importar/confirmar', [InvoiceImportController::class, 'confirm'])->name('invoice-imports.confirm');
```

- [ ] **Step 6: Apontar o form da revisão para a rota nomeada**

Em `resources/js/pages/invoices/review.tsx`, troque `form.post('/importar/confirmar')` por:

```tsx
form.post(InvoiceImportController.confirm.url());
```

E adicione no topo:

```tsx
import InvoiceImportController from '@/actions/App/Http/Controllers/InvoiceImportController';
```

- [ ] **Step 7: Rodar e confirmar que passa**

```bash
npm run build
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=InvoiceImport
composer types:check
```

Esperado: PASS.

- [ ] **Step 8: Adicionar o link no menu**

Em `resources/js/components/app-sidebar.tsx`, adicione `FileUp` à lista de ícones importados de `lucide-react` (mantendo a ordem alfabética do bloco), adicione o import do controller:

```tsx
import InvoiceImportController from '@/actions/App/Http/Controllers/InvoiceImportController';
```

e acrescente o item ao final de `mainNavItems`, depois de "Histórico":

```tsx
    {
        title: 'Importar fatura',
        href: InvoiceImportController.create(),
        icon: FileUp,
    },
```

Usa o objeto do controller em vez de `@/routes/...` porque o nome da rota é hifenizado (`invoice-imports.create`) e o caminho gerado pelo Wayfinder para esse caso não é óbvio — o import do controller é o mesmo já usado nas telas.

- [ ] **Step 9: Rodar a suíte inteira e commitar**

```bash
composer run ci:check
git add app resources routes tests
git commit -m "feat: confirmação da importação de fatura"
```

---

### Tasks 9–12: Parsers por emissor

> **BLOQUEADO.** Estas quatro tasks não podem ser escritas com código concreto sem as faturas reais. O texto que o `pdftotext` produz para cada emissor determina os regex, as assinaturas de detecção e os valores esperados nos testes. Escrever regex de memória aqui produziria um plano que parece completo e falha no primeiro uso — o oposto do que este plano serve para fazer.

**Procedimento para destravar, uma vez por emissor:**

1. Rodar `pdftotext -layout fatura-real.pdf saida.txt` (com `-upw SENHA` se o PDF pedir).
2. Ler `saida.txt` e identificar: a assinatura do emissor, a linha do vencimento, os 4 dígitos do cartão, e o padrão das linhas parceladas.
3. Anonimizar: substituir nome, CPF, número completo do cartão, endereço e os nomes de estabelecimento reais por valores fictícios, **preservando o layout de colunas e o espaçamento** — é ele que o parser lê.
4. Salvar em `tests/Fixtures/faturas/<emissor>.txt`. O PDF original **não** entra no repositório.
5. Escrever o teste do parser contra a fixture, com os valores observados no passo 2.
6. Implementar o parser até o teste passar.
7. Commitar fixture + teste + parser juntos.

**Contrato que cada parser precisa cumprir** (definido na Task 5):

```php
public function supports(string $text): bool;   // assinatura do emissor
public function parse(string $text): ParsedInvoice;
```

`parse()` lança `UnreadableInvoiceException` quando não acha o vencimento. Retorna `ParsedInvoice` com `lines` contendo **apenas** linhas com marcação de parcela — compra à vista é descartada, conforme a decisão 4 do spec.

**Ordem sugerida:** Nubank (layout mais simples) → Mercado Pago → Renner → Itaú (colunas + possível senha).

Ao concluir a Task 12, trocar em `AppServiceProvider` a lista vazia pela lista com os quatro parsers, e rodar `composer run ci:check`.

---

## Verificação final

Depois da Task 12:

```bash
composer run ci:check
```

Tem que passar inteiro: eslint, prettier, tsc, pint, phpstan nível 7 e Pest.
