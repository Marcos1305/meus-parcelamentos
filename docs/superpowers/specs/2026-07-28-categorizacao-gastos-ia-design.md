# Categorização de Gastos por IA (Spec 4)

**Data:** 2026-07-28
**Escopo:** capturar as compras à vista da fatura, classificar todo gasto por categoria usando um modelo local via Ollama, e apresentar isso em gráficos numa tela nova.
**Reverte:** a decisão 5 do Spec 1 ("sem categorias"). Ela valia enquanto o app só conhecia compromisso futuro; este spec introduz a leitura retrospectiva que a torna necessária.

## Objetivo

Responder "para onde foi o meu dinheiro" com a mesma confiança com que o app hoje responde "quanto eu devo". Hoje ele não consegue: os parsers descartam toda linha que não é parcelada, então mercado, combustível e lanches — a maior parte do gasto real — nunca entram no banco.

## Decisões que moldam o resto

1. **A compra à vista é persistida, mas fica fora do fluxo de parcelamentos.** Tabela própria, consultável a qualquer momento. Não aparece no Histórico nem no Planejamento, não gera parcela, não entra na projeção. É fato passado, não compromisso.
2. **Categoria vale para os dois tipos de compra.** À vista e parcelada recebem categoria. Sem isso o gráfico esconderia justamente as compras grandes.
3. **Regime de caixa, por mês de cobrança.** Um parcelamento de R$ 4.000 em 10x conta R$ 400 no mês de cada parcela, não R$ 4.000 no mês da compra. É a régua que o app inteiro já usa.
4. **Lista fixa de categorias, imposta pelo schema.** O enum entra no JSON Schema da resposta; o modelo não consegue devolver rótulo fora dele. Alternativa descartada: rótulo livre — produz "mercado", "supermercado" e "compras de supermercado" para o mesmo estabelecimento, e o gráfico fragmenta o mesmo gasto em três fatias.
5. **A compra à vista não passa pela revisão.** É gravada automaticamente na confirmação. Não há decisão a tomar sobre ela, e uma fatura tem dezenas.
6. **A categoria é editável, e a correção é permanente.** Uma coluna registra que aquela categoria veio do usuário; nenhum reprocessamento futuro a sobrescreve.
7. **A IA nunca bloqueia a importação.** Classificação roda em job de fila, depois da gravação. Ollama fora do ar atrasa o gráfico e não impede nada.
8. **O provedor de IA é configuração, não código.** Via `laravel/ai`, trocar Ollama por Anthropic ou OpenAI é mudar provider e modelo no `.env`.

## Extração

`ParsedInvoice` ganha um segundo array, `cashLines`, ao lado do `lines` existente. As linhas parceladas não mudam: o fluxo de revisão, a reconciliação e a detecção de duplicata continuam operando sobre `lines` exatamente como hoje.

### `ParsedCashLine`

| Campo | Tipo | Invariante de construtor |
|---|---|---|
| `description` | string | |
| `transactionDay` | int | 1–31 |
| `transactionMonth` | int | 1–12 |
| `amountCents` | int | ≥ 1 |

As invariantes ficam no construtor pelo mesmo motivo de `ParsedInvoiceLine`: o app não guarda o PDF, então uma linha malformada precisa falhar onde ainda dá para nomear o defeito.

O ano vem do vencimento da fatura. Uma linha de 28/12 numa fatura que vence em janeiro é do ano anterior.

### Por emissor

| Emissor | Como a linha à vista se distingue | O que fica de fora |
|---|---|---|
| Nubank | Mesmo padrão da parcelada, sem o sufixo `- Parcela N/M` | Valor com sinal `−` (U+2212): "Pagamento recebido", estornos |
| Renner | Rótulo explícito `Compra a Vista` | `PagamentoFaturaPix` e demais negativos |
| Mercado Pago | Linha `dd/mm  descrição  R$ valor` sem `Parcela` | `Pagamento da fatura de …` |
| Itaú | Layout de duas colunas; a coluna de compras traz `dd/mm  descrição  valor` sem `N/M` | Coluna esquerda (`PAGAMENTO`) e qualquer valor negativo |

Encargos que apareçam como linha positiva — IOF, juros, anuidade — são extraídos e caem em Outros.

## Modelo de dados

### `cash_purchases` (nova)

| Coluna | Tipo | Notas |
|---|---|---|
| `card_id` | FK → cards, cascade on delete | |
| `description` | string | Texto do estabelecimento como veio da fatura |
| `amount_cents` | unsigned integer | |
| `purchased_at` | date | Quando a compra aconteceu |
| `billed_at` | date | Vencimento da fatura que a cobrou — é por esta data que os gráficos somam |
| `category` | string, nullable | Enum `ExpenseCategory`; nulo significa "ainda não classificada" |
| `category_is_manual` | boolean, default false | |

Índice único em (`card_id`, `purchased_at`, `amount_cents`, `description`), com gravação por `insertOrIgnore`.

**Por que `billed_at` existe.** O regime é de caixa, e sem esta coluna ele quebraria na virada do mês: uma compra à vista de 28/12 é cobrada na fatura de janeiro, enquanto a parcela irmã dela conta em janeiro pelo vencimento. Atribuir a à vista pela data da compra erraria dezembro e janeiro ao mesmo tempo. Guardando o vencimento da fatura de origem, os dois tipos somam pela mesma pergunta: o que a fatura cobrou naquele mês. A `purchased_at` permanece para a lista mostrar quando a compra foi feita.

**Por que o índice único.** Reimportar a mesma fatura duplicaria os gastos e o gráfico passaria a mentir sem avisar. O único no banco é a garantia que código esquecido não desfaz.

Custo conhecido e aceito: dois gastos idênticos, no mesmo dia, no mesmo estabelecimento e no mesmo valor viram um só. O erro é de centavos e para menos.

### `purchases` (alteração)

Ganha `category` (string, nullable) e `category_is_manual` (boolean, default false), com o mesmo significado.

## Categorização

### `ExpenseCategory`

Doze casos, chaves em TitleCase como os enums existentes.

| Caso | Cobre |
|---|---|
| Mercado | supermercado, hortifruti, açougue, padaria |
| Restaurantes e lanches | restaurante, bar, delivery |
| Combustível | posto, abastecimento |
| Transporte | app de corrida, estacionamento, pedágio, ônibus |
| Saúde | farmácia, consulta, exame, plano |
| Casa | móveis, utilidades, material de construção, contas da casa |
| Eletrônicos | celular, computador, periférico |
| Vestuário | roupa, calçado, acessório |
| Assinaturas | streaming, nuvem, software recorrente |
| Lazer | cinema, show, jogo, academia |
| Viagem | passagem, hotel, hospedagem |
| Outros | o resto, e o destino de tudo que o modelo não souber classificar |

### `CategorizeExpenses`

Agente em `app/Ai/Agents/`, implementando `Agent`, `HasStructuredOutput` e `HasProviderOptions`.

As instruções descrevem a tarefa em português: classificar descrições de fatura de cartão brasileiro, escolhendo Outros quando a descrição não permitir decidir. O schema é um array de objetos `{id: integer, category: string}` com `category` restrita ao enum — o runtime rejeita rótulo fora da lista, e não há normalização de string a fazer depois. `providerOptions` desliga o modo *thinking* no Ollama, que numa tarefa de classificação só custaria tokens.

### `CategorizeExpensesJob`

`ShouldQueue`, despachado depois da transação da confirmação, recebendo os ids gravados dos dois tipos.

- Envia as descrições em lotes, não uma por requisição.
- Grava a categoria com `category_is_manual` falso.
- **Nunca toca em linha com `category_is_manual` verdadeiro.**
- Três tentativas. Esgotadas, a categoria permanece nula e a linha aparece no balde "Sem categoria" da tela.

### Configuração

`config/ai.php` publicado do pacote. `.env` aponta provider `ollama` e modelo `gemma4:e4b`. Trocar de provedor é mudar essas duas chaves — nenhum chamador muda, porque a classe do agente é a costura.

## Tela

Rota `/gastos`, `SpendingController@index`, página `spending/index.tsx`, item novo no menu. O dashboard não é tocado: ele responde sobre compromisso futuro, e esta tela responde sobre gasto passado.

Uma action `BuildSpendingBreakdown`, no formato das actions existentes, entrega:

1. **Barras horizontais do mês selecionado** — uma por categoria, ordenadas do maior para o menor, com nome e valor impressos na própria linha.
2. **Barras empilhadas dos últimos 12 meses** — uma barra por mês, segmentada por categoria.
3. **Lista do mês** — à vista e parcelas juntas, com a categoria editável em linha.

Seletor de mês no topo, valendo para as barras por categoria e a lista. Gráficos em `recharts`, seguindo `projection-chart.tsx`.

O balde "Sem categoria" é visível e traz botão que enfileira o job para o que ainda não foi classificado.

`PATCH` de categoria marca `category_is_manual` e é autorizado pelo dono do cartão.

Fora de escopo, deliberadamente: comparativo mês contra mês por categoria, e meta ou orçamento por categoria. A segunda encosta no módulo de Planejamento que já existe e é assunto de outro spec.

## Testes

- **Por parser, contra as fixtures existentes:** quantas linhas à vista saem, e que pagamento, estorno e valor negativo não entram.
- **Importação:** as compras à vista são gravadas na confirmação; reimportar a mesma fatura não duplica; o job é despachado.
- **Job:** com `CategorizeExpenses::fake()`, classifica o lote, respeita `category_is_manual`, e deixa a categoria nula quando o provedor falha.
- **Tela:** os totais por categoria batem no regime de caixa; usuário não edita categoria de cartão alheio.

Nenhum teste depende de Ollama rodando.

**Limite honesto:** as fixtures têm nomes de estabelecimento embaralhados. Elas provam a extração e não provam nada sobre a qualidade da classificação. Essa só se afere rodando o modelo real contra uma fatura real, manualmente, e é condição para dar o trabalho por pronto.

## Riscos assumidos

| Risco | Situação |
|---|---|
| Um modelo 8B classifica errado | Mitigado pela edição manual; sem ela o erro seria permanente |
| `laravel/ai` é 0.x e vai quebrar em upgrade | Contido no agente e na config; é first-party e segue o ciclo do Laravel |
| Trocar para Anthropic ou OpenAI envia a descrição das compras a um terceiro | Com Ollama nada sai da máquina. A troca é decisão do usuário, e não é só técnica |
| Dois gastos idênticos no mesmo dia viram um | Consequência aceita do índice único |
| Fatura real com seção que a fixture não cobre | Verificação manual com os PDFs reais; Itaú e Mercado Pago exigem senha |

## Dependência nova

`composer require laravel/ai` — v0.10.1, PHP ^8.3, Laravel ^12|^13. Aprovada.
