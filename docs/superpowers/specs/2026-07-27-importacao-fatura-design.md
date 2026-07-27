# Meus Parcelamentos — Importação de Fatura (Spec 2)

**Data:** 2026-07-27
**Escopo:** importar parcelamentos a partir do PDF da fatura do cartão. Substitui a menção a "OCR" do Spec 1.
**Depende de:** Spec 1 (núcleo) implementado — `cards`, `purchases`, `installments`, `GenerateInstallments`.

## Objetivo

Deixar o usuário lançar seus parcelamentos a partir do PDF da fatura em vez de digitar um por um, sem que o app grave dado inferido errado.

## Decisões que moldam o resto

1. **PDF com camada de texto, não OCR.** Fatura de banco não é imagem escaneada. Extração via `pdftotext`, sem reconhecimento óptico.
2. **Sem LLM.** Os quatro emissores alvo são conhecidos e há faturas reais para testar. Parser dedicado é determinístico, gratuito e testável offline. LLM como fallback para emissor desconhecido é evolução futura, não faz parte deste spec — a interface `InvoiceParser` já acomoda.
3. **Assinatura do Claude Code não serve como API key.** Max/Pro dão acesso ao CLI, não a chamadas programáticas de dentro da aplicação. O caminho legítimo seria uma API key do console, cobrada por token. Descartado junto com a decisão 2.
4. **Só linhas parceladas.** Compra à vista não gera compromisso futuro. Se entrasse como `installments_count = 1`, apareceria na projeção e no "comprometido" do dashboard como dívida futura, distorcendo exatamente o número que o app existe para mostrar.
5. **Revisão obrigatória.** Nenhum `Purchase` é gravado sem confirmação do usuário. A data da compra é inferida, e errar por um mês desloca todo o cronograma.
6. **Sem tabela nova.** Nenhuma migration. Os candidatos vivem na sessão entre upload e confirmação; a deduplicação sai de `purchases`.
7. **O PDF não é armazenado.** Lido do arquivo temporário do upload, parseado e descartado no mesmo request.

## Emissores suportados

Nubank, Mercado Pago, Itaú, Renner. PDF de emissor não reconhecido é rejeitado com a lista de suportados. Não existe parser genérico de fallback: um parser que adivinha é o que produz importação silenciosamente errada.

## Arquitetura

### Extração

Namespace novo `app/Invoices/`.

```
app/Invoices/
  ExtractsInvoiceText.php        interface: (string $path, ?string $password): string
  PopplerTextExtractor.php       pdftotext -layout [-upw senha]
  InvoiceParser.php              interface: supports(string): bool | parse(string): ParsedInvoice
  Parsers/NubankInvoiceParser.php
  Parsers/MercadoPagoInvoiceParser.php
  Parsers/ItauInvoiceParser.php
  Parsers/RennerInvoiceParser.php
  ResolveInvoiceParser.php       primeiro parser registrado que suportar o texto
  ReconcileInvoiceLine.php       linha + vencimento + cartão → candidato
  ParsedInvoice.php              readonly DTO
  ParsedInvoiceLine.php          readonly DTO
  InvoiceCandidate.php           readonly DTO
```

`pdftotext` (poppler) é dependência de sistema, aprovada. Preserva colunas — necessário para Itaú e Renner — e abre PDF protegido por senha via `-upw`, necessário se a fatura do Itaú vier cifrada. A interface `ExtractsInvoiceText` existe para permitir troca de motor e para os testes rodarem sem o binário.

### Contrato dos parsers

Cada parser é obrigado a extrair três coisas. Falha em qualquer uma aborta a importação:

1. **Vencimento da fatura** — âncora de toda a reconciliação.
2. **Últimos 4 dígitos do cartão** — para casar com o `Card` do usuário.
3. **Linhas com marcação de parcela** — `Parcela 3/10`, `PARC 03/10`, `3 de 10`. O padrão é por emissor, não global.

`ParsedInvoiceLine`: `description`, `merchant`, `transactionDay`, `installmentNumber`, `installmentsCount`, `amountCents`.

### Reconciliação

O problema: a fatura mostra o valor da *parcela* e "3/10", não o total nem a data da compra. O app deriva todo o cronograma de `purchased_at` via `CalculateFirstDueDate`. Então a data da compra precisa ser exata.

A fatura fornece um fato impresso: o **vencimento da fatura**. Para uma linha `3/10` numa fatura que vence em 10/05/2026, a parcela nº 3 vence em 10/05. Isso permite resolver para a data em vez de adivinhá-la.

**Passo 1 — inverter `CalculateFirstDueDate`:**

```
firstDue     = vencimentoDaFatura − (N−1) meses
closingMonth = dueDay > closingDay ? mês(firstDue) : mês(firstDue) − 1
purchased_at = (diaDaTransação ≤ closingDay ? closingMonth : closingMonth − 1)
               com dia = diaDaTransação, limitado ao último dia do mês
```

O dia vem da data impressa na linha; o mês sai da conta. Descartada a alternativa de confiar na data impressa: emissores divergem no que carimbam (data original vs. data do ciclo), e um mês de erro desloca todas as parcelas.

**Passo 2 — verificar por round-trip:**

Roda `BuildInstallmentSchedule` com a data resolvida e confere se a parcela nº N caiu no **mês** de vencimento da fatura.

- Bateu → `matched`
- Não bateu → `divergent`: vai para revisão desmarcada, com a data editável. Nunca é gravado às cegas.

A comparação é por mês, não por data exata, porque emissores deslocam o vencimento que cai em fim de semana ou feriado: um cartão com `due_day = 10` recebe fatura vencendo em 11/05 se 10/05 for domingo. Comparar a data exata marcaria como divergente toda fatura nessa situação, enchendo a revisão de falso-positivo. O erro que importa — mês errado, que desloca o cronograma inteiro — continua sendo pego.

Pela mesma razão, a aritmética de `firstDue` opera sobre meses e só depois reconstrói o dia a partir de `due_day`. Subtrair meses de uma data já truncada (28/02 vindo de um `due_day = 31`) perde informação.

O pipeline existente é usado como *verificador*, não só como gerador. Quando a conta não fecha, o app admite que não sabe.

**Passo 3 — deduplicação:**

Impressão digital: `card_id + total_amount_cents + installments_count + purchased_at`. A mesma compra aparece em faturas consecutivas como `3/10` e depois `4/10`, mas ambas resolvem para o mesmo `purchased_at` e o mesmo total. A segunda vira `duplicate` e vem desmarcada.

A descrição fica fora da impressão digital de propósito: o texto do estabelecimento varia entre faturas (`MERCADOLIVRE*ABC` vs `MERCADO LIVRE`) e mataria a deduplicação.

### Limitações conhecidas

- **`total = parcela × N` é suposição.** Se o banco fez 9x R$ 129,90 + 1x R$ 129,93, uma linha isolada não revela. O erro fica em centavos.
- **A conferência de valor no round-trip é tautológica.** Como o total é definido como `parcela × N`, `SplitIntoInstallments` sempre devolve o valor exato. Quem valida de fato é a data.
- **Colisão de deduplicação.** Duas compras genuinamente distintas no mesmo cartão, mesmo dia, mesmo valor e mesmo número de parcelas colapsam em uma. Raro, e visível na revisão, mas é perda real.

## Fluxo HTTP

Rotas em `routes/parcelamentos.php`, `InvoiceImportController`, sob `auth` + `verified` como o resto do app.

| Rota | Ação |
|---|---|
| `GET importar` | formulário: arquivo PDF + senha opcional |
| `POST importar` | extrai → parseia → reconcilia → candidatos na sessão → redirect |
| `GET importar/revisao` | tela de revisão, lê os candidatos da sessão |
| `POST importar/confirmar` | cria os `Purchase` marcados + `GenerateInstallments` em cada |

Validação do upload: `required|file|mimes:pdf|max:10240` (10 MB). Autorização de cartão pela `CardPolicy` existente.

### Tela de revisão

`resources/js/pages/invoices/import.tsx` e `review.tsx`.

- Cabeçalho: emissor detectado, vencimento da fatura, cartão casado pelos 4 dígitos — ou seletor, se não casou com nenhum cartão do usuário
- Uma linha por parcelamento: descrição, valor da parcela, `3/10`, total calculado, data da compra inferida, checkbox
- `matched` marcado; `duplicate` desmarcado com nota "já importado"; `divergent` desmarcado, destacado, data editável
- Rodapé: "Importar N compras"

Componente de status próprio. `StatusChip` não é reaproveitado: `Pago/Próximo/Pendente` e `matched/duplicate/divergent` são vocabulários diferentes, e sobrepor os dois confunde as duas telas.

### Erros

Todos como falha de validação na tela de upload: PDF ilegível, emissor não reconhecido, senha errada ou faltando, fatura sem nenhuma linha parcelada, vencimento não encontrado.

`try/catch` apenas na fronteira do `pdftotext`. O resto sobe.

## Testes

**As fixtures são texto, não PDF:** `tests/Fixtures/faturas/{nubank,mercado-pago,itau,renner}.txt`, contendo a saída do `pdftotext` já anonimizada. Os testes de parser viram `string → DTO` puros: rodam sem poppler, sem upload, sem I/O.

Os PDFs reais ficam **fora do repositório**. Anonimizar texto é confiável; anonimizar PDF não é, por causa de metadados, camadas e objetos embutidos.

| Teste | Cobre |
|---|---|
| Unit, um por parser (4) | detecção do emissor, vencimento, 4 dígitos, linhas parceladas; e o negativo: linha à vista não vira candidato |
| Unit, `ReconcileInvoiceLine` | `due_day < closing_day`, compra dia 31 em mês de 30, `N=1`, `N=M`, virada de ano |
| Unit de inversão | varre `closing_day` × `due_day` × N e afirma que resolver a data e rodar o pipeline devolve o vencimento alvo — no estilo do `SchedulePreviewParityTest` |
| Feature, fluxo completo | `ExtractsInvoiceText` fake com o texto da fixture: upload → revisão → confirmação grava `Purchase` + parcelas corretas; dedup na segunda importação; emissor desconhecido; fatura sem parcelamento |
| Feature, `PopplerTextExtractor` | um PDF mínimo gerado no teste, só para provar a chamada do binário. `skip` se `pdftotext` não existir |

## Fora de escopo

- OCR de foto ou print de fatura
- LLM como fallback para emissor desconhecido
- Importação de CSV/OFX
- Compras à vista, IOF, anuidade, estornos
- Histórico de importações
- Emissores além dos quatro listados

## Pré-requisito para implementação

Os quatro PDFs reais de fatura, para extrair e anonimizar o texto das fixtures. Sem eles os parsers não podem ser escritos nem testados.
