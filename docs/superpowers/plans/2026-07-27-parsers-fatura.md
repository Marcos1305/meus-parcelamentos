# Parsers de Fatura por Emissor — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar os quatro parsers de fatura (Nubank, Mercado Pago, Itaú, Renner) e habilitar a importação.

**Architecture:** Cada parser implementa `App\Invoices\InvoiceParser`: `supports()` reconhece o emissor por assinatura no texto, `parse()` devolve `ParsedInvoice` com o vencimento, os quatro dígitos (quando existirem) e apenas as linhas parceladas. `ResolveInvoiceParser` escolhe o primeiro que suportar.

**Tech Stack:** Laravel 13, PHP 8.4, Pest v4, poppler (`pdftotext`).

**Continua:** `docs/superpowers/plans/2026-07-27-importacao-fatura.md` (Tasks 1–8, concluídas).

## Global Constraints

- Fixtures são **texto anonimizado** em `tests/Fixtures/faturas/*.txt`, já commitadas. Os PDFs reais nunca entram no repositório.
- Testes de parser vivem em `tests/Unit` — plain PHPUnit, **sem container do Laravel**. Use `new`, nunca `app()`.
- `parse()` lança `UnreadableInvoiceException` quando não encontra o vencimento.
- `ParsedInvoiceLine` valida as próprias invariantes no construtor. Um parser que produza `installmentNumber > installmentsCount` explode ali — não silencie isso.
- Só linhas com marcação de parcela viram `ParsedInvoiceLine`. Compra à vista, pagamento, estorno, IOF e anuidade são descartados.
- Linhas com valor negativo nunca viram candidato.
- `try/catch` só em fronteira de sistema — nenhum pertence a um parser.
- Tipos de retorno e hints explícitos em todo método; PHPDoc com array shapes.
- PHPDoc no lugar de comentário inline. Comentário documenta o código, não o processo.
- `vendor/bin/pint --dirty --format agent` antes de cada commit.
- `composer types:check` (PHPStan nível 7) sem erros.
- Sem dependência nova de composer ou npm. Sem migration.
- Mensagens de commit em português.
- Não criar arquivos de documentação.

## Fatos apurados nas faturas reais

Levantados lendo os quatro PDFs. Cada um contradiz alguma suposição do spec original.

### Multi-cartão

Nubank lista **17** números de cartão distintos numa fatura; Mercado Pago, **5**. São cartões virtuais de uma conta só. Não existe um "cartão da fatura" para esses dois: ambos devolvem `lastFourDigits: null`, e o `resolveCard()` do controller já pede o cartão ao usuário nesse caso. Renner e Itaú têm número único e casam automaticamente.

### A data impressa não significa o mesmo em todo emissor

Nubank carimba as parcelas `N≥2` com a data de abertura do ciclo (16 das 20 linhas são `02 JUN`); só as parcelas `1/M` trazem a data real. Itaú, Renner e Mercado Pago imprimem a data original da compra.

Isso **não** exige tratamento no parser: `ReconcileInvoiceLine` deriva o mês a partir do vencimento da fatura e usa apenas o **dia** da linha. Para parcelas antigas do Nubank a data resultante terá dia 02 — o cronograma continua correto, a data exibida fica aproximada. Documentado, não corrigido: a informação não existe no PDF.

### Seção "próximas faturas" duplica todos os parcelamentos

Renner e Itaú repetem cada parcelamento numa seção final, com o número da parcela **seguinte**. Varrer o arquivo inteiro criaria uma segunda compra por parcelamento, com data derivada diferente — e a deduplicação **não pegaria**, justamente porque a data difere. Cada parser precisa delimitar a seção de lançamentos.

### `supports()` sobreposto é risco real, não teórico

A fatura do **Itaú contém a palavra `RENNER`** (`LOJAS RENNER F 01/03` — uma compra em Lojas Renner paga com o cartão Itaú). Um parser do Renner que casasse por `/renner/i` sequestraria a fatura do Itaú.

Cada `supports()` deve casar por **assinatura do emissor**, não por nome que possa aparecer como estabelecimento:

| Emissor | Assinatura segura |
|---|---|
| Nubank | `Nu Pagamentos` |
| Renner | `Realize Sociedade` |
| Itaú | `ITAU UNIBANCO` ou `Banco Itaú` |
| Mercado Pago | `Mercado Pago` |

---

### Task 9: Duplicatas legítimas dentro da mesma fatura

Decisão do usuário: **duas linhas idênticas na mesma fatura são duas compras reais e ambas devem ser importadas.** Evidência: a fatura do Nubank traz `Lite *Vivoeasyanual - Parcela 6/12  R$ 20,00` duas vezes, mesmo cartão virtual e mesma data — duas assinaturas. Colapsar subestima a dívida, que é o erro pior num app que existe para mostrar quanto se deve.

Hoje `BuildInvoiceCandidates` marca a segunda como `Duplicate` e a tela desabilita o checkbox, então ela é **impossível de importar**.

**Files:**
- Modify: `app/Invoices/BuildInvoiceCandidates.php`
- Modify: `app/Http/Controllers/InvoiceImportController.php`
- Test: `tests/Feature/BuildInvoiceCandidatesTest.php`, `tests/Feature/InvoiceImportTest.php`

**Interfaces:**
- Consumes: `PurchaseAlreadyExists` (existente).
- Produces: comportamento — colisão intra-lote não marca `Duplicate`; a checagem de escrita ignora o que foi gravado no próprio lote.

- [ ] **Step 1: Escrever os testes que falham**

Em `tests/Feature/BuildInvoiceCandidatesTest.php`:

```php
it('nao marca como duplicata duas linhas identicas da mesma fatura', function (): void {
    $card = Card::factory()->for(User::factory())->create(['closing_day' => 3, 'due_day' => 10]);

    $candidates = app(BuildInvoiceCandidates::class)->handle(
        parsedInvoice('2026-05-10', parsedLine(3, 10, 2, 12_990), parsedLine(3, 10, 2, 12_990)),
        $card,
    );

    expect($candidates[0]->status)->toBe(CandidateStatus::Matched)
        ->and($candidates[1]->status)->toBe(CandidateStatus::Matched);
});
```

Em `tests/Feature/InvoiceImportTest.php`, um teste que confirma duas linhas idênticas e verifica que **duas** `Purchase` foram criadas, e um segundo que reimporta a mesma fatura depois e confirma que nada novo é gravado.

- [ ] **Step 2: Rodar e confirmar que falham**

```bash
php artisan test --compact --filter="BuildInvoiceCandidates|InvoiceImport"
```

- [ ] **Step 3: Remover a marcação intra-lote de `BuildInvoiceCandidates`**

A deduplicação volta a considerar apenas compras já persistidas. A marcação por `$seen[$fingerprint]` sai.

- [ ] **Step 4: Fazer a checagem de escrita ignorar o próprio lote**

Em `confirm()`, `PurchaseAlreadyExists` passa a ser consultado contra o estado **anterior** ao lote. Colete as assinaturas já existentes antes de abrir a transação, ou restrinja a consulta às compras criadas antes do início da confirmação. Duas linhas idênticas no mesmo `confirm()` gravam duas compras; reimportar a mesma fatura depois não grava nada.

- [ ] **Step 5: Ajustar a mensagem**

O texto de pulo continua verdadeiro para o caso que sobrou (já constava no histórico ou não pôde ser lida).

- [ ] **Step 6: Rodar tudo**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
composer types:check
```

- [ ] **Step 7: Commit**

```bash
git add app tests
git commit -m "fix: importa duas linhas iguais da mesma fatura como compras distintas"
```

---

### Task 10: NubankInvoiceParser

**Files:**
- Create: `app/Invoices/Parsers/NubankInvoiceParser.php`
- Test: `tests/Unit/NubankInvoiceParserTest.php`
- Fixture (já existe): `tests/Fixtures/faturas/nubank.txt`

**Interfaces:**
- Consumes: `InvoiceParser`, `ParsedInvoice`, `ParsedInvoiceLine`, `UnreadableInvoiceException`.
- Produces: `NubankInvoiceParser`, registrada na Task 14.

**Layout:**

```
Data de vencimento: 09 JUL 2026

              02 JUN       •••• 3778   Mp *Petinolutuluvagov - Parcela 4/6                                      R$ 44,04
              02 JUN       •••• 0537   D - Parcela 4/4                                                          R$ 161,23
              16 JUN           Nu Seguro Vida                                                                  R$ 24,35
              05 JUN       •••• 1654   Netflix Entretenimento                                                   R$ 20,90
```

- Assinatura: `Nu Pagamentos`
- Vencimento: `Data de vencimento: DD MMM YYYY`, mês em português maiúsculo de 3 letras (`JAN FEV MAR ABR MAI JUN JUL AGO SET OUT NOV DEZ`)
- `lastFourDigits`: **sempre `null`** — a fatura tem 17 cartões virtuais
- Linha parcelada: `DD MMM` + `•••• NNNN` opcional + descrição + ` - Parcela N/M` + `R$ valor`
- Descartar: linhas sem ` - Parcela N/M`; a seção `Pagamentos e Financiamentos`; valores negativos (aparecem com `−` U+2212, não com hífen ASCII)
- **20** linhas parceladas na fixture

- [ ] **Step 1: Escrever o teste que falha**

`tests/Unit/NubankInvoiceParserTest.php`:

```php
<?php

use App\Invoices\Parsers\NubankInvoiceParser;
use App\Invoices\UnreadableInvoiceException;

function nubankFixture(): string
{
    return file_get_contents(__DIR__.'/../Fixtures/faturas/nubank.txt');
}

it('reconhece a fatura do nubank', function (): void {
    expect((new NubankInvoiceParser)->supports(nubankFixture()))->toBeTrue();
});

it('nao reconhece fatura de outro emissor', function (string $fixture): void {
    $text = file_get_contents(__DIR__.'/../Fixtures/faturas/'.$fixture);

    expect((new NubankInvoiceParser)->supports($text))->toBeFalse();
})->with(['itau.txt', 'renner.txt', 'mercado-pago.txt']);

it('extrai o vencimento e o emissor', function (): void {
    $invoice = (new NubankInvoiceParser)->parse(nubankFixture());

    expect($invoice->dueDate->toDateString())->toBe('2026-07-09')
        ->and($invoice->issuer)->toBe('Nubank');
});

it('nao identifica um cartao unico porque a fatura tem varios', function (): void {
    expect((new NubankInvoiceParser)->parse(nubankFixture())->lastFourDigits)->toBeNull();
});

it('extrai apenas as linhas parceladas', function (): void {
    $lines = (new NubankInvoiceParser)->parse(nubankFixture())->lines;

    expect($lines)->toHaveCount(20);

    foreach ($lines as $line) {
        expect($line->installmentsCount)->toBeGreaterThan(0)
            ->and($line->installmentNumber)->toBeLessThanOrEqual($line->installmentsCount)
            ->and($line->amountCents)->toBeGreaterThan(0);
    }
});

it('extrai os campos de uma linha parcelada', function (): void {
    $lines = (new NubankInvoiceParser)->parse(nubankFixture())->lines;

    expect($lines[0]->installmentNumber)->toBe(4)
        ->and($lines[0]->installmentsCount)->toBe(6)
        ->and($lines[0]->amountCents)->toBe(4_404)
        ->and($lines[0]->transactionDay)->toBe(2)
        ->and($lines[0]->description)->toContain('Parcela')->not->toContain('R$');
});

it('recusa texto sem vencimento', function (): void {
    expect(fn () => (new NubankInvoiceParser)->parse('Nu Pagamentos S.A. sem data'))
        ->toThrow(UnreadableInvoiceException::class);
});
```

Ajuste `description` conforme a decisão de implementação: se a descrição for gravada sem o sufixo ` - Parcela N/M`, troque a asserção para `->not->toContain('Parcela')`. Escolha um dos dois e faça o teste refletir a escolha.

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
php artisan test --compact --filter=NubankInvoiceParser
```

- [ ] **Step 3: Implementar até passar**

- [ ] **Step 4: Verificar**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=NubankInvoiceParser
composer types:check
```

- [ ] **Step 5: Commit**

```bash
git add app/Invoices/Parsers/NubankInvoiceParser.php tests/Unit/NubankInvoiceParserTest.php
git commit -m "feat: parser de fatura do Nubank"
```

---

### Task 11: MercadoPagoInvoiceParser

**Files:**
- Create: `app/Invoices/Parsers/MercadoPagoInvoiceParser.php`
- Test: `tests/Unit/MercadoPagoInvoiceParserTest.php`
- Fixture: `tests/Fixtures/faturas/mercado-pago.txt`

**Layout:**

```
Vencimento: 10/07/2026

Cartão Visa [************0677]

13/02      VUVEPEPEDELU*NALUCUFOBOLIL           Parcela 17 de 18       R$ 38,41
08/06      GEBUC LEROSEV VILADO                                        R$ 13,00
```

- Assinatura: `Mercado Pago`
- Vencimento: `Vencimento: DD/MM/YYYY` (aparece em vários rodapés; todos iguais) ou `Vence em`
- `lastFourDigits`: **sempre `null`** — 5 cartões na mesma fatura
- Linha parcelada: `DD/MM` + estabelecimento + `Parcela N de M` + `R$ valor`
- Descartar: linhas sem `Parcela N de M`; `Pagamento da fatura`; a seção explicativa de parcelamento de fatura (`Parcelamento de fatura`, `Parcele ou pague`) que contém a palavra `Parcela` sem o padrão numérico
- **22** linhas parceladas na fixture

Testes espelhando os da Task 10: `supports()` verdadeiro na própria fixture e falso nas outras três; vencimento `2026-07-10`; `lastFourDigits` nulo; 22 linhas; campos da primeira linha (`17`, `18`, `3_841`, dia `13`); recusa texto sem vencimento.

Mesma sequência de steps da Task 10, commit `feat: parser de fatura do Mercado Pago`.

---

### Task 12: ItauInvoiceParser

O mais difícil dos quatro: duas colunas e sem a palavra "Parcela".

**Files:**
- Create: `app/Invoices/Parsers/ItauInvoiceParser.php`
- Test: `tests/Unit/ItauInvoiceParserTest.php`
- Fixture: `tests/Fixtures/faturas/itau.txt`

**Layout:**

```
                                                                           Vencimento: 09/07/2026
           Cartão 0000.LACA.LACA.5687

                 DATA     ESTABELECIMENTO                            VALOR EM R$
                 04/09    DOMO SEVEROBOG 10/10                             129,01
                          outros CARIACICA

                 08/06    PAGAMENTO                                      -4.713,38        18/05    GOFUPI *SapalE 02/02                             111,30

     Compras parceladas - próximas faturas
     21/10   PucetugItufolA 10/10                             170,00
```

- Assinatura: `ITAU UNIBANCO` ou `Banco Itaú`
- Vencimento: `Vencimento: DD/MM/YYYY`
- `lastFourDigits`: os 4 últimos de `Cartão NNNN.XXXX.XXXX.NNNN` → `5687`
- Marcador de parcela: `NN/MM` colado ao fim do estabelecimento, **sem** a palavra "Parcela"
- **Duas colunas:** uma linha de texto pode conter DUAS transações. O regex tem que varrer **todas** as ocorrências da linha, não ancorar em fim de linha
- **Cortar** tudo a partir de `Compras parceladas - próximas faturas` — ali cada parcelamento se repete com o número seguinte
- Descartar valores negativos (`PAGAMENTO -4.713,38`)
- **19** linhas parceladas na seção de detalhe

Testes: além dos espelhados, dois específicos e obrigatórios:

```php
it('ignora a secao de proximas faturas', function (): void {
    $lines = (new ItauInvoiceParser)->parse(itauFixture())->lines;

    // 10/10 aparece na secao de detalhe; 10/10 da secao futura seria uma
    // segunda ocorrencia do mesmo parcelamento com a parcela seguinte.
    expect($lines)->toHaveCount(19);
});

it('extrai as duas transacoes de uma linha de coluna dupla', function (): void {
    $lines = (new ItauInvoiceParser)->parse(itauFixture())->lines;
    $valores = array_map(fn ($line): int => $line->amountCents, $lines);

    expect($valores)->toContain(11_130);
});
```

`11_130` é a `GOFUPI *SapalE 02/02  111,30`, que divide a linha de texto com `PAGAMENTO -4.713,38`. Se o parser ancorar em fim de linha, esse valor some e o teste falha.

Mesma sequência de steps, commit `feat: parser de fatura do Itaú`.

---

### Task 13: RennerInvoiceParser

**Files:**
- Create: `app/Invoices/Parsers/RennerInvoiceParser.php`
- Test: `tests/Unit/RennerInvoiceParserTest.php`
- Fixture: `tests/Fixtures/faturas/renner.txt`

**Layout:**

```
0000**.******.7001

                  Pagamento Total²         Data de Vencimento         Limite Total
                    R$ 3.427,37                 10/05/2026            R$ 4.800,00

Lançamentos detalhados do período:
26/09/2025    Compra Parcelada Parc 7/9            PONUFARABORI RIFULUSIG      54,00
26/03/2026    Compra a Vista                       BIROGUG                    318,49

Compras parceladas | Próximas faturas:
26/09/2025     PONUFARABORI Parc. 8/9              PONUFARABORI RIFULUSIG      54,00
```

- Assinatura: **`Realize Sociedade`**, nunca `/renner/i` — a fatura do Itaú contém `RENNER` como estabelecimento
- Vencimento: `Vencimento:      DD/MM/YYYY` na linha do recibo, ou o `10/05/2026` sob `Data de Vencimento`
- `lastFourDigits`: os 4 últimos de `NNNN**.******.NNNN` → `7001`
- Seção de lançamentos: de `Lançamentos detalhados do período:` até antes de `Compras parceladas | Próximas faturas:`
- Linha parcelada: `DD/MM/YYYY` + `Compra Parcelada Parc N/M` + estabelecimento + valor
- Descartar: `Compra a Vista`; valores negativos (`PagamentoFaturaPix -2.610,15`)
- **15** linhas parceladas na seção de detalhe; a seção futura tem 11 (formato `Parc. N/M`, com ponto) e **não** entra

Testes: espelhados, mais um obrigatório:

```php
it('nao reconhece a fatura do itau, que cita Renner como estabelecimento', function (): void {
    $text = file_get_contents(__DIR__.'/../Fixtures/faturas/itau.txt');

    expect((new RennerInvoiceParser)->supports($text))->toBeFalse();
});

it('ignora a secao de proximas faturas', function (): void {
    expect((new RennerInvoiceParser)->parse(rennerFixture())->lines)->toHaveCount(15);
});
```

Mesma sequência de steps, commit `feat: parser de fatura do Renner`.

---

### Task 14: Registrar os parsers e habilitar a importação

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/ResolveInvoiceParserTest.php`, `tests/Feature/InvoiceImportTest.php`

- [ ] **Step 1: Escrever o teste de não-sobreposição**

Em `tests/Unit/ResolveInvoiceParserTest.php`, um teste que percorre as quatro fixtures e afirma que **exatamente um** parser reconhece cada uma:

```php
it('cada fatura e reconhecida por exatamente um parser', function (string $fixture): void {
    $text = file_get_contents(__DIR__.'/../Fixtures/faturas/'.$fixture);

    $suportam = array_filter(
        [new NubankInvoiceParser, new MercadoPagoInvoiceParser, new ItauInvoiceParser, new RennerInvoiceParser],
        fn (InvoiceParser $parser): bool => $parser->supports($text),
    );

    expect($suportam)->toHaveCount(1);
})->with(['nubank.txt', 'mercado-pago.txt', 'itau.txt', 'renner.txt']);
```

Esse teste é o que impede a regressão do `RENNER` dentro da fatura do Itaú. A ordem de registro deixa de ser carga estrutural quando ele passa.

- [ ] **Step 2: Registrar os quatro no `AppServiceProvider`**

```php
$this->app->bind(ResolveInvoiceParser::class, fn (): ResolveInvoiceParser => new ResolveInvoiceParser([
    new NubankInvoiceParser,
    new MercadoPagoInvoiceParser,
    new ItauInvoiceParser,
    new RennerInvoiceParser,
]));
```

- [ ] **Step 3: Conferir que o gate abriu sozinho**

`hasParsers()` passa a devolver `true`, então o item volta ao menu e `create()` renderiza. O teste existente que cobre os dois estados continua válido — o estado "vazio" agora é montado explicitamente no teste, não pelo binding de produção. Ajuste-o se necessário, sem removê-lo.

- [ ] **Step 4: Rodar tudo**

```bash
composer run ci:check
```

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "feat: habilita a importação de fatura com os quatro emissores"
```

## Verificação final

```bash
composer run ci:check
```

Tem que passar inteiro: eslint, prettier, tsc, pint, phpstan nível 7 e Pest.
