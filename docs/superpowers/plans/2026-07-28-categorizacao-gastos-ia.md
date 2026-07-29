# Categorização de Gastos por IA — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** capturar as compras à vista da fatura, classificar todo gasto por categoria com um modelo local via Ollama, e apresentar isso em gráficos numa tela nova.

**Architecture:** os parsers passam a devolver um segundo array de linhas (`cashLines`) ao lado das parceladas que já devolvem; essas linhas viram registros numa tabela própria, gravados na confirmação da importação, com índice único que impede duplicata na reimportação. Um job de fila classifica tudo que estiver sem categoria usando um agente do `laravel/ai`, cujo provedor é configuração. Uma tela nova soma por mês de cobrança e desenha barras horizontais ordenadas e barras empilhadas.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, Inertia v3 + React 19, recharts 3, `laravel/ai` v0.10.1, Ollama com `gemma4:e4b`.

**Spec:** `docs/superpowers/specs/2026-07-28-categorizacao-gastos-ia-design.md`

## Global Constraints

- **Nada de mudança na `main`.** O trabalho corre na branch `gastos-categorizacao-ia`, que já existe e já tem o commit do spec.
- **Nenhum teste depende de Ollama rodando.** A CI não tem Ollama. Onde houver IA, use `CategorizeExpenses::fake()`.
- **A importação de parcelamentos não pode regredir.** Todos os testes de `tests/Unit/*InvoiceParserTest.php` e `tests/Feature/InvoiceImportTest.php` devem continuar passando sem edição, exceto onde este plano mandar editar explicitamente.
- **Valor zero ou negativo nunca vira compra à vista.** É descartado no extrator, antes de chegar ao construtor de `ParsedCashLine`. Foi assim que se descobriu a linha "Saldo restante da fatura anterior — 0,00" da fatura Nubank, que de outra forma derrubaria a importação inteira com `UnreadableInvoiceException`.
- **Comentários documentam o código, não o processo.** Nada de "melhoria em relação à versão anterior". Escreva em português, no tom dos arquivos vizinhos: explique o *porquê* de uma decisão não óbvia, nunca o *o quê* que o código já diz.
- **`vendor/bin/pint --dirty --format agent` antes de cada commit** que toque PHP.
- **Contagens de referência verificadas nas fixtures:** Nubank 53 linhas à vista, Renner 21, Mercado Pago 54, Itaú 26. Itaú mantém as 19 parceladas que o teste atual já cobra.

---

### Task 1: `ParsedCashLine` e o segundo array em `ParsedInvoice`

A fatura passa a carregar dois tipos de linha. As parceladas não mudam.

**Files:**
- Create: `app/Invoices/ParsedCashLine.php`
- Modify: `app/Invoices/ParsedInvoice.php`
- Test: `tests/Unit/ParsedCashLineTest.php`

**Interfaces:**
- Consumes: `App\Invoices\UnreadableInvoiceException` (já existe).
- Produces: `ParsedCashLine(string $description, int $transactionDay, int $transactionMonth, int $amountCents)` e `ParsedInvoice::$cashLines` (`array<int, ParsedCashLine>`, default `[]`).

- [ ] **Step 1: Escrever o teste que falha**

`tests/Unit/ParsedCashLineTest.php`:

```php
<?php

use App\Invoices\ParsedCashLine;
use App\Invoices\UnreadableInvoiceException;

it('aceita uma linha bem formada', function (): void {
    $line = new ParsedCashLine(
        description: 'Padaria do Bairro',
        transactionDay: 5,
        transactionMonth: 6,
        amountCents: 1_990,
    );

    expect($line->description)->toBe('Padaria do Bairro')
        ->and($line->transactionDay)->toBe(5)
        ->and($line->transactionMonth)->toBe(6)
        ->and($line->amountCents)->toBe(1_990);
});

it('recusa valor que nao e positivo', function (int $amountCents): void {
    new ParsedCashLine('Padaria', 5, 6, $amountCents);
})->with([0, -100])->throws(UnreadableInvoiceException::class);

it('recusa dia fora do mes', function (int $day): void {
    new ParsedCashLine('Padaria', $day, 6, 1_990);
})->with([0, 32])->throws(UnreadableInvoiceException::class);

it('recusa mes fora do ano', function (int $month): void {
    new ParsedCashLine('Padaria', 5, $month, 1_990);
})->with([0, 13])->throws(UnreadableInvoiceException::class);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact tests/Unit/ParsedCashLineTest.php`
Expected: FAIL com "Class App\Invoices\ParsedCashLine not found".

- [ ] **Step 3: Criar a classe**

`app/Invoices/ParsedCashLine.php`:

```php
<?php

namespace App\Invoices;

readonly class ParsedCashLine
{
    /**
     * As invariantes ficam no construtor pelo mesmo motivo de
     * ParsedInvoiceLine: o app nao guarda o PDF, entao uma linha que chegasse
     * malformada aqui viraria um registro impossivel de reproduzir depois.
     *
     * Os extratores garantem que nada fora dessas faixas chega ate aqui — o
     * proprio regex recusa mes invalido, e valor zero ou negativo e descartado
     * antes. Estas guardas existem para o dia em que um regex for afrouxado.
     *
     * @throws UnreadableInvoiceException
     */
    public function __construct(
        public string $description,
        public int $transactionDay,
        public int $transactionMonth,
        public int $amountCents,
    ) {
        if ($amountCents < 1) {
            throw new UnreadableInvoiceException(
                'Não foi possível ler o valor de uma das compras à vista desta fatura.',
            );
        }

        if ($transactionDay < 1 || $transactionDay > 31) {
            throw new UnreadableInvoiceException(
                'Não foi possível ler a data de uma das compras à vista desta fatura.',
            );
        }

        if ($transactionMonth < 1 || $transactionMonth > 12) {
            throw new UnreadableInvoiceException(
                'Não foi possível ler o mês de uma das compras à vista desta fatura.',
            );
        }
    }
}
```

- [ ] **Step 4: Adicionar `cashLines` ao `ParsedInvoice`**

`app/Invoices/ParsedInvoice.php` — substituir a classe inteira:

```php
<?php

namespace App\Invoices;

use Carbon\CarbonImmutable;

readonly class ParsedInvoice
{
    /**
     * Duas listas separadas em vez de uma lista com tipo misto: a linha
     * parcelada vira compromisso futuro e passa por revisao, a linha a vista
     * vira fato passado e e gravada direto. Sao dois destinos, e juntar os
     * dois num array so obrigaria todo consumidor a filtrar antes de usar.
     *
     * @param  array<int, ParsedInvoiceLine>  $lines
     * @param  array<int, ParsedCashLine>  $cashLines
     */
    public function __construct(
        public string $issuer,
        public CarbonImmutable $dueDate,
        public ?string $lastFourDigits,
        public array $lines,
        public array $cashLines = [],
    ) {}
}
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --compact tests/Unit/ParsedCashLineTest.php`
Expected: PASS, 8 testes.

- [ ] **Step 6: Garantir que nada regrediu**

Run: `php artisan test --compact tests/Unit`
Expected: PASS. `cashLines` tem default `[]`, então os quatro parsers continuam construindo `ParsedInvoice` como antes.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Invoices/ParsedCashLine.php app/Invoices/ParsedInvoice.php tests/Unit/ParsedCashLineTest.php
git commit -m "feat: linha de compra a vista na fatura parseada"
```

---

### Task 2: Extração à vista do Nubank

**Files:**
- Modify: `app/Invoices/Parsers/NubankInvoiceParser.php`
- Test: `tests/Unit/NubankInvoiceParserTest.php`

**Interfaces:**
- Consumes: `ParsedCashLine` da Task 1.
- Produces: `NubankInvoiceParser::parse()` passa a preencher `ParsedInvoice::$cashLines`.

- [ ] **Step 1: Escrever os testes que falham**

Adicionar ao fim de `tests/Unit/NubankInvoiceParserTest.php` (a função `nubankFixture()` já existe no arquivo — reutilize-a):

```php
it('extrai as compras a vista', function (): void {
    $cashLines = (new NubankInvoiceParser)->parse(nubankFixture())->cashLines;

    expect($cashLines)->toHaveCount(53);

    foreach ($cashLines as $line) {
        expect($line->amountCents)->toBeGreaterThan(0)
            ->and($line->transactionDay)->toBeBetween(1, 31)
            ->and($line->transactionMonth)->toBeBetween(1, 12)
            ->and($line->description)->not->toContain('Parcela');
    }
});

it('mantem a compra a vista de um estabelecimento que tambem tem parcelada', function (): void {
    $invoice = (new NubankInvoiceParser)->parse(nubankFixture());

    $repeated = 'Vuvepepedelu*Tomudibe';

    $cashDescriptions = array_map(fn ($line): string => $line->description, $invoice->cashLines);
    $installmentDescriptions = array_map(fn ($line): string => $line->description, $invoice->lines);

    expect($cashDescriptions)->toContain($repeated)
        ->and($installmentDescriptions)->toContain($repeated);
});

it('ignora pagamento recebido e saldo remanescente', function (): void {
    $descriptions = array_map(
        fn ($line): string => mb_strtolower($line->description),
        (new NubankInvoiceParser)->parse(nubankFixture())->cashLines,
    );

    foreach ($descriptions as $description) {
        expect($description)->not->toContain('pagamento recebido')
            ->and($description)->not->toContain('saldo restante');
    }
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact tests/Unit/NubankInvoiceParserTest.php`
Expected: FAIL — `toHaveCount(53)` recebe 0.

- [ ] **Step 3: Implementar a extração**

Em `app/Invoices/Parsers/NubankInvoiceParser.php`, adicionar a constante junto das outras:

```php
    /**
     * A linha a vista e a mesma da parcelada sem o sufixo "- Parcela N/M".
     * A distincao nao pode ser feita so por este padrao, que casaria tambem a
     * linha parcelada engolindo o sufixo dentro da descricao: por isso o
     * extrator testa o padrao parcelado primeiro e pula quem casar.
     */
    private const CASH_LINE_PATTERN = '/^\s*(\d{2})\s+(JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\s+(?:••••\s*\d{4}\s+)?(.+?)\s+(−)?R\$\s*([\d.,]+)\s*$/u';
```

Trocar o corpo de `parse()` para preencher as duas listas:

```php
    public function parse(string $text): ParsedInvoice
    {
        return new ParsedInvoice(
            issuer: self::ISSUER,
            dueDate: $this->extractDueDate($text),
            lastFourDigits: null,
            lines: $this->extractLines($text),
            cashLines: $this->extractCashLines($text),
        );
    }
```

E adicionar o método:

```php
    /**
     * O sinal de menos aqui e o U+2212 da fatura, nao o hifen do teclado: ele
     * marca credito — pagamento recebido, estorno — que nao e gasto.
     *
     * O valor zero tem dono conhecido: a linha "Saldo restante da fatura
     * anterior", que casa o padrao de transacao mas nao e compra. Descartar
     * aqui, e nao no construtor, e o que impede que ela derrube a importacao
     * inteira de uma fatura legitima.
     *
     * @return array<int, ParsedCashLine>
     */
    private function extractCashLines(string $text): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $rawLine) {
            if (preg_match(self::INSTALLMENT_LINE_PATTERN, $rawLine)) {
                continue;
            }

            if (! preg_match(self::CASH_LINE_PATTERN, $rawLine, $matches)) {
                continue;
            }

            if ($matches[4] !== '') {
                continue;
            }

            $amountCents = $this->parseAmountCents($matches[5]);

            if ($amountCents < 1) {
                continue;
            }

            $lines[] = new ParsedCashLine(
                description: trim($matches[3]),
                transactionDay: (int) $matches[1],
                transactionMonth: self::MONTHS[$matches[2]],
                amountCents: $amountCents,
            );
        }

        return $lines;
    }
```

Adicionar `use App\Invoices\ParsedCashLine;` ao topo do arquivo.

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --compact tests/Unit/NubankInvoiceParserTest.php`
Expected: PASS, incluindo os testes que já existiam.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Invoices/Parsers/NubankInvoiceParser.php tests/Unit/NubankInvoiceParserTest.php
git commit -m "feat: extracao de compras a vista na fatura do nubank"
```

---

### Task 3: Extração à vista do Renner

**Files:**
- Modify: `app/Invoices/Parsers/RennerInvoiceParser.php`
- Test: `tests/Unit/RennerInvoiceParserTest.php`

**Interfaces:**
- Consumes: `ParsedCashLine` da Task 1.
- Produces: `RennerInvoiceParser::parse()` preenche `cashLines`.

- [ ] **Step 1: Escrever os testes que falham**

Adicionar ao fim de `tests/Unit/RennerInvoiceParserTest.php` (reutilize a função de fixture que já existe no arquivo):

```php
it('extrai as compras a vista', function (): void {
    $cashLines = (new RennerInvoiceParser)->parse(rennerFixture())->cashLines;

    expect($cashLines)->toHaveCount(21);

    foreach ($cashLines as $line) {
        expect($line->amountCents)->toBeGreaterThan(0)
            ->and($line->description)->not->toContain('Compra a Vista');
    }
});

it('ignora pagamento de fatura', function (): void {
    $descriptions = array_map(
        fn ($line): string => mb_strtolower($line->description),
        (new RennerInvoiceParser)->parse(rennerFixture())->cashLines,
    );

    foreach ($descriptions as $description) {
        expect($description)->not->toContain('pagamento');
    }
});

it('nao le a vista das proximas faturas', function (): void {
    $text = rennerFixture()
        ."\n01/09/2026    Compra a Vista                       LOJA DA PROXIMA FATURA                                    99,00\n";

    expect((new RennerInvoiceParser)->parse($text)->cashLines)->toHaveCount(21);
});

it('descarta uma compra a vista com valor negativo', function (): void {
    $negative = "26/03/2026    Compra a Vista                       ESTORNO DE COMPRA                                        -50,00";

    $text = str_replace(
        'Compras parceladas | Próximas faturas:',
        $negative."\nCompras parceladas | Próximas faturas:",
        rennerFixture(),
    );

    expect((new RennerInvoiceParser)->parse($text)->cashLines)->toHaveCount(21);
});
```

> A linha negativa é inserida **antes** do marcador de propósito. Acrescentá-la ao fim do texto a colocaria depois do corte, e o teste passaria porque a linha foi cortada — não porque o sinal foi reconhecido.

> O marcador `Compras parceladas | Próximas faturas:` fica no meio da fixture, então a linha acrescentada no fim cai depois dele. Sem o corte a contagem iria a 22; com o corte fica em 21. A fixture, sozinha, não prova nada sobre o corte: ela não tem nenhuma linha à vista depois do marcador, e um teste que apenas a comparasse consigo mesma passaria de qualquer jeito.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact tests/Unit/RennerInvoiceParserTest.php`
Expected: FAIL — `toHaveCount(21)` recebe 0.

- [ ] **Step 3: Implementar a extração**

Em `app/Invoices/Parsers/RennerInvoiceParser.php`, adicionar a constante:

```php
    /**
     * A Renner rotula a compra avulsa no proprio texto, o que dispensa
     * qualquer inferencia por ausencia de sufixo.
     */
    private const CASH_LINE_PATTERN = '/^(\d{2})\/(\d{2})\/(\d{4})\s+Compra a Vista\s+(.+?)\s{2,}(-?)(\d{1,3}(?:\.\d{3})*,\d{2})\s*$/u';
```

Trocar `parse()` para incluir `cashLines: $this->extractCashLines($this->cutFutureInstallments($text)),` — o mesmo corte que `extractLines()` já aplica — e adicionar:

```php
    /**
     * @return array<int, ParsedCashLine>
     */
    private function extractCashLines(string $text): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $rawLine) {
            if (! preg_match(self::CASH_LINE_PATTERN, $rawLine, $matches)) {
                continue;
            }

            if ($matches[5] !== '') {
                continue;
            }

            $amountCents = $this->parseAmountCents($matches[6]);

            if ($amountCents < 1) {
                continue;
            }

            $lines[] = new ParsedCashLine(
                description: trim($matches[4]),
                transactionDay: (int) $matches[1],
                transactionMonth: (int) $matches[2],
                amountCents: $amountCents,
            );
        }

        return $lines;
    }
```

Confira como `parse()` chama `extractLines` hoje: se o corte já é aplicado dentro de `extractLines`, aplique-o também dentro de `extractCashLines` em vez de no `parse()`, para manter as duas simétricas. Adicionar `use App\Invoices\ParsedCashLine;`.

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --compact tests/Unit/RennerInvoiceParserTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Invoices/Parsers/RennerInvoiceParser.php tests/Unit/RennerInvoiceParserTest.php
git commit -m "feat: extracao de compras a vista na fatura da renner"
```

---

### Task 4: Extração à vista do Mercado Pago

**Files:**
- Modify: `app/Invoices/Parsers/MercadoPagoInvoiceParser.php`
- Test: `tests/Unit/MercadoPagoInvoiceParserTest.php`

**Interfaces:**
- Consumes: `ParsedCashLine` da Task 1.
- Produces: `MercadoPagoInvoiceParser::parse()` preenche `cashLines`.

- [ ] **Step 1: Escrever os testes que falham**

Adicionar ao fim de `tests/Unit/MercadoPagoInvoiceParserTest.php` (reutilize a função de fixture do arquivo):

```php
it('extrai as compras a vista', function (): void {
    $cashLines = (new MercadoPagoInvoiceParser)->parse(mercadoPagoFixture())->cashLines;

    expect($cashLines)->toHaveCount(54);

    foreach ($cashLines as $line) {
        expect($line->amountCents)->toBeGreaterThan(0)
            ->and($line->description)->not->toContain('Parcela');
    }
});

it('ignora o pagamento da fatura anterior', function (): void {
    $descriptions = array_map(
        fn ($line): string => mb_strtolower($line->description),
        (new MercadoPagoInvoiceParser)->parse(mercadoPagoFixture())->cashLines,
    );

    foreach ($descriptions as $description) {
        expect($description)->not->toStartWith('pagamento');
    }
});

it('descarta uma compra a vista com valor negativo', function (): void {
    $text = mercadoPagoFixture()."\n15/06      ESTORNO DE COMPRA                                        -R\$ 50,00\n";

    expect((new MercadoPagoInvoiceParser)->parse($text)->cashLines)->toHaveCount(54);
});
```

> O segundo teste prova o filtro por texto (o pagamento da fatura vem com valor positivo e só a descrição o denuncia); o terceiro prova o filtro por sinal. São dois caminhos de exclusão diferentes e um não cobre o outro.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact tests/Unit/MercadoPagoInvoiceParserTest.php`
Expected: FAIL — `toHaveCount(54)` recebe 0.

- [ ] **Step 3: Implementar a extração**

Em `app/Invoices/Parsers/MercadoPagoInvoiceParser.php`, adicionar:

```php
    /**
     * A linha a vista e a mesma da parcelada sem o trecho "Parcela N de M".
     * O padrao parcelado e testado primeiro pelo mesmo motivo do Nubank: sem
     * isso a descricao engoliria o trecho e a parcela viraria compra avulsa.
     */
    private const CASH_LINE_PATTERN = '/^\s*(\d{2})\/(\d{2})\s+(.+?)\s+(-)?R\$\s*([\d.,]+)\s*$/u';

    /**
     * O pagamento da fatura anterior aparece com valor positivo, sem sinal que
     * o distinga de uma compra. So o texto o identifica.
     */
    private const PAYMENT_PREFIX = 'pagamento';
```

Trocar `parse()` para incluir `cashLines: $this->extractCashLines($text),` e adicionar:

```php
    /**
     * @return array<int, ParsedCashLine>
     */
    private function extractCashLines(string $text): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $rawLine) {
            if (preg_match(self::INSTALLMENT_LINE_PATTERN, $rawLine)) {
                continue;
            }

            if (! preg_match(self::CASH_LINE_PATTERN, $rawLine, $matches)) {
                continue;
            }

            if ($matches[4] !== '') {
                continue;
            }

            $description = trim($matches[3]);

            if (str_starts_with(mb_strtolower($description), self::PAYMENT_PREFIX)) {
                continue;
            }

            $amountCents = $this->parseAmountCents($matches[5]);

            if ($amountCents < 1) {
                continue;
            }

            $lines[] = new ParsedCashLine(
                description: $description,
                transactionDay: (int) $matches[1],
                transactionMonth: (int) $matches[2],
                amountCents: $amountCents,
            );
        }

        return $lines;
    }
```

Adicionar `use App\Invoices\ParsedCashLine;`.

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --compact tests/Unit/MercadoPagoInvoiceParserTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Invoices/Parsers/MercadoPagoInvoiceParser.php tests/Unit/MercadoPagoInvoiceParserTest.php
git commit -m "feat: extracao de compras a vista na fatura do mercado pago"
```

---

### Task 5: Extração à vista do Itaú

O Itaú é o único de layout em duas colunas: a coluna esquerda traz pagamentos com valor negativo e a direita traz compras. Uma mesma linha de texto pode conter as duas, por isso `preg_match_all`.

**Files:**
- Modify: `app/Invoices/Parsers/ItauInvoiceParser.php`
- Test: `tests/Unit/ItauInvoiceParserTest.php`

**Interfaces:**
- Consumes: `ParsedCashLine` da Task 1.
- Produces: `ItauInvoiceParser::parse()` preenche `cashLines`.

- [ ] **Step 1: Escrever os testes que falham**

Adicionar ao fim de `tests/Unit/ItauInvoiceParserTest.php`:

```php
it('extrai as compras a vista', function (): void {
    $cashLines = (new ItauInvoiceParser)->parse(itauFixture())->cashLines;

    expect($cashLines)->toHaveCount(26);

    foreach ($cashLines as $line) {
        expect($line->amountCents)->toBeGreaterThan(0)
            ->and($line->description)->not->toMatch('/ \d{1,2}\/\d{1,2}$/');
    }
});

it('ignora a coluna de pagamentos', function (): void {
    $descriptions = array_map(
        fn ($line): string => mb_strtolower($line->description),
        (new ItauInvoiceParser)->parse(itauFixture())->cashLines,
    );

    foreach ($descriptions as $description) {
        expect($description)->not->toContain('pagamento');
    }
});

it('continua lendo as mesmas parceladas depois de ler as a vista', function (): void {
    expect((new ItauInvoiceParser)->parse(itauFixture())->lines)->toHaveCount(19);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact tests/Unit/ItauInvoiceParserTest.php`
Expected: FAIL — `toHaveCount(26)` recebe 0. O terceiro teste já passa.

- [ ] **Step 3: Implementar a extração**

Em `app/Invoices/Parsers/ItauInvoiceParser.php`, adicionar:

```php
    /**
     * O layout tem duas colunas por linha de texto, entao uma linha pode
     * conter pagamento a esquerda e compra a direita: dai o preg_match_all.
     *
     * O padrao nao consegue, sozinho, distinguir compra avulsa de parcelada.
     * A descricao de uma linha parcelada sai capturada com o "N/M" dentro
     * dela: entre o nome do estabelecimento e o marcador ha um espaco so, e a
     * descricao preguicosa so pode parar diante de dois ou mais espacos
     * seguidos do valor. Por isso o sufixo e testado depois, contra a
     * descricao ja capturada, em vez de dentro do padrao.
     */
    private const CASH_LINE_PATTERN = '/(?<!\d)(\d{2})\/(\d{2}) {2,}(\S.*?) {2,}(-?)(\d{1,3}(?:\.\d{3})*,\d{2})(?!\d)/u';

    private const INSTALLMENT_SUFFIX_PATTERN = '/ \d{1,2}\/\d{1,2}$/u';
```

Trocar `parse()` para incluir `cashLines: $this->extractCashLines($this->cutFutureInstallments($text)),` — espelhando o que `extractLines()` já faz — e adicionar:

```php
    /**
     * @return array<int, ParsedCashLine>
     */
    private function extractCashLines(string $text): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $rawLine) {
            preg_match_all(self::CASH_LINE_PATTERN, $rawLine, $allMatches, PREG_SET_ORDER);

            foreach ($allMatches as $matches) {
                $description = rtrim($matches[3]);

                if (preg_match(self::INSTALLMENT_SUFFIX_PATTERN, $description)) {
                    continue;
                }

                if ($matches[4] !== '') {
                    continue;
                }

                $amountCents = $this->parseAmountCents($matches[5]);

                if ($amountCents < 1) {
                    continue;
                }

                $lines[] = new ParsedCashLine(
                    description: $description,
                    transactionDay: (int) $matches[1],
                    transactionMonth: (int) $matches[2],
                    amountCents: $amountCents,
                );
            }
        }

        return $lines;
    }
```

Confira onde `cutFutureInstallments()` é aplicado hoje e aplique-o do mesmo jeito para as linhas à vista. Adicionar `use App\Invoices\ParsedCashLine;`.

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --compact tests/Unit/ItauInvoiceParserTest.php`
Expected: PASS.

- [ ] **Step 5: Rodar toda a suíte de parsers**

Run: `php artisan test --compact tests/Unit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Invoices/Parsers/ItauInvoiceParser.php tests/Unit/ItauInvoiceParserTest.php
git commit -m "feat: extracao de compras a vista na fatura do itau"
```

---

### Task 6: O enum `ExpenseCategory`

Fonte única da taxonomia: alimenta o schema do agente, o rótulo da tela e a cor do gráfico. A tela recebe a lista pronta do servidor, como já acontece com `LineColor`.

**Files:**
- Create: `app/Enums/ExpenseCategory.php`
- Test: `tests/Unit/ExpenseCategoryTest.php`

**Interfaces:**
- Produces: `ExpenseCategory` (enum de string) com `label(): string`, `hint(): string`, `color(): string`, e o estático `values(): array<int, string>`.

- [ ] **Step 1: Escolher a paleta**

**REQUIRED SUB-SKILL:** invoque a skill `dataviz` antes de escrever os hexadecimais. São doze séries categóricas que precisam ser distinguíveis entre si e legíveis no tema claro e no escuro — é exatamente o problema que aquela skill resolve. Não invente doze cores no olho.

- [ ] **Step 2: Escrever o teste que falha**

`tests/Unit/ExpenseCategoryTest.php`:

```php
<?php

use App\Enums\ExpenseCategory;

it('tem doze categorias', function (): void {
    expect(ExpenseCategory::cases())->toHaveCount(12);
});

it('expoe os valores como lista de string', function (): void {
    expect(ExpenseCategory::values())
        ->toBeArray()
        ->toContain('mercado', 'outros')
        ->toHaveCount(12);
});

it('da rotulo, dica e cor a cada categoria', function (): void {
    foreach (ExpenseCategory::cases() as $category) {
        expect($category->label())->not->toBeEmpty()
            ->and($category->hint())->not->toBeEmpty()
            ->and($category->color())->toMatch('/^#[0-9a-f]{6}$/');
    }
});

it('nao repete cor entre categorias', function (): void {
    $colors = array_map(
        fn (ExpenseCategory $category): string => $category->color(),
        ExpenseCategory::cases(),
    );

    expect(array_unique($colors))->toHaveCount(12);
});
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `php artisan test --compact tests/Unit/ExpenseCategoryTest.php`
Expected: FAIL com "Class App\Enums\ExpenseCategory not found".

- [ ] **Step 4: Criar o enum**

`app/Enums/ExpenseCategory.php` — troque os hexadecimais pelos que a skill `dataviz` produzir:

```php
<?php

namespace App\Enums;

/**
 * A taxonomia de gastos. Esta e a unica definicao: o schema do agente entra
 * no JSON Schema a partir daqui, o que faz o proprio runtime recusar rotulo
 * fora da lista, e a tela recebe rotulo e cor prontos do servidor em vez de
 * manter uma copia que sairia de sincronia em silencio.
 *
 * A dica de cada caso nao e documentacao: ela vai literalmente para dentro das
 * instrucoes do agente. Mexer nela muda a classificacao.
 */
enum ExpenseCategory: string
{
    case Mercado = 'mercado';
    case RestaurantesELanches = 'restaurantes-e-lanches';
    case Combustivel = 'combustivel';
    case Transporte = 'transporte';
    case Saude = 'saude';
    case Casa = 'casa';
    case Eletronicos = 'eletronicos';
    case Vestuario = 'vestuario';
    case Assinaturas = 'assinaturas';
    case Lazer = 'lazer';
    case Viagem = 'viagem';
    case Outros = 'outros';

    public function label(): string
    {
        return match ($this) {
            self::Mercado => 'Mercado',
            self::RestaurantesELanches => 'Restaurantes e lanches',
            self::Combustivel => 'Combustível',
            self::Transporte => 'Transporte',
            self::Saude => 'Saúde',
            self::Casa => 'Casa',
            self::Eletronicos => 'Eletrônicos',
            self::Vestuario => 'Vestuário',
            self::Assinaturas => 'Assinaturas',
            self::Lazer => 'Lazer',
            self::Viagem => 'Viagem',
            self::Outros => 'Outros',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Mercado => 'supermercado, hortifruti, açougue, padaria',
            self::RestaurantesELanches => 'restaurante, bar, lanchonete, delivery',
            self::Combustivel => 'posto de combustível, abastecimento',
            self::Transporte => 'aplicativo de corrida, estacionamento, pedágio, ônibus',
            self::Saude => 'farmácia, consulta, exame, plano de saúde',
            self::Casa => 'móveis, utilidades domésticas, material de construção, contas da casa',
            self::Eletronicos => 'celular, computador, periférico, eletrônico em geral',
            self::Vestuario => 'roupa, calçado, acessório',
            self::Assinaturas => 'streaming, armazenamento em nuvem, software por assinatura',
            self::Lazer => 'cinema, show, jogo, academia',
            self::Viagem => 'passagem aérea, hotel, hospedagem',
            self::Outros => 'qualquer gasto que não caiba nas demais, e tudo que a descrição não permitir identificar',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Mercado => '#000000',
            self::RestaurantesELanches => '#000000',
            self::Combustivel => '#000000',
            self::Transporte => '#000000',
            self::Saude => '#000000',
            self::Casa => '#000000',
            self::Eletronicos => '#000000',
            self::Vestuario => '#000000',
            self::Assinaturas => '#000000',
            self::Lazer => '#000000',
            self::Viagem => '#000000',
            self::Outros => '#000000',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            fn (self $category): string => $category->value,
            self::cases(),
        );
    }
}
```

> Os `#000000` são o único lugar deste plano onde falta um valor de propósito: eles vêm da skill `dataviz` no Step 1. O teste "não repete cor entre categorias" falha enquanto eles estiverem lá, o que impede que passem despercebidos.

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --compact tests/Unit/ExpenseCategoryTest.php`
Expected: PASS, 4 testes.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/ExpenseCategory.php tests/Unit/ExpenseCategoryTest.php
git commit -m "feat: taxonomia de categorias de gasto"
```

---

### Task 7: Tabela `cash_purchases`, modelo, fábrica e colunas em `purchases`

**Files:**
- Create: migração `create_cash_purchases_table` (via artisan)
- Create: migração `add_category_to_purchases_table` (via artisan)
- Create: `app/Models/CashPurchase.php`
- Create: `database/factories/CashPurchaseFactory.php`
- Modify: `app/Models/Purchase.php`
- Test: `tests/Feature/CashPurchaseTest.php`

**Interfaces:**
- Consumes: `ExpenseCategory` da Task 6.
- Produces: `CashPurchase` com `card()`, `scopeForUser(Builder, User)`, casts de `purchased_at`/`billed_at` para `immutable_date`, `category` para `ExpenseCategory`, `category_is_manual` para `boolean`. `Purchase` ganha `scopeForUser(Builder, User)` e os mesmos dois campos.

- [ ] **Step 1: Gerar as migrações**

```bash
php artisan make:migration create_cash_purchases_table --no-interaction
php artisan make:migration add_category_to_purchases_table --no-interaction
```

- [ ] **Step 2: Escrever o teste que falha**

`tests/Feature/CashPurchaseTest.php`:

```php
<?php

use App\Enums\ExpenseCategory;
use App\Models\CashPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('grava uma compra a vista', function (): void {
    $cash = CashPurchase::factory()->create([
        'description' => 'Padaria do Bairro',
        'amount_cents' => 1_990,
    ]);

    expect($cash->description)->toBe('Padaria do Bairro')
        ->and($cash->amount_cents)->toBe(1_990)
        ->and($cash->category)->toBeNull()
        ->and($cash->category_is_manual)->toBeFalse();
});

it('converte a categoria para o enum', function (): void {
    $cash = CashPurchase::factory()->create(['category' => ExpenseCategory::Mercado]);

    expect($cash->fresh()->category)->toBe(ExpenseCategory::Mercado);
});

it('recusa duas compras identicas no mesmo cartao e dia', function (): void {
    $first = CashPurchase::factory()->create([
        'description' => 'Padaria do Bairro',
        'amount_cents' => 1_990,
        'purchased_at' => '2026-06-05',
    ]);

    $duplicate = [
        'card_id' => $first->card_id,
        'description' => 'Padaria do Bairro',
        'amount_cents' => 1_990,
        'purchased_at' => '2026-06-05',
        'billed_at' => '2026-07-10',
        'category_is_manual' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('cash_purchases')->insertOrIgnore([$duplicate]);

    expect(CashPurchase::query()->count())->toBe(1);
});

it('grava a data como data pura mesmo recebendo um objeto de data', function (): void {
    $cash = CashPurchase::factory()->create([
        'purchased_at' => CarbonImmutable::parse('2026-06-05 14:32:10'),
        'billed_at' => CarbonImmutable::parse('2026-07-10 09:00:00'),
    ]);

    $raw = DB::table('cash_purchases')->where('id', $cash->id)->first();

    expect($raw->purchased_at)->toBe('2026-06-05')
        ->and($raw->billed_at)->toBe('2026-07-10')
        ->and($cash->fresh()->purchased_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('recusa duplicata quando a primeira compra veio de um objeto de data', function (): void {
    $first = CashPurchase::factory()->create([
        'description' => 'Padaria do Bairro',
        'amount_cents' => 1_990,
        'purchased_at' => CarbonImmutable::parse('2026-06-05 14:32:10'),
    ]);

    DB::table('cash_purchases')->insertOrIgnore([[
        'card_id' => $first->card_id,
        'description' => 'Padaria do Bairro',
        'amount_cents' => 1_990,
        'purchased_at' => '2026-06-05',
        'billed_at' => '2026-07-10',
        'category_is_manual' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]]);

    expect(CashPurchase::query()->count())->toBe(1);
});

it('so enxerga as compras dos cartoes do proprio usuario', function (): void {
    $user = User::factory()->create();
    $mine = CashPurchase::factory()->forUser($user)->create();
    CashPurchase::factory()->create();

    expect(CashPurchase::query()->forUser($user)->pluck('id')->all())->toBe([$mine->id]);
});
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `php artisan test --compact tests/Feature/CashPurchaseTest.php`
Expected: FAIL com "Class App\Models\CashPurchase not found".

- [ ] **Step 4: Escrever a migração da tabela**

No arquivo `*_create_cash_purchases_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('amount_cents');
            $table->date('purchased_at');
            $table->date('billed_at');
            $table->string('category')->nullable();
            $table->boolean('category_is_manual')->default(false);
            $table->timestamps();

            $table->index(['card_id', 'billed_at']);

            /**
             * Reimportar a mesma fatura duplicaria o gasto e o grafico passaria
             * a mentir sem avisar. O unico no banco e a garantia que codigo
             * esquecido nao desfaz.
             *
             * O custo aceito: dois gastos identicos, no mesmo dia, no mesmo
             * estabelecimento e no mesmo valor viram um so.
             */
            $table->unique(['card_id', 'purchased_at', 'amount_cents', 'description'], 'cash_purchases_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_purchases');
    }
};
```

- [ ] **Step 5: Escrever a migração das colunas em `purchases`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('category')->nullable()->after('purchased_at');
            $table->boolean('category_is_manual')->default(false)->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['category', 'category_is_manual']);
        });
    }
};
```

- [ ] **Step 6: Criar o modelo**

`app/Models/CashPurchase.php`:

```php
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
```

> `Attribute` já é usado no projeto (`Installment::status`), então o padrão não é novo. Os dois mutators delegam a um helper privado em vez de repetir o mesmo corpo duas vezes.

- [ ] **Step 7: Criar a fábrica**

`database/factories/CashPurchaseFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CashPurchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashPurchase>
 */
class CashPurchaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $purchasedAt = fake()->dateTimeBetween('-12 months', 'now');

        return [
            'card_id' => Card::factory(),
            'description' => fake()->randomElement(['Padaria do Bairro', 'Posto Ipiranga', 'Supermercado Extra', 'Farmácia Pague Menos']),
            'amount_cents' => fake()->numberBetween(500, 80_000),
            'purchased_at' => $purchasedAt,
            'billed_at' => (clone $purchasedAt)->modify('+1 month'),
            'category' => null,
            'category_is_manual' => false,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(['card_id' => Card::factory()->for($user)]);
    }
}
```

Confira em `database/factories/CardFactory.php` se a relação com `User` é declarada de forma que `Card::factory()->for($user)` funcione; se o nome da relação for outro, ajuste para `Card::factory()->state(['user_id' => $user->id])`.

- [ ] **Step 8: Adicionar `scopeForUser` ao `Purchase`**

Em `app/Models/Purchase.php`, adicionar o método e os imports de `Builder`:

```php
    /** @param Builder<Purchase> $query */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->whereHas(
            'card',
            fn (Builder $cards) => $cards->where('user_id', $user->id),
        );
    }
```

Adicionar `category` e `category_is_manual` ao atributo `#[Fillable(...)]` e aos `casts()`:

```php
            'category' => ExpenseCategory::class,
            'category_is_manual' => 'boolean',
```

E ao bloco PHPDoc da classe:

```php
 * @property ExpenseCategory|null $category
 * @property bool $category_is_manual
```

- [ ] **Step 9: Rodar migração e testes**

```bash
php artisan migrate --no-interaction
php artisan test --compact tests/Feature/CashPurchaseTest.php
```
Expected: PASS, 4 testes.

- [ ] **Step 10: Garantir que nada regrediu**

Run: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/CashPurchase.php app/Models/Purchase.php database/factories/CashPurchaseFactory.php tests/Feature/CashPurchaseTest.php
git commit -m "feat: tabela e modelo de compra a vista"
```

---

### Task 8: Gravação das compras à vista na importação

**Files:**
- Create: `app/Actions/ResolveCashPurchaseDate.php`
- Create: `app/Actions/WriteCashPurchases.php`
- Modify: `app/Http/Controllers/InvoiceImportController.php`
- Modify: `resources/js/pages/invoices/review.tsx`
- Test: `tests/Unit/ResolveCashPurchaseDateTest.php`
- Test: `tests/Feature/InvoiceImportTest.php` (acrescentar)

**Interfaces:**
- Consumes: `ParsedCashLine` (Task 1), `CashPurchase` (Task 7).
- Produces:
  - `ResolveCashPurchaseDate::handle(int $day, int $month, CarbonImmutable $invoiceDueDate): CarbonImmutable`
  - `WriteCashPurchases::handle(array $lines, Card $card, CarbonImmutable $invoiceDueDate): int` onde `$lines` é `array<int, array{description: string, day: int, month: int, amount_cents: int}>` e o retorno é quantas foram efetivamente gravadas.
  - A sessão `invoice_import` ganha a chave `cash_lines`.
  - A página de revisão recebe a prop `cash_lines_count: number`.

- [ ] **Step 1: Escrever o teste da resolução de data**

`tests/Unit/ResolveCashPurchaseDateTest.php`:

```php
<?php

use App\Actions\ResolveCashPurchaseDate;
use Carbon\CarbonImmutable;

it('usa o ano do vencimento quando a compra o antecede', function (): void {
    $date = (new ResolveCashPurchaseDate)->handle(2, 6, CarbonImmutable::parse('2026-07-09'));

    expect($date->toDateString())->toBe('2026-06-02');
});

it('recua um ano quando a compra cairia depois do vencimento', function (): void {
    $date = (new ResolveCashPurchaseDate)->handle(28, 12, CarbonImmutable::parse('2026-01-10'));

    expect($date->toDateString())->toBe('2025-12-28');
});

it('encaixa o dia no ultimo dia do mes quando ele nao existe', function (): void {
    $date = (new ResolveCashPurchaseDate)->handle(31, 2, CarbonImmutable::parse('2026-04-10'));

    expect($date->toDateString())->toBe('2026-02-28');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact tests/Unit/ResolveCashPurchaseDateTest.php`
Expected: FAIL com "Class App\Actions\ResolveCashPurchaseDate not found".

- [ ] **Step 3: Implementar a resolução de data**

`app/Actions/ResolveCashPurchaseDate.php`:

```php
<?php

namespace App\Actions;

use Carbon\CarbonImmutable;

class ResolveCashPurchaseDate
{
    /**
     * A fatura traz dia e mes, nunca o ano. O ano e o do vencimento, exceto
     * quando isso colocaria a compra depois do proprio vencimento: uma linha
     * de 28/12 numa fatura que vence em janeiro e do ano anterior.
     */
    public function handle(int $day, int $month, CarbonImmutable $invoiceDueDate): CarbonImmutable
    {
        $candidate = $this->build($invoiceDueDate->year, $month, $day);

        return $candidate->greaterThan($invoiceDueDate)
            ? $this->build($invoiceDueDate->year - 1, $month, $day)
            : $candidate;
    }

    /**
     * O dia e encaixado no mes em vez de transbordar: somar 31 dias a um mes
     * de 28 daria marco, e a compra mudaria de mes sozinha.
     */
    private function build(int $year, int $month, int $day): CarbonImmutable
    {
        $firstDay = CarbonImmutable::create($year, $month, 1);

        return $firstDay->setDay(min($day, $firstDay->daysInMonth));
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --compact tests/Unit/ResolveCashPurchaseDateTest.php`
Expected: PASS, 3 testes.

- [ ] **Step 5: Escrever o teste de gravação na importação**

Acrescentar a `tests/Feature/InvoiceImportTest.php`. Siga o estilo dos testes que já existem no arquivo para montar upload e sessão — reutilize os helpers de lá em vez de inventar outros:

```php
it('grava as compras a vista ao confirmar a importacao', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)->withSession(['invoice_import' => [
        'card_id' => $card->id,
        'issuer' => 'Nubank',
        'due_date' => '2026-07-10',
        'candidates' => [],
        'cash_lines' => [
            ['description' => 'Padaria do Bairro', 'day' => 5, 'month' => 6, 'amount_cents' => 1_990],
            ['description' => 'Posto Ipiranga', 'day' => 6, 'month' => 6, 'amount_cents' => 31_849],
        ],
    ]]);

    $this->post(route('invoice-imports.confirm'), ['lines' => []]);

    expect(CashPurchase::query()->count())->toBe(2)
        ->and(CashPurchase::query()->first()->billed_at->toDateString())->toBe('2026-07-10')
        ->and(CashPurchase::query()->first()->purchased_at->toDateString())->toBe('2026-06-05');
});

it('nao duplica as compras a vista ao reimportar a mesma fatura', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $session = ['invoice_import' => [
        'card_id' => $card->id,
        'issuer' => 'Nubank',
        'due_date' => '2026-07-10',
        'candidates' => [],
        'cash_lines' => [
            ['description' => 'Padaria do Bairro', 'day' => 5, 'month' => 6, 'amount_cents' => 1_990],
        ],
    ]];

    $this->actingAs($user)->withSession($session)->post(route('invoice-imports.confirm'), ['lines' => []]);
    $this->actingAs($user)->withSession($session)->post(route('invoice-imports.confirm'), ['lines' => []]);

    expect(CashPurchase::query()->count())->toBe(1);
});
```

> `candidates` vazio faria `importFromSession()` devolver `null` na implementação atual, que exige `non-empty-array`. Isso é intencional e este teste vai expor: ver Step 7.

- [ ] **Step 6: Rodar e ver falhar**

Run: `php artisan test --compact tests/Feature/InvoiceImportTest.php`
Expected: FAIL — nenhuma `CashPurchase` gravada.

- [ ] **Step 7: Implementar a gravação**

`app/Actions/WriteCashPurchases.php`:

```php
<?php

namespace App\Actions;

use App\Models\Card;
use App\Models\CashPurchase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WriteCashPurchases
{
    public function __construct(private ResolveCashPurchaseDate $resolveCashPurchaseDate) {}

    /**
     * A gravacao e em lote com insertOrIgnore porque a duplicata e resolvida
     * pelo indice unico da tabela, nao por consulta previa: reimportar a mesma
     * fatura precisa ser inofensivo mesmo quando duas importacoes acontecem ao
     * mesmo tempo.
     *
     * Como insertOrIgnore nao passa pelo modelo, os timestamps entram a mao.
     *
     * @param  array<int, array{description: string, day: int, month: int, amount_cents: int}>  $lines
     * @return int quantas foram efetivamente gravadas
     */
    public function handle(array $lines, Card $card, CarbonImmutable $invoiceDueDate): int
    {
        if ($lines === []) {
            return 0;
        }

        $now = CarbonImmutable::now();

        $rows = array_map(
            fn (array $line): array => [
                'card_id' => $card->id,
                'description' => $line['description'],
                'amount_cents' => $line['amount_cents'],
                'purchased_at' => $this->resolveCashPurchaseDate
                    ->handle($line['day'], $line['month'], $invoiceDueDate)
                    ->toDateString(),
                'billed_at' => $invoiceDueDate->toDateString(),
                'category' => null,
                'category_is_manual' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $lines,
        );

        return DB::table((new CashPurchase)->getTable())->insertOrIgnore($rows);
    }
}
```

- [ ] **Step 8: Ligar no controller**

Em `app/Http/Controllers/InvoiceImportController.php`:

1. No `store()`, gravar as linhas à vista na sessão junto dos candidatos:

```php
        $request->session()->put('invoice_import', [
            'card_id' => $card->id,
            'issuer' => $invoice->issuer,
            'due_date' => $invoice->dueDate->toDateString(),
            'candidates' => array_map(
                fn (InvoiceCandidate $candidate): array => $candidate->toArray(),
                $candidates,
            ),
            'cash_lines' => array_map(
                fn (ParsedCashLine $line): array => [
                    'description' => $line->description,
                    'day' => $line->transactionDay,
                    'month' => $line->transactionMonth,
                    'amount_cents' => $line->amountCents,
                ],
                $invoice->cashLines,
            ),
        ]);
```

2. Em `importFromSession()`, aceitar sessão sem `cash_lines` (a chave não existe em sessão gravada por uma versão anterior do app) e devolvê-la já filtrada. Ajustar o PHPDoc de retorno para incluir `cash_lines: array<int, array{description: string, day: int, month: int, amount_cents: int}>` e acrescentar antes do `return`:

```php
        $cashLines = is_array($import['cash_lines'] ?? null)
            ? array_values(array_filter($import['cash_lines'], $this->isCashLine(...)))
            : [];
```

com o predicado:

```php
    /**
     * A sessao dura duas horas e sobrevive a deploy: a forma guardada pode ser
     * de uma versao anterior do app. Uma linha a vista irreconhecivel e
     * descartada em silencio, e nao invalida a sessao inteira — ao contrario do
     * candidato parcelado, cuja forma estranha viraria compra errada. Aqui o
     * pior caso e um gasto de fora do grafico.
     *
     * @param  mixed  $line
     */
    private function isCashLine(mixed $line): bool
    {
        return is_array($line)
            && is_string($line['description'] ?? null)
            && is_int($line['day'] ?? null)
            && is_int($line['month'] ?? null)
            && is_int($line['amount_cents'] ?? null);
    }
```

3. **Relaxar a exigência de candidatos.** Hoje `importFromSession()` devolve `null` quando `candidates` está vazio, sob a premissa de que `store()` nunca grava sessão sem linha parcelada. Isso deixa de valer: uma fatura pode ter só compras à vista. Trocar a condição

```php
        if ($candidates === [] || ! $this->everyCandidateIsAnchored($candidates)) {
            return null;
        }
```

por

```php
        /**
         * Uma fatura pode nao ter nenhuma parcelada e ainda assim ter dezenas
         * de compras a vista para gravar. O que invalida a sessao e candidato
         * sem ancora de duplicata, nao a ausencia de candidatos.
         */
        if (! $this->everyCandidateIsAnchored($candidates)) {
            return null;
        }
```

Ajustar o PHPDoc de retorno de `non-empty-array` para `array`, e conferir se `store()` ainda deve recusar fatura sem nenhuma linha: **sim, mas a condição muda** — hoje ele lança `ValidationException` quando `$invoice->lines === []`. Trocar por:

```php
        if ($invoice->lines === [] && $invoice->cashLines === []) {
            throw ValidationException::withMessages([
                'invoice' => 'Nenhuma compra encontrada nesta fatura.',
            ]);
        }
```

4. No `confirm()`, dentro da transação que já existe, depois do laço das parceladas:

```php
            $writeCashPurchases->handle(
                $import['cash_lines'],
                $card,
                CarbonImmutable::parse($import['due_date']),
            );
```

injetando `WriteCashPurchases $writeCashPurchases` na assinatura do método e adicionando-o ao `use (...)` da closure da transação.

5. No `review()`, passar a contagem para a tela:

```php
            'cash_lines_count' => count($import['cash_lines']),
```

- [ ] **Step 9: Avisar na tela de revisão**

Em `resources/js/pages/invoices/review.tsx`, adicionar `cash_lines_count: number` ao tipo das props e, acima da tabela de candidatos, uma linha discreta:

```tsx
{cash_lines_count > 0 && (
    <p className="text-sm text-muted-foreground">
        {cash_lines_count === 1
            ? '1 compra à vista desta fatura também será salva para análise de gastos.'
            : `${cash_lines_count} compras à vista desta fatura também serão salvas para análise de gastos.`}
    </p>
)}
```

> Gravar 53 registros sem dizer nada ao usuário seria desonesto. Esta linha é o aviso mínimo.

**E o botão de confirmar precisa habilitar.** Hoje ele é `disabled={selectedCount === 0 || form.processing}`, o que trava a tela para sempre numa fatura sem nenhuma parcelada — justamente o caso que esta task acabou de abrir no servidor. Sem isso, o caminho de fatura só com compras à vista existe no backend e é inalcançável pela interface.

```tsx
disabled={(selectedCount === 0 && cash_lines_count === 0) || form.processing}
```

E o rótulo precisa dizer a verdade sobre o que vai acontecer:

```tsx
{selectedCount > 0
    ? `Importar ${selectedCount} ${selectedCount === 1 ? 'compra' : 'compras'}`
    : 'Salvar as compras à vista'}
```

- [ ] **Step 9b: A mensagem final precisa contar as compras à vista**

`importToast()` hoje só conhece parceladas. Numa fatura só com compras à vista ele recebe `(0, 0)` e diz *"Nenhuma compra foi importada"* — depois de gravar dezenas de registros. Isso era inalcançável antes desta task, e passa a ser o caminho feliz novo.

`WriteCashPurchases::handle()` já devolve quantas gravou, e o retorno está sendo descartado. Aproveite-o e passe a contagem para `importToast()`. As regras que a mensagem tem que respeitar:

- **Nunca** dizer que nada foi importado quando compras à vista foram gravadas.
- Mencionar a contagem de à vista sempre que ela for maior que zero, inclusive numa fatura mista.
- Continuar dizendo a verdade sobre as parceladas puladas, como já faz.

A redação é sua; as três regras não são. Escreva um teste por regra.

- [ ] **Step 10: Rodar os testes**

```bash
php artisan test --compact tests/Feature/InvoiceImportTest.php tests/Unit/ResolveCashPurchaseDateTest.php
npm run types:check
```
Expected: PASS nos dois.

- [ ] **Step 11: Rodar tudo**

Run: `php artisan test --compact`
Expected: PASS. Atenção especial aos testes de `InvoiceImportTest` que dependiam da recusa de fatura sem linha parcelada — se algum deles falhar, ele testava a regra antiga e precisa ser atualizado para a nova mensagem.

- [ ] **Step 12: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/ResolveCashPurchaseDate.php app/Actions/WriteCashPurchases.php app/Http/Controllers/InvoiceImportController.php resources/js/pages/invoices/review.tsx tests
git commit -m "feat: gravacao das compras a vista na confirmacao da importacao"
```

---

### Task 9: Instalar `laravel/ai` e criar o agente

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `config/ai.php` (publicado)
- Modify: `.env`, `.env.example`
- Create: `app/Ai/Agents/CategorizeExpenses.php`
- Test: `tests/Feature/CategorizeExpensesAgentTest.php`

**Interfaces:**
- Consumes: `ExpenseCategory` da Task 6.
- Produces: `CategorizeExpenses` — agente com saída estruturada. `(new CategorizeExpenses)->prompt($text)` devolve algo acessível como `$response['items']`, um array de `['id' => int, 'category' => string]`.

- [ ] **Step 1: Instalar e publicar**

```bash
composer require laravel/ai
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --no-interaction
php artisan migrate --no-interaction
```

> As migrações publicadas criam `agent_conversations` e `agent_conversation_messages`. Este projeto não usa conversas persistidas, mas rodá-las agora evita erro se o pacote as pressupuser em alguma rota interna.

- [ ] **Step 2: Configurar o provedor**

Em `config/ai.php`:

```php
    'default' => env('AI_PROVIDER', 'ollama'),
```

e no bloco `ollama` acrescentar o modelo padrão, que o `OllamaProvider` lê de `config['models']['text']['default']`:

```php
        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
            'models' => [
                'text' => [
                    'default' => env('OLLAMA_MODEL', 'gemma4:e4b'),
                ],
            ],
        ],
```

Em `.env` e `.env.example`:

```ini
AI_PROVIDER=ollama
OLLAMA_URL=http://localhost:11434
OLLAMA_MODEL=gemma4:e4b
```

- [ ] **Step 3: Escrever o teste que falha**

`tests/Feature/CategorizeExpensesAgentTest.php`:

```php
<?php

use App\Ai\Agents\CategorizeExpenses;
use App\Enums\ExpenseCategory;

it('devolve a categoria de cada item', function (): void {
    CategorizeExpenses::fake([
        ['items' => [
            ['id' => 1, 'category' => 'mercado'],
            ['id' => 2, 'category' => 'combustivel'],
        ]],
    ]);

    $response = (new CategorizeExpenses)->prompt("1. Supermercado Extra\n2. Posto Ipiranga");

    expect($response['items'])->toHaveCount(2)
        ->and($response['items'][0]['category'])->toBe('mercado');
});

it('leva a taxonomia inteira nas instrucoes', function (): void {
    $instructions = (string) (new CategorizeExpenses)->instructions();

    foreach (ExpenseCategory::cases() as $category) {
        expect($instructions)->toContain($category->value)
            ->and($instructions)->toContain($category->hint());
    }
});

it('desliga o modo thinking no ollama', function (): void {
    expect((new CategorizeExpenses)->providerOptions('ollama'))->toBe(['think' => false])
        ->and((new CategorizeExpenses)->providerOptions('anthropic'))->toBe([]);
});
```

- [ ] **Step 4: Rodar e ver falhar**

Run: `php artisan test --compact tests/Feature/CategorizeExpensesAgentTest.php`
Expected: FAIL com "Class App\Ai\Agents\CategorizeExpenses not found".

- [ ] **Step 5: Criar o agente**

```bash
php artisan make:agent CategorizeExpenses --structured --no-interaction
```

Substituir o conteúdo gerado em `app/Ai/Agents/CategorizeExpenses.php`:

```php
<?php

namespace App\Ai\Agents;

use App\Enums\ExpenseCategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Classifica descricoes de fatura de cartao. O agente e a costura entre o app
 * e o provedor de IA: nem o job nem a tela sabem quem classificou, e trocar
 * Ollama por Anthropic e mudanca de configuracao, nao de codigo.
 */
class CategorizeExpenses implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        $taxonomy = collect(ExpenseCategory::cases())
            ->map(fn (ExpenseCategory $category): string => "- {$category->value}: {$category->hint()}")
            ->implode("\n");

        return <<<INSTRUCTIONS
        Você classifica compras de fatura de cartão de crédito brasileira.

        Receberá uma lista numerada de descrições como aparecem na fatura. Elas
        são curtas, abreviadas e frequentemente trazem prefixos de adquirente
        como "Mp *", "PG ", "Cappta *" ou "Dl *", que não fazem parte do nome do
        estabelecimento e devem ser ignorados na hora de decidir.

        Para cada item, responda com o número do item e uma destas categorias:

        {$taxonomy}

        Regras:
        - Responda um item para cada número recebido, na mesma numeração.
        - Use exatamente os valores de categoria listados acima.
        - Quando a descrição não permitir identificar o tipo de gasto com
          razoável confiança, use "outros". Não adivinhe.
        INSTRUCTIONS;
    }

    /**
     * O enum entra no JSON Schema, o que faz o proprio runtime recusar rotulo
     * fora da lista. E o que dispensa normalizar string depois e o que impede
     * o grafico de ganhar tres fatias para o mesmo supermercado.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items(
                $schema->object(fn (JsonSchema $schema): array => [
                    'id' => $schema->integer()->required(),
                    'category' => $schema->string()->enum(ExpenseCategory::values())->required(),
                ]),
            )->required(),
        ];
    }

    /**
     * O gemma4 tem modo de raciocinio. Numa tarefa de classificacao ele so
     * custa tokens, e o gateway do Ollama iça a chave "think" para o topo do
     * corpo da requisicao.
     *
     * O parametro chega como string do driver, nao como Lab: comparar contra o
     * caso do enum direto num match silenciaria a opcao sem erro nenhum.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;

        return $driver === Lab::Ollama->value ? ['think' => false] : [];
    }
}
```

- [ ] **Step 6: Rodar os testes**

Run: `php artisan test --compact tests/Feature/CategorizeExpensesAgentTest.php`
Expected: PASS, 3 testes.

> Se `instructions()` for declarado no contrato como `Stringable|string`, ajuste o tipo de retorno para casar. Se `fake()` reclamar da forma dos dados, confira `vendor/laravel/ai/src` — o contrato do pacote vale mais que este plano, que foi escrito contra a v0.10.1.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock config/ai.php .env.example app/Ai tests/Feature/CategorizeExpensesAgentTest.php database/migrations
git commit -m "feat: agente de categorizacao de gastos"
```

---

### Task 10: O job de categorização

**Files:**
- Create: `app/Jobs/CategorizeExpensesJob.php`
- Modify: `app/Http/Controllers/InvoiceImportController.php`
- Test: `tests/Feature/CategorizeExpensesJobTest.php`

**Interfaces:**
- Consumes: `CategorizeExpenses` (Task 9), `CashPurchase` e `Purchase::scopeForUser` (Task 7), `ExpenseCategory` (Task 6).
- Produces: `CategorizeExpensesJob(int $userId)`, despachável por `CategorizeExpensesJob::dispatch($userId)`.

- [ ] **Step 1: Escrever os testes que falham**

`tests/Feature/CategorizeExpensesJobTest.php`:

```php
<?php

use App\Ai\Agents\CategorizeExpenses;
use App\Enums\ExpenseCategory;
use App\Jobs\CategorizeExpensesJob;
use App\Models\CashPurchase;
use App\Models\Purchase;
use App\Models\User;

it('categoriza compras a vista sem categoria', function (): void {
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create(['description' => 'Supermercado Extra']);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'mercado']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($cash->fresh()->category)->toBe(ExpenseCategory::Mercado)
        ->and($cash->fresh()->category_is_manual)->toBeFalse();
});

it('nao sobrescreve categoria definida pelo usuario', function (): void {
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create([
        'description' => 'Supermercado Extra',
        'category' => ExpenseCategory::Lazer,
        'category_is_manual' => true,
    ]);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'mercado']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($cash->fresh()->category)->toBe(ExpenseCategory::Lazer);
});

it('categoriza compras parceladas tambem', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()
        ->for(App\Models\Card::factory()->for($user))
        ->create(['description' => 'Posto Ipiranga']);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'combustivel']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($purchase->fresh()->category)->toBe(ExpenseCategory::Combustivel);
});

it('ignora categoria que nao existe no enum', function (): void {
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create(['description' => 'Loja Qualquer']);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'criptomoedas']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($cash->fresh()->category)->toBeNull();
});

it('nao toca em compras de outro usuario', function (): void {
    $user = User::factory()->create();
    $other = CashPurchase::factory()->create(['description' => 'Supermercado Extra']);

    CategorizeExpenses::fake([
        ['items' => [['id' => 1, 'category' => 'mercado']]],
    ]);

    new CategorizeExpensesJob($user->id)->handle();

    expect($other->fresh()->category)->toBeNull();
});
```

> Se `CategorizeExpenses::fake()` esgotar as respostas quando o job faz duas chamadas (uma para à vista, outra para parceladas), passe a mesma resposta duas vezes no array ou use a forma de closure. Ajuste ao contrato real do pacote.

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact tests/Feature/CategorizeExpensesJobTest.php`
Expected: FAIL com "Class App\Jobs\CategorizeExpensesJob not found".

- [ ] **Step 3: Criar o job**

```bash
php artisan make:job CategorizeExpensesJob --no-interaction
```

`app/Jobs/CategorizeExpensesJob.php`:

```php
<?php

namespace App\Jobs;

use App\Ai\Agents\CategorizeExpenses;
use App\Enums\ExpenseCategory;
use App\Models\CashPurchase;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

/**
 * O job varre tudo que esta sem categoria em vez de receber a lista de ids da
 * importacao. Assim a importacao e o botao de reprocessar despacham o mesmo
 * job, e uma classificacao que falhou numa fatura e retomada na proxima em vez
 * de ficar orfa.
 */
class CategorizeExpensesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Lote grande o bastante para nao pagar a latencia do modelo por item, e
     * pequeno o bastante para caber com folga na janela de contexto de um
     * modelo local de 8B.
     */
    private const BATCH = 40;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /**
     * Um modelo local classificando 40 descricoes leva bem mais que o padrao
     * de 60 segundos.
     */
    public int $timeout = 600;

    public function __construct(public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        CashPurchase::query()
            ->forUser($user)
            ->whereNull('category')
            ->chunkById(self::BATCH, $this->categorize(...));

        Purchase::query()
            ->forUser($user)
            ->whereNull('category')
            ->chunkById(self::BATCH, $this->categorize(...));
    }

    /**
     * O identificador enviado ao modelo e a posicao no lote, nao o id do banco:
     * numero pequeno gasta menos token e nao vaza chave interna no prompt.
     *
     * @param  Collection<int, Model>  $rows
     */
    private function categorize(Collection $rows): void
    {
        $indexed = $rows->values();

        $list = $indexed
            ->map(fn (Model $row, int $index): string => ($index + 1).'. '.$row->description)
            ->implode("\n");

        $response = new CategorizeExpenses()->prompt($list);

        foreach ($response['items'] ?? [] as $item) {
            $row = $indexed->get(((int) ($item['id'] ?? 0)) - 1);
            $category = ExpenseCategory::tryFrom((string) ($item['category'] ?? ''));

            if ($row === null || $category === null || $row->category_is_manual) {
                continue;
            }

            $row->update(['category' => $category]);
        }
    }
}
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --compact tests/Feature/CategorizeExpensesJobTest.php`
Expected: PASS, 5 testes.

- [ ] **Step 5: Despachar na importação**

Em `InvoiceImportController::confirm()`, **depois** da `DB::transaction(...)` e antes do redirect:

```php
        CategorizeExpensesJob::dispatch($request->user()->id);
```

- [ ] **Step 6: Escrever o teste do despacho**

Acrescentar a `tests/Feature/InvoiceImportTest.php`:

```php
it('enfileira a categorizacao ao confirmar a importacao', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)->withSession(['invoice_import' => [
        'card_id' => $card->id,
        'issuer' => 'Nubank',
        'due_date' => '2026-07-10',
        'candidates' => [],
        'cash_lines' => [
            ['description' => 'Padaria do Bairro', 'day' => 5, 'month' => 6, 'amount_cents' => 1_990],
        ],
    ]])->post(route('invoice-imports.confirm'), ['lines' => []]);

    Queue::assertPushed(CategorizeExpensesJob::class);
});
```

- [ ] **Step 7: Rodar tudo**

Run: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/CategorizeExpensesJob.php app/Http/Controllers/InvoiceImportController.php tests
git commit -m "feat: job de categorizacao de gastos"
```

---

### Task 11: `BuildSpendingBreakdown`

A action que responde pelos números da tela. Regime de caixa: à vista soma pelo mês de `billed_at`, parcela soma pelo mês do próprio vencimento.

**Files:**
- Create: `app/Actions/BuildSpendingBreakdown.php`
- Test: `tests/Feature/BuildSpendingBreakdownTest.php`

**Interfaces:**
- Consumes: `CashPurchase`, `Purchase`, `Installment`, `ExpenseCategory`.
- Produces:

```php
/**
 * @return array{
 *   month: string,
 *   categories: array<int, array{category: string, label: string, color: string, total_cents: int}>,
 *   months: array<int, array{month: string, totals: array<string, int>}>,
 *   entries: array<int, array{
 *     id: int, kind: string, description: string, amount_cents: int,
 *     purchased_at: string, installment_label: string|null,
 *     category: string|null, category_is_manual: bool, card_name: string
 *   }>,
 *   uncategorized_count: int,
 * }
 */
public function handle(User $user, CarbonImmutable $month, int $months = 12): array
```

`kind` é `'cash'` ou `'purchase'`. O `id` é o do registro editável: para uma parcela, o id da **compra**, porque é lá que a categoria mora.

- [ ] **Step 1: Escrever o teste que falha**

`tests/Feature/BuildSpendingBreakdownTest.php`:

```php
<?php

use App\Actions\BuildSpendingBreakdown;
use App\Enums\ExpenseCategory;
use App\Models\Card;
use App\Models\CashPurchase;
use App\Models\Purchase;
use App\Models\User;
use Carbon\CarbonImmutable;

it('soma a compra a vista no mes em que a fatura cobrou', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 10_000,
        'purchased_at' => '2026-06-28',
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Mercado,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-07-01'));

    $mercado = collect($breakdown['categories'])->firstWhere('category', 'mercado');

    expect($mercado['total_cents'])->toBe(10_000)
        ->and($breakdown['month'])->toBe('2026-07');
});

it('nao soma a compra a vista no mes da compra', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'amount_cents' => 10_000,
        'purchased_at' => '2026-06-28',
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Mercado,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-06-01'));

    expect(collect($breakdown['categories'])->sum('total_cents'))->toBe(0);
});

it('soma a parcela e nao a compra inteira', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['closing_day' => 1, 'due_day' => 10]);

    $purchase = Purchase::factory()->for($card)->parcelada(10)->create([
        'total_amount_cents' => 400_000,
        'purchased_at' => '2026-06-15',
        'category' => ExpenseCategory::Casa,
    ]);

    $firstDueDate = $purchase->installments()->first()->due_date;

    $breakdown = new BuildSpendingBreakdown()->handle($user, $firstDueDate->startOfMonth());

    $casa = collect($breakdown['categories'])->firstWhere('category', 'casa');

    expect($casa['total_cents'])->toBe(40_000);
});

it('conta o que esta sem categoria', function (): void {
    $user = User::factory()->create();

    CashPurchase::factory()->forUser($user)->create([
        'billed_at' => '2026-07-10',
        'category' => null,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-07-01'));

    expect($breakdown['uncategorized_count'])->toBe(1);
});

it('nao mistura gasto de outro usuario', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->create([
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Mercado,
        'amount_cents' => 99_999,
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-07-01'));

    expect(collect($breakdown['categories'])->sum('total_cents'))->toBe(0);
});

it('devolve a serie dos ultimos doze meses terminando no mes escolhido', function (): void {
    $user = User::factory()->create();

    $breakdown = new BuildSpendingBreakdown()->handle($user, CarbonImmutable::parse('2026-07-01'));

    expect($breakdown['months'])->toHaveCount(12)
        ->and($breakdown['months'][0]['month'])->toBe('2025-08')
        ->and($breakdown['months'][11]['month'])->toBe('2026-07');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact tests/Feature/BuildSpendingBreakdownTest.php`
Expected: FAIL com "Class App\Actions\BuildSpendingBreakdown not found".

- [ ] **Step 3: Implementar a action**

`app/Actions/BuildSpendingBreakdown.php`:

```php
<?php

namespace App\Actions;

use App\Enums\ExpenseCategory;
use App\Models\CashPurchase;
use App\Models\Installment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildSpendingBreakdown
{
    /**
     * Regime de caixa: o mes de um gasto e o mes em que a fatura o cobrou. Para
     * a compra a vista isso e o billed_at, gravado na importacao; para a
     * parcelada e o vencimento da parcela. Usar a data da compra para uma e o
     * vencimento para a outra erraria os dois meses na virada do ano.
     *
     * @return array{
     *   month: string,
     *   categories: array<int, array{category: string, label: string, color: string, total_cents: int}>,
     *   months: array<int, array{month: string, totals: array<string, int>}>,
     *   entries: array<int, array{id: int, kind: string, description: string, amount_cents: int, purchased_at: string, installment_label: string|null, category: string|null, category_is_manual: bool, card_name: string}>,
     *   uncategorized_count: int,
     * }
     */
    public function handle(User $user, CarbonImmutable $month, int $months = 12): array
    {
        $start = $month->startOfMonth();
        $seriesStart = $start->subMonths($months - 1);

        $cash = CashPurchase::query()
            ->forUser($user)
            ->with('card:id,name')
            ->whereDate('billed_at', '>=', $seriesStart->toDateString())
            ->whereDate('billed_at', '<=', $start->endOfMonth()->toDateString())
            ->get();

        $installments = Installment::query()
            ->forUser($user)
            ->with('purchase:id,card_id,description,installments_count,category,category_is_manual', 'purchase.card:id,name')
            ->whereDate('due_date', '>=', $seriesStart->toDateString())
            ->whereDate('due_date', '<=', $start->endOfMonth()->toDateString())
            ->get();

        return [
            'month' => $start->format('Y-m'),
            'categories' => $this->categories($cash, $installments, $start),
            'months' => $this->series($cash, $installments, $seriesStart, $months),
            'entries' => $this->entries($cash, $installments, $start),
            'uncategorized_count' => $this->uncategorizedCount($cash, $installments, $start),
        ];
    }

    /**
     * A lista traz so as categorias com gasto no mes: uma barra de zero nao
     * comunica nada e polui a legenda.
     *
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @return array<int, array{category: string, label: string, color: string, total_cents: int}>
     */
    private function categories(Collection $cash, Collection $installments, CarbonImmutable $month): array
    {
        $totals = [];

        foreach ($this->inMonth($cash, $installments, $month) as [$category, $amountCents]) {
            if ($category === null) {
                continue;
            }

            $totals[$category->value] = ($totals[$category->value] ?? 0) + $amountCents;
        }

        arsort($totals);

        return array_values(array_map(
            fn (string $value, int $totalCents): array => [
                'category' => $value,
                'label' => ExpenseCategory::from($value)->label(),
                'color' => ExpenseCategory::from($value)->color(),
                'total_cents' => $totalCents,
            ],
            array_keys($totals),
            $totals,
        ));
    }

    /**
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @return array<int, array{month: string, totals: array<string, int>}>
     */
    private function series(Collection $cash, Collection $installments, CarbonImmutable $seriesStart, int $months): array
    {
        return array_map(
            function (int $offset) use ($cash, $installments, $seriesStart): array {
                $month = $seriesStart->addMonths($offset);
                $totals = [];

                foreach ($this->inMonth($cash, $installments, $month) as [$category, $amountCents]) {
                    if ($category === null) {
                        continue;
                    }

                    $totals[$category->value] = ($totals[$category->value] ?? 0) + $amountCents;
                }

                return ['month' => $month->format('Y-m'), 'totals' => $totals];
            },
            range(0, $months - 1),
        );
    }

    /**
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @return array<int, array{id: int, kind: string, description: string, amount_cents: int, purchased_at: string, installment_label: string|null, category: string|null, category_is_manual: bool, card_name: string}>
     */
    private function entries(Collection $cash, Collection $installments, CarbonImmutable $month): array
    {
        $entries = [];

        foreach ($cash as $purchase) {
            if (! $purchase->billed_at->isSameMonth($month, true)) {
                continue;
            }

            $entries[] = [
                'id' => $purchase->id,
                'kind' => 'cash',
                'description' => $purchase->description,
                'amount_cents' => $purchase->amount_cents,
                'purchased_at' => $purchase->purchased_at->toDateString(),
                'installment_label' => null,
                'category' => $purchase->category?->value,
                'category_is_manual' => $purchase->category_is_manual,
                'card_name' => $purchase->card->name,
            ];
        }

        foreach ($installments as $installment) {
            if (! $installment->due_date->isSameMonth($month, true)) {
                continue;
            }

            $purchase = $installment->purchase;

            $entries[] = [
                'id' => $purchase->id,
                'kind' => 'purchase',
                'description' => $purchase->description,
                'amount_cents' => $installment->amount_cents,
                'purchased_at' => $installment->due_date->toDateString(),
                'installment_label' => "{$installment->number}/{$purchase->installments_count}",
                'category' => $purchase->category?->value,
                'category_is_manual' => $purchase->category_is_manual,
                'card_name' => $purchase->card->name,
            ];
        }

        usort($entries, fn (array $a, array $b): int => $b['amount_cents'] <=> $a['amount_cents']);

        return $entries;
    }

    /**
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     */
    private function uncategorizedCount(Collection $cash, Collection $installments, CarbonImmutable $month): int
    {
        $count = 0;

        foreach ($this->inMonth($cash, $installments, $month) as [$category]) {
            if ($category === null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Achata os dois tipos numa lista de (categoria, valor) do mes pedido, que
     * e a unica forma em que as somas os enxergam.
     *
     * @param  Collection<int, CashPurchase>  $cash
     * @param  Collection<int, Installment>  $installments
     * @return array<int, array{0: ExpenseCategory|null, 1: int}>
     */
    private function inMonth(Collection $cash, Collection $installments, CarbonImmutable $month): array
    {
        $rows = [];

        foreach ($cash as $purchase) {
            if ($purchase->billed_at->isSameMonth($month, true)) {
                $rows[] = [$purchase->category, $purchase->amount_cents];
            }
        }

        foreach ($installments as $installment) {
            if ($installment->due_date->isSameMonth($month, true)) {
                $rows[] = [$installment->purchase->category, $installment->amount_cents];
            }
        }

        return $rows;
    }
}
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --compact tests/Feature/BuildSpendingBreakdownTest.php`
Expected: PASS, 6 testes.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/BuildSpendingBreakdown.php tests/Feature/BuildSpendingBreakdownTest.php
git commit -m "feat: agregacao de gastos por categoria"
```

---

### Task 12: Rotas, controller e edição de categoria

**Files:**
- Create: `app/Http/Controllers/SpendingController.php`
- Create: `app/Http/Requests/UpdateExpenseCategoryRequest.php`
- Create: `app/Policies/CashPurchasePolicy.php`
- Modify: `app/Policies/PurchasePolicy.php`
- Create: `routes/gastos.php`
- Modify: `bootstrap/app.php` (ou onde os arquivos de rota são registrados)
- Test: `tests/Feature/SpendingTest.php`

**Interfaces:**
- Consumes: `BuildSpendingBreakdown` (Task 11), `CategorizeExpensesJob` (Task 10), `ExpenseCategory` (Task 6).
- Produces: rotas nomeadas `spending.index`, `spending.cash-category`, `spending.purchase-category`, `spending.categorize`.

- [ ] **Step 1: Descobrir como as rotas são registradas**

Run: `grep -n "parcelamentos\|planejamento" bootstrap/app.php routes/web.php`
Siga exatamente o mesmo mecanismo para registrar `routes/gastos.php`.

- [ ] **Step 2: Escrever os testes que falham**

`tests/Feature/SpendingTest.php`:

```php
<?php

use App\Enums\ExpenseCategory;
use App\Jobs\CategorizeExpensesJob;
use App\Models\CashPurchase;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

it('mostra a tela de gastos', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('spending.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('spending/index')
            ->has('breakdown.categories')
            ->has('breakdown.months', 12)
            ->has('categories', 12));
});

it('exige login', function (): void {
    $this->get(route('spending.index'))->assertRedirect(route('login'));
});

it('troca a categoria de uma compra a vista e marca como manual', function (): void {
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create(['category' => ExpenseCategory::Outros]);

    $this->actingAs($user)
        ->patch(route('spending.cash-category', $cash), ['category' => 'mercado'])
        ->assertRedirect();

    expect($cash->fresh()->category)->toBe(ExpenseCategory::Mercado)
        ->and($cash->fresh()->category_is_manual)->toBeTrue();
});

it('recusa categoria fora da lista', function (): void {
    $user = User::factory()->create();
    $cash = CashPurchase::factory()->forUser($user)->create();

    $this->actingAs($user)
        ->patch(route('spending.cash-category', $cash), ['category' => 'criptomoedas'])
        ->assertSessionHasErrors('category');
});

it('nao deixa editar categoria de cartao alheio', function (): void {
    $user = User::factory()->create();
    $other = CashPurchase::factory()->create();

    $this->actingAs($user)
        ->patch(route('spending.cash-category', $other), ['category' => 'mercado'])
        ->assertForbidden();
});

it('enfileira a recategorizacao', function (): void {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('spending.categorize'))
        ->assertRedirect();

    Queue::assertPushed(CategorizeExpensesJob::class);
});
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `php artisan test --compact tests/Feature/SpendingTest.php`
Expected: FAIL — rota `spending.index` não definida.

- [ ] **Step 4: Criar o form request**

`app/Http/Requests/UpdateExpenseCategoryRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\ExpenseCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseCategoryRequest extends FormRequest
{
    /**
     * `present` e nao `required`: null e valor legitimo — devolve o gasto para
     * o balde "sem categoria" e o proximo job o reclassifica —, mas campo
     * ausente e requisicao malformada. Mesma regra da cor da linha do
     * planejamento, pelo mesmo motivo.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['present', 'nullable', Rule::enum(ExpenseCategory::class)],
        ];
    }
}
```

- [ ] **Step 5: Criar as policies**

`app/Policies/CashPurchasePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\CashPurchase;
use App\Models\User;

class CashPurchasePolicy
{
    public function update(User $user, CashPurchase $cashPurchase): bool
    {
        return $cashPurchase->card->user_id === $user->id;
    }
}
```

Em `app/Policies/PurchasePolicy.php`, acrescentar:

```php
    public function update(User $user, Purchase $purchase): bool
    {
        return $purchase->card->user_id === $user->id;
    }
```

- [ ] **Step 6: Criar o controller**

`app/Http/Controllers/SpendingController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\BuildSpendingBreakdown;
use App\Enums\ExpenseCategory;
use App\Http\Requests\UpdateExpenseCategoryRequest;
use App\Jobs\CategorizeExpensesJob;
use App\Models\CashPurchase;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SpendingController extends Controller
{
    public function index(Request $request, BuildSpendingBreakdown $buildSpendingBreakdown): Response
    {
        return Inertia::render('spending/index', [
            'breakdown' => $buildSpendingBreakdown->handle($request->user(), $this->selectedMonth($request)),
            'categories' => array_map(
                fn (ExpenseCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                    'color' => $category->color(),
                ],
                ExpenseCategory::cases(),
            ),
        ]);
    }

    public function updateCashCategory(UpdateExpenseCategoryRequest $request, CashPurchase $cashPurchase): RedirectResponse
    {
        $this->storeCategory($cashPurchase, $request->validated('category'));

        return back();
    }

    /**
     * A categoria de uma compra parcelada mora na compra, nao na parcela: mudar
     * aqui muda o gasto em todos os meses do parcelamento, que e o que o
     * usuario espera de "esse sofa e da categoria Casa".
     */
    public function updatePurchaseCategory(UpdateExpenseCategoryRequest $request, Purchase $purchase): RedirectResponse
    {
        $this->storeCategory($purchase, $request->validated('category'));

        return back();
    }

    public function categorize(Request $request): RedirectResponse
    {
        CategorizeExpensesJob::dispatch($request->user()->id);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => 'Estamos classificando os gastos que ainda não têm categoria. Atualize em instantes.',
        ]);

        return back();
    }

    private function storeCategory(CashPurchase|Purchase $model, ?string $category): void
    {
        $model->update([
            'category' => $category,
            'category_is_manual' => $category !== null,
        ]);
    }

    /**
     * Mes invalido cai no mes corrente em vez de estourar: o parametro vem da
     * barra de endereco e nao vale derrubar a tela por causa dele.
     */
    private function selectedMonth(Request $request): CarbonImmutable
    {
        $month = $request->query('mes');

        if (! is_string($month) || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return CarbonImmutable::now()->startOfMonth();
        }

        return CarbonImmutable::parse("{$month}-01")->startOfMonth();
    }
}
```

- [ ] **Step 7: Criar as rotas**

`routes/gastos.php`:

```php
<?php

use App\Http\Controllers\SpendingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('gastos', [SpendingController::class, 'index'])->name('spending.index');

    Route::patch('gastos/a-vista/{cashPurchase}/categoria', [SpendingController::class, 'updateCashCategory'])
        ->name('spending.cash-category')
        ->can('update', 'cashPurchase');

    Route::patch('gastos/compras/{purchase}/categoria', [SpendingController::class, 'updatePurchaseCategory'])
        ->name('spending.purchase-category')
        ->can('update', 'purchase');

    Route::post('gastos/categorizar', [SpendingController::class, 'categorize'])->name('spending.categorize');
});
```

Registrar o arquivo do mesmo jeito que `routes/parcelamentos.php` é registrado (Step 1).

- [ ] **Step 8: Rodar os testes**

```bash
php artisan test --compact tests/Feature/SpendingTest.php
php artisan route:list --path=gastos
```
Expected: PASS, 6 testes; quatro rotas listadas.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SpendingController.php app/Http/Requests/UpdateExpenseCategoryRequest.php app/Policies routes bootstrap tests/Feature/SpendingTest.php
git commit -m "feat: rotas e controller da tela de gastos"
```

---

### Task 13: A tela de gastos

**Files:**
- Create: `resources/js/pages/spending/index.tsx`
- Create: `resources/js/components/category-breakdown-chart.tsx`
- Create: `resources/js/components/category-trend-chart.tsx`
- Modify: `resources/js/types/parcelamentos.ts`
- Modify: `resources/js/components/app-sidebar.tsx`

**Interfaces:**
- Consumes: as props de `SpendingController@index` (Task 12) e as rotas geradas pelo Wayfinder.
- Produces: a página `spending/index`.

- [ ] **Step 1: Invocar as skills obrigatórias**

**REQUIRED SUB-SKILLS**, nesta ordem:
1. `dataviz` — antes de escrever qualquer linha de gráfico. Doze séries categóricas e barras empilhadas de doze meses são exatamente os casos que ela cobre.
2. `frontend-design` — para a tela não sair com cara de template.
3. `inertia-react-development` e `wayfinder-development` — padrões de página, formulário e rota tipada neste projeto.

Não pule nenhuma.

**A forma do gráfico de categorias já foi decidida e não está em aberto.** A Task 6 mediu a paleta: a skill `dataviz` separa com segurança cerca de oito matizes categóricos, e doze deixam pares não adjacentes fracos — o mais próximo é Mercado `#0095ea` contra Eletrônicos `#286fe3`. O usuário decidiu trocar a rosca por **barras horizontais ordenadas do maior para o menor, com o nome da categoria e o valor impressos na própria linha**. A cor vira enfeite, não identidade — o mesmo padrão que o planejamento já usa: faixa de cor ao lado do nome, nunca legenda que o olho precise cruzar. Não reintroduza a rosca.

- [ ] **Step 2: Gerar as rotas tipadas**

Run: `php artisan wayfinder:generate`
Confira que `resources/js/actions/App/Http/Controllers/SpendingController.ts` apareceu.

- [ ] **Step 3: Declarar os tipos**

Em `resources/js/types/parcelamentos.ts`, junto de `MonthlyProjectionRow`:

```ts
export type ExpenseCategoryOption = {
    value: string;
    label: string;
    color: string;
};

export type SpendingCategoryTotal = {
    category: string;
    label: string;
    color: string;
    total_cents: number;
};

export type SpendingMonthTotals = {
    month: string;
    totals: Record<string, number>;
};

export type SpendingEntry = {
    id: number;
    kind: 'cash' | 'purchase';
    description: string;
    amount_cents: number;
    purchased_at: string;
    installment_label: string | null;
    category: string | null;
    category_is_manual: boolean;
    card_name: string;
};

export type SpendingBreakdown = {
    month: string;
    categories: SpendingCategoryTotal[];
    months: SpendingMonthTotals[];
    entries: SpendingEntry[];
    uncategorized_count: number;
};
```

- [ ] **Step 4: Escrever a página**

`resources/js/pages/spending/index.tsx`. Requisitos concretos, sem liberdade de escopo:

1. Layout da aplicação, como as demais páginas fazem (`history/index.tsx` é a referência mais próxima).
2. Seletor de mês no topo, que navega para `spending.index` com `?mes=YYYY-MM`. Use `<Link>` do Inertia com `prefetch`, no padrão do projeto.
3. `<CategoryBreakdownChart categories={breakdown.categories} />` — barras horizontais ordenadas, nome e valor na própria linha.
4. `<CategoryTrendChart months={breakdown.months} categories={categories} />`.
5. Tabela das `entries` com: descrição, cartão, data, o rótulo de parcela quando houver, valor formatado por `formatBRL`, e um `<Select>` de categoria (o componente de `@/components/ui/select` já existe) que dispara `router.patch` para `spending.cash-category` ou `spending.purchase-category` conforme `entry.kind`.
6. Quando `breakdown.uncategorized_count > 0`, um aviso com a contagem e um botão que faz `router.post` para `spending.categorize`.
7. Estado vazio: quando `breakdown.entries` está vazio, uma mensagem dizendo que não há gastos naquele mês e que a análise começa depois da primeira importação de fatura.

- [ ] **Step 5: Escrever os dois componentes de gráfico**

`category-breakdown-chart.tsx` e `category-trend-chart.tsx`, com `recharts`, seguindo `projection-chart.tsx`: `ResponsiveContainer`, tooltip própria formatando com `formatBRL`, cores vindas da prop (nunca hardcoded no componente — a fonte é o enum no servidor).

- [ ] **Step 6: Adicionar ao menu**

Em `resources/js/components/app-sidebar.tsx`, acrescentar a `mainNavItems`, depois de "Histórico":

```tsx
    {
        title: 'Gastos',
        href: spending.index(),
        icon: ChartPie,
    },
```

importando `ChartPie` de `lucide-react` e `spending` de `@/routes/spending` (confirme o caminho gerado pelo Wayfinder).

- [ ] **Step 7: Verificar**

```bash
npm run types:check
npm run lint:check
npm run format:check
npm run build
php artisan test --compact tests/Feature/SpendingTest.php
```
Expected: PASS em todos.

- [ ] **Step 8: Commit**

```bash
git add resources/js
git commit -m "feat: tela de gastos por categoria"
```

---

### Task 14: Verificação manual contra o Ollama real

Nenhuma fixture prova qualidade de classificação: os nomes de estabelecimento nelas estão embaralhados. Esta task é a única que responde se a feature funciona de verdade, e é condição para dar o trabalho por pronto.

**Files:**
- Nenhum arquivo de produção. Possíveis ajustes em `app/Ai/Agents/CategorizeExpenses.php` (instruções) conforme o resultado.

**Interfaces:**
- Consumes: tudo que veio antes.

- [ ] **Step 1: Confirmar que o Ollama responde**

Run: `curl -s http://127.0.0.1:11434/api/tags`
Expected: JSON listando `gemma4:e4b`. Se falhar, peça ao usuário para rodar `ollama serve`.

- [ ] **Step 2: Subir a aplicação e a fila**

Run: `composer run dev` (confira em `composer.json` o que esse script sobe; a fila precisa estar rodando — `php artisan queue:work` — senão o job nunca sai da tabela `jobs`).

- [ ] **Step 3: Importar uma fatura real**

Peça ao usuário o PDF e, para Itaú e Mercado Pago, **a senha** — os arquivos em `faturas/` estão protegidos. Importe pela interface, confirme, e observe a linha de aviso da contagem de compras à vista.

**REQUIRED SUB-SKILL:** `claude-in-chrome` para dirigir o navegador. **Não gere screenshot como entrega** — o CLAUDE.md manda servir a UI para o usuário ver. Sirva e mostre.

- [ ] **Step 4: Conferir a classificação**

Run: `php artisan tinker --execute 'App\Models\CashPurchase::query()->latest()->take(40)->get(["description","category"])->each(fn ($c) => print("{$c->description} => ".($c->category?->value ?? "NULO")."\n"));'`

Leia as quarenta linhas com o usuário. O que procurar:
- Estabelecimento óbvio classificado errado (padaria em Eletrônicos) — é prompt ruim, ajuste `instructions()`.
- Excesso de `outros` — pode ser prompt ou pode ser descrição genuinamente ilegível. Distinga antes de mexer.
- Categoria nula — job falhou. Investigue com `php artisan queue:failed` e `mcp__laravel-boost__last-error`.

- [ ] **Step 5: Conferir a tela**

Abra `/gastos` no navegador. Confirme que as barras somam o mesmo total que a lista, que o seletor de mês muda os três blocos, e que editar uma categoria persiste e marca como manual (reprocessar não deve desfazer a edição).

- [ ] **Step 6: Rodar a suíte inteira e o phpstan**

```bash
php artisan test --compact
vendor/bin/phpstan analyse
npm run types:check && npm run lint:check && npm run build
```
Expected: PASS em tudo. **Conserte qualquer falha, mesmo em teste que você não escreveu.**

- [ ] **Step 7: Commit final**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "fix: ajustes das instrucoes do agente apos verificacao com fatura real"
```

- [ ] **Step 8: Fechar a branch**

**REQUIRED SUB-SKILL:** `superpowers:finishing-a-development-branch`. Não faça merge em `main` sem passar por ela e sem o aval do usuário.

---

## Self-Review

**Cobertura do spec:**

| Requisito do spec | Task |
|---|---|
| Compra à vista persistida, fora do fluxo de parcelamentos | 7, 8 |
| Categoria nos dois tipos de compra | 7, 10 |
| Regime de caixa por mês de cobrança | 7 (`billed_at`), 11 |
| Lista fixa imposta pelo schema | 6, 9 |
| À vista não passa pela revisão | 8 |
| Categoria editável, correção permanente | 12 |
| IA nunca bloqueia a importação | 10 (job de fila), 8 (gravação independente) |
| Provedor é configuração | 9 |
| Extração nos quatro emissores | 2, 3, 4, 5 |
| Índice único contra reimportação | 7, 8 |
| Barras horizontais por categoria, barras de 12 meses, lista do mês | 11, 13 |
| Balde "Sem categoria" com reprocessar | 11, 12, 13 |
| Nenhum teste depende de Ollama | 9, 10 (`fake()`) |
| Verificação manual com fatura real | 14 |

**Placeholders:** o único valor deliberadamente ausente são os doze hexadecimais do `ExpenseCategory::color()` na Task 6, que vêm da skill `dataviz` no Step 1 daquela task. O teste "não repete cor entre categorias" falha enquanto os `#000000` estiverem lá, então não passam despercebidos.

**Consistência de tipos:** `ParsedCashLine(description, transactionDay, transactionMonth, amountCents)` é construído do mesmo jeito nas Tasks 2–5. `WriteCashPurchases::handle` recebe as linhas na forma `{description, day, month, amount_cents}`, que é exatamente a forma gravada na sessão pelo `store()` e validada por `isCashLine()`. `BuildSpendingBreakdown` devolve `entries[].kind` como `'cash' | 'purchase'`, que é o que a Task 13 usa para escolher entre as duas rotas de PATCH da Task 12.

**Riscos de execução conhecidos:**

1. **`laravel/ai` é v0.10.1 e este plano foi escrito lendo seu fonte, não usando-o.** As assinaturas de `fake()`, `instructions()` e `providerOptions()` foram conferidas no repositório, mas se o pacote divergir, **o pacote vence** — ajuste o código e siga.
2. **A Task 8 relaxa uma invariante existente** (`candidates` não-vazio). Testes atuais de `InvoiceImportTest` podem depender dela. Isso é esperado e está sinalizado no Step 11 daquela task.
3. **A Task 13 diverge do spec original por decisão do usuário:** barras horizontais ordenadas no lugar da rosca, porque doze matizes categóricos não se separam com segurança. O spec foi atualizado.
