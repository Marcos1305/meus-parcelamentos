# Meus Parcelamentos — Núcleo (Spec 1)

**Data:** 2026-07-24
**Origem do design:** Stitch `projects/6109854161931976959` — "Multi-Card Installment Dashboard"
**Escopo:** núcleo do produto. A importação de fatura via OCR fica no Spec 2.

## Objetivo

Dar ao usuário uma visão única e confiável de tudo que ele deve nos cartões de crédito: quanto sai por mês, em qual cartão, até quando. O app rastreia compromisso futuro, não inadimplência.

## Decisões que moldam o resto

1. **Multi-usuário, sem billing.** Cada usuário vê apenas os próprios cartões. Registro, login, 2FA e passkeys já vêm prontos do starter kit e não são tocados. O "Plano Premium" das telas do Stitch não é implementado.
2. **Parcelas são materializadas.** Cada compra grava N linhas em `installments` na criação. Alternativa descartada: calcular sob demanda — inviabiliza paginação e filtro no servidor no Histórico, e deixa o Spec 2 sem âncora para reconciliar linhas de fatura importada.
3. **Status é derivado da data, nunca persistido.** Sem `paid_at`, sem clique de confirmação.
4. **Lançamento manual é a única entrada de dados.** Sem OCR neste spec.
5. **Sem categorias, sem gastos fixos recorrentes.** Ambos ficam fora.

## Modelo de dados

Três tabelas novas. `users` já existe e não muda.

### `cards`

| Coluna | Tipo | Notas |
|---|---|---|
| `user_id` | FK → users, cascade on delete | |
| `name` | string | "Nubank" |
| `brand` | string (enum `CardBrand`) | visa, mastercard, elo, amex, hipercard, outro |
| `label` | string, nullable | "Platinum", "Black Edition" — texto livre |
| `last_four_digits` | string(4) | |
| `credit_limit_cents` | unsigned integer | |
| `closing_day` | unsigned tinyint | 1–31 |
| `due_day` | unsigned tinyint | 1–31 |

### `purchases`

| Coluna | Tipo | Notas |
|---|---|---|
| `card_id` | FK → cards, cascade on delete | |
| `description` | string | "MacBook Pro M2" |
| `merchant` | string, nullable | "Apple Store - Shopping Morumbi" |
| `total_amount_cents` | unsigned integer | > 0 |
| `installments_count` | unsigned tinyint | 1–48 |
| `purchased_at` | date | |

### `installments`

| Coluna | Tipo | Notas |
|---|---|---|
| `purchase_id` | FK → purchases, cascade on delete | |
| `number` | unsigned tinyint | 1..N |
| `amount_cents` | unsigned integer | |
| `due_date` | date | |

Índice composto em `(due_date, purchase_id)` — toda agregação do dashboard filtra por `due_date`. Índice único em `(purchase_id, number)`, que torna impossível uma compra ter duas parcelas com o mesmo número.

**Escopo por usuário** é feito por join: `installments → purchases → cards.user_id`. Não há `user_id` denormalizado — o volume é de centenas a poucos milhares de linhas por usuário, e a redundância só criaria oportunidade de inconsistência.

**Dinheiro é sempre `integer` de centavos**, com cast `'integer'` do Eloquent. Nenhum cast customizado: a formatação em BRL acontece na borda React, via `Intl.NumberFormat`. Não existe `float` nem `decimal` em lugar nenhum do domínio.

## Regras de domínio

### Data de vencimento da primeira parcela

Dada uma compra em `P` num cartão com fechamento `C` e vencimento `D`:

1. **Fatura de fechamento.** Se `dia(P) <= C`, a compra entra na fatura que fecha no mês de `P`. Caso contrário, na fatura do mês seguinte.
2. **Vencimento.** Se `D > C`, vence no dia `D` do mesmo mês do fechamento. Se `D <= C`, vence no dia `D` do mês *seguinte* ao fechamento.
3. **Corte de dia.** Quando `C` ou `D` excedem o número de dias do mês alvo, usa-se o último dia daquele mês.

**Exemplos normativos:**

| Compra | C | D | 1º vencimento | Por quê |
|---|---|---|---|---|
| 02/03 | 3 | 10 | 10/03 | entra na fatura de março, D > C |
| 05/03 | 3 | 10 | 10/04 | passou do fechamento de março |
| 02/03 | 28 | 5 | 05/04 | fecha em março, mas D <= C empurra para abril |
| 15/01 | 10 | 31 | 28/02 | fatura de fevereiro; dia 31 cortado para 28 |

### Parcelas seguintes

A parcela `k` vence no dia `D` do mês `mês(primeiro vencimento) + (k-1)`, com o mesmo corte de dia. **O corte nunca se propaga:** com `D = 31` e primeira parcela em janeiro, a sequência é 31/01, 28/02, 31/03 — não 28/03. Cada data é calculada a partir de `D` original, nunca a partir da data anterior.

### Rateio do valor

`base = intdiv(total, n)`, `resto = total % n`. A primeira parcela recebe `base + resto`; as demais recebem `base`.

- R$ 1.000,00 em 3x → `[33334, 33333, 33333]`
- R$ 100,00 em 1x → `[10000]`
- R$ 0,01 em 3x → `[1, 0, 0]` (degenerado, mas válido; a soma bate)

**Invariante:** a soma das parcelas é sempre exatamente igual ao total da compra.

### Status da parcela

Função pura de `due_date` contra a data de hoje, avaliada na leitura:

- `paid` — `due_date < hoje`
- `upcoming` — `due_date >= hoje` e `due_date` no mês corrente
- `pending` — `due_date` em mês futuro

A partição é completa e sem sobreposição. Modelada como enum `InstallmentStatus`.

**Cuidado com o vocabulário:** "parcela **em aberto**" significa `status != paid`, ou seja `upcoming` **ou** `pending`. O selo `pending` da UI é outra coisa — só as de meses futuros. As métricas abaixo usam "em aberto" no sentido estrito definido aqui.

### Métricas agregadas

Toda métrica exibida em qualquer tela está definida aqui. Nenhuma é calculada de duas formas diferentes.

| Métrica | Definição |
|---|---|
| Total devido / Total em parcelas | Soma de `amount_cents` das parcelas em aberto do usuário |
| Parcelas em aberto (contagem) | Número de parcelas em aberto do usuário |
| Próximo vencimento (data) | Menor `due_date >= hoje` entre as parcelas do usuário |
| Próximo vencimento (valor) | Soma das parcelas cujo `due_date` é igual a essa data |
| Pago este mês | Soma das parcelas com `due_date` no mês corrente e `due_date < hoje` |
| Projeção do mês M | Soma das parcelas com `due_date` dentro de M |
| Comprometido do cartão | Soma das parcelas em aberto daquele cartão |
| Comprometido total | Soma do comprometido de todos os cartões do usuário |
| Disponível | `credit_limit_cents` menos o comprometido, do cartão ou do total |

**Os cards de topo do Dashboard e do Histórico são globais** — não respondem aos filtros da tabela. Filtro é da tabela; os totais do topo respondem sempre pela carteira inteira. Misturar os dois faria o número mudar de significado conforme o filtro, que é exatamente o tipo de ambiguidade que este app existe para eliminar.

### Comprometido por cartão

`comprometido = soma de amount_cents das parcelas do cartão com status != paid`.
`disponível = credit_limit_cents - comprometido`. Percentual = `comprometido / credit_limit_cents`.

**Aproximação declarada:** isto não é o "limite utilizado" que o banco mostra — o banco considera compras à vista, anuidade, estornos e a fatura em aberto. É o melhor proxy possível com os dados do app. A UI rotula como **"comprometido"**, nunca "utilizado", para não prometer o que não entrega.

### Dica de finanças

Uma única regra, a que a tela do Stitch mostra: contar as parcelas em que `number == purchase.installments_count` e cujo `due_date` cai no mês corrente; somar seus valores. O painel exibe "N parcelas terminam este mês, liberando R$ X a partir de [mês seguinte]". Com `N = 0`, o painel não é renderizado.

Nenhuma outra dica é inventada.

## Configuração da aplicação

`APP_LOCALE` passa para `pt_BR`, `APP_FAKER_LOCALE` para `pt_BR`, e o timezone da app para `America/Sao_Paulo`. Hoje estão em `en` / `en_US` / `UTC`. O timezone é o que mais importa: sem ele, "hoje" no cálculo de status fica errado por até três horas na virada do dia.

**Tradução de mensagens fica fora do escopo.** O Laravel não traz arquivos `lang/pt_BR`, e publicá-los mais traduzi-los é trabalho próprio. Com `APP_FALLBACK_LOCALE=en`, as mensagens de validação continuam em inglês; os rótulos das telas são escritos em português direto no React. Fica registrado como dívida consciente.

## Camada HTTP

Rotas em `routes/parcelamentos.php`, incluído por `routes/web.php`, todas sob `['auth', 'verified']`.

| Método | URI | Nome | Controller |
|---|---|---|---|
| GET | `dashboard` | `dashboard` | `DashboardController@index` |
| GET | `cartoes` | `cards.index` | `CardController@index` |
| GET | `cartoes/novo` | `cards.create` | `CardController@create` |
| POST | `cartoes` | `cards.store` | `CardController@store` |
| GET | `cartoes/{card}/editar` | `cards.edit` | `CardController@edit` |
| PATCH | `cartoes/{card}` | `cards.update` | `CardController@update` |
| DELETE | `cartoes/{card}` | `cards.destroy` | `CardController@destroy` |
| GET | `lancamentos/novo` | `purchases.create` | `PurchaseController@create` |
| POST | `lancamentos` | `purchases.store` | `PurchaseController@store` |
| DELETE | `lancamentos/{purchase}` | `purchases.destroy` | `PurchaseController@destroy` |
| GET | `historico` | `history.index` | `HistoryController@index` |
| GET | `historico/exportar` | `history.export` | `ExportHistoryController` |

A rota `dashboard` existente em `routes/web.php` (hoje um `Route::inertia`) é substituída pelo controller. Ela permanece com URI e nome em inglês, ao contrário das demais: o Fortify redireciona para lá após o login, e `app-sidebar.tsx` já a importa via Wayfinder. Renomear traria retrabalho no starter sem ganho.

`purchases.create` exige ao menos um cartão. Sem cartões, redireciona para `cards.create` com um toast explicando o porquê.

Controllers são finos: validam via Form Request, delegam para Actions, devolvem `Inertia::render` ou `to_route` com `Inertia::flash('toast', ...)` — o mesmo padrão de `Settings\ProfileController`.

### Autorização

`CardPolicy` e `PurchasePolicy`, ambas checando propriedade via `user_id` (a de `Purchase` através de `card`). Registradas por convenção. Acesso a recurso alheio responde **403**. Toda rota com `{card}` ou `{purchase}` chama `$this->authorize(...)`.

### Form Requests

`StoreCardRequest` / `UpdateCardRequest`:
- `name` obrigatório, string, máx 60
- `brand` obrigatório, `Rule::enum(CardBrand::class)`
- `label` opcional, string, máx 40
- `last_four_digits` obrigatório, `digits:4`
- `credit_limit_cents` obrigatório, inteiro, mínimo 1
- `closing_day` obrigatório, inteiro, entre 1 e 31
- `due_day` obrigatório, inteiro, entre 1 e 31

`StorePurchaseRequest`:
- `card_id` obrigatório, `exists` **restrito aos cartões do usuário autenticado**
- `description` obrigatório, string, máx 120
- `merchant` opcional, string, máx 120
- `total_amount_cents` obrigatório, inteiro, mínimo 1
- `installments_count` obrigatório, inteiro, entre 1 e 48
- `purchased_at` obrigatório, data, `before_or_equal:today`

Compra à vista é `installments_count = 1`. Não há caminho especial no código.

### Tratamento de erros

O Spec 1 não conversa com nenhum sistema externo, então não há fronteira que justifique `try/catch`. Falhas se resolvem pelos mecanismos do framework: validação volta ao formulário via Inertia; propriedade violada dá 403 pela Policy; ID inexistente dá 404 pelo route model binding. Estados vazios são parte do design, não exceção.

## Actions

Em `app/Actions/` (pasta já existente, hoje com `Actions/Fortify`). Nenhuma pasta base nova é criada.

| Classe | Assinatura | Responsabilidade |
|---|---|---|
| `SplitIntoInstallments` | `(int $totalCents, int $count): array<int, int>` | Rateio. Pura, sem banco. |
| `CalculateFirstDueDate` | `(CarbonImmutable $purchasedAt, int $closingDay, int $dueDay): CarbonImmutable` | Regra de fechamento/vencimento. Pura, sem banco. |
| `GenerateInstallments` | `(Purchase $purchase): void` | Orquestra as duas acima e grava as N linhas. Apaga as existentes antes, para ser idempotente. |
| `BuildDashboardSummary` | `(User $user): array` | Contagem de cartões, parcelas em aberto, total devido, próximo vencimento. |
| `BuildMonthlyProjection` | `(User $user, int $months = 6): array` | Total por mês, do mês corrente em diante. |
| `BuildCardsOverview` | `(User $user): array` | Por cartão: comprometido, disponível, percentual, próximo vencimento e o parcelamento em destaque. Alimenta a tela Meus Cartões. |
| `BuildFinanceTip` | `(User $user): ?array` | A regra única descrita acima. |

As três de leitura devolvem arrays tipados por PHPDoc com array shape, consumidos direto pelo Inertia.

`GenerateInstallments` é chamada no `store` de `Purchase`, dentro de uma transação com a criação da compra.

### Comando de rebuild

`php artisan installments:rebuild [--user=]` regera todas as parcelas a partir das compras, chamando `GenerateInstallments`. Existe para o dia em que a regra de vencimento mudar, ou para corrigir dados após ajuste no cartão. É a válvula de escape que torna a materialização segura.

**Nota:** alterar `closing_day` ou `due_day` de um cartão **não** regera as parcelas existentes automaticamente. A tela de edição avisa disso, e o rebuild é a ação explícita. Regerar em silêncio mudaria datas de parcelas que o usuário já pagou.

## Frontend

Páginas Inertia em `resources/js/pages/`, seguindo o padrão kebab/minúsculo do starter:

- `dashboard.tsx` (substitui o placeholder atual)
- `cards/index.tsx`, `cards/create.tsx`, `cards/edit.tsx`
- `purchases/create.tsx`
- `history/index.tsx`

Rotas no cliente via Wayfinder (`import { cards } from '@/routes'`). Breadcrumbs pelo `Page.layout`, como o `dashboard.tsx` atual já faz. Feedback por toast via `Inertia::flash`.

Tipos em `resources/js/types/parcelamentos.ts`, reexportado por `types/index.ts` — mesmo padrão de `auth.ts`, `navigation.ts`, `ui.ts`.

Formatação de moeda e data em `resources/js/lib/currency.ts` (`formatBRL(cents: number): string`) — `lib/` já existe.

### Navegação

`AppSidebar` ganha Dashboard, Meus Cartões e Histórico em `mainNavItems`. Os itens de rodapé e o `NavUser` (que dá acesso a perfil e segurança) permanecem. O sidebar colapsável e o sheet mobile do starter atendem o requisito de "colapsar em ícones" do design system — não é construída uma bottom nav separada.

### Conteúdo das telas

**Dashboard** — três cards de topo (total de cartões; parcelas em aberto, com a data do próximo vencimento; valor total devido), gráfico de projeção de 6 meses, e uma lista "Resumo Mensal" dos mesmos 6 meses com selo Atual / Próximo / Pendente. Botões: Novo Lançamento e Exportar Relatório. O banner de sincronização OCR **não** é renderizado neste spec.

O gráfico tem série única (parcelados). A legenda "Gastos Fixos" das telas do Stitch não se aplica, já que gastos fixos recorrentes estão fora do escopo.

**Meus Cartões** — faixa de topo com comprometido total, disponível e percentual; painel "Próximos Vencimentos" listando, por cartão, a próxima data de vencimento e a soma das parcelas daquela data; e uma grade de cartões. Cada cartão mostra nome, label, bandeira, últimos 4 dígitos, e o **parcelamento em destaque**: o parcelamento ativo com maior valor restante (empate resolvido pela compra mais recente), com a barra pago/restante e o número da parcela atual. "Detalhes →" leva ao Histórico já filtrado por aquele cartão — não existe tela de detalhe de cartão.

**Adicionar / Editar Cartão** — mesmo formulário nas duas rotas. O card de importação via OCR ao pé da tela não é renderizado neste spec.

**Novo Lançamento** — *não existe tela correspondente no Stitch e é desenhada do zero.* É a única entrada de dados do Spec 1. Campos: cartão (select), descrição, estabelecimento, valor total, número de parcelas, data da compra. Abaixo do formulário, um **preview ao vivo** das parcelas que serão geradas — número, valor e vencimento de cada uma — calculado no cliente pelas mesmas regras, para o usuário conferir antes de salvar. O servidor recalcula e é a autoridade; o preview é conveniência.

**Histórico** — três cards de topo (total em parcelas, pago este mês, próximo vencimento com data), filtros de mês/status/cartão, e uma tabela **de uma linha por parcela**: descrição + estabelecimento, valor total da compra, cartão, `parcela/total`, vencimento, status, ações. Ordenada por `due_date` ascendente. Filtro padrão: mês corrente.

A ação disponível na linha é **apagar a compra inteira**, com diálogo de confirmação que informa quantas parcelas serão removidas. Apagar uma parcela isolada não é oferecido — parcelas são derivadas da compra.

Paginação no servidor: `->paginate(20)->withQueryString()`. Abaixo da tabela, o mesmo gráfico de projeção e o painel de dica de finanças.

### Estados vazios

Sem cartões, o Dashboard e a tela de Cartões trocam a grade de zeros por um CTA "Cadastre seu primeiro cartão". Com cartões mas sem compras, o Histórico mostra um CTA para Novo Lançamento. O gráfico de projeção não é renderizado quando não há parcelas futuras.

## Exportação CSV

`ExportHistoryController` (invokable) devolve `response()->streamDownload()` percorrendo a query com `cursor()`. Respeita os filtros ativos na query string e ignora a paginação.

Colunas: `cartao, descricao, estabelecimento, valor_total_compra, parcela, total_parcelas, valor_parcela, vencimento, status`.

Separador `;` e BOM UTF-8 no início do arquivo. Sem os dois, o Excel em português abre o arquivo com acentos corrompidos e tudo numa coluna só.

## Design visual

Paleta do `design.md` do Stitch: fundo `#F8FAFC`, primary navy `#1A2B4C`, acento dourado `#F59E0B` para vencimentos próximos e ações de destaque, verde `#2E7D32` para parcelas pagas. Tipografia Inter, com números tabulares nas colunas de valor.

Os tokens são aplicados em `resources/css/app.css`, sobre as variáveis CSS que o shadcn já usa — sem substituir o sistema de temas nem quebrar o modo escuro que o starter oferece.

Chips de status: verde para Pago, dourado para Próximo, neutro para Pendente. Cards com `rounded-lg` e sombra suave; botões e inputs com `rounded-md`.

## Dependências

**Nova, aprovada:** `recharts` mais o componente `chart` do shadcn/ui, para o gráfico de projeção. Os tokens `--color-chart-*` já existem no `app.css` e passam a ser consumidos.

Nenhuma outra dependência é adicionada. O CSV é gerado com recursos nativos do Laravel.

## Testes

### Unit — sem banco

`SplitIntoInstallments`
- A soma das parcelas é igual ao total, para uma bateria de valores e contagens
- 1x devolve o total intacto
- O resto vai para a primeira parcela
- R$ 0,01 em 3x produz `[1, 0, 0]`

`CalculateFirstDueDate`
- Os quatro exemplos normativos da tabela acima
- Virada de ano: compra em dezembro após o fechamento vence em janeiro do ano seguinte
- Dia 31 caindo em fevereiro de ano bissexto e não bissexto

Sequência de parcelas
- Com `D = 31` e início em janeiro, as datas são 31/01, 28/02, 31/03 — o corte não se propaga

### Feature — por rota

Autorização
- Usuário A recebe 403 em `cards.edit`, `cards.update`, `cards.destroy` de cartão de B
- Usuário A recebe 403 em `purchases.destroy` de compra de B
- `StorePurchaseRequest` rejeita `card_id` de cartão de outro usuário

Compras
- Criar compra de 12x grava 12 parcelas com valores e datas corretos
- A soma das parcelas gravadas é igual ao total da compra
- Apagar a compra apaga suas parcelas
- Apagar o cartão apaga compras e parcelas

Dashboard
- Agrega apenas parcelas do usuário autenticado
- A projeção cobre 6 meses a partir do corrente, com os totais certos
- Sem cartões, a resposta traz o estado vazio

Histórico
- Filtra por status e por cartão
- Pagina em 20 e preserva os filtros na query string
- Ordena por vencimento ascendente

Exportação
- O CSV traz exatamente as linhas do filtro ativo
- O arquivo começa com BOM e usa `;`

Comando
- `installments:rebuild` regenera parcelas idênticas quando nada mudou
- Após alterar `due_day` do cartão, o rebuild atualiza as datas

Todo teste que toca status congela o relógio com `travelTo`. Sem isso a suíte quebra sozinha na virada do mês.

### Factories e seeder

`CardFactory` e `PurchaseFactory`. A `PurchaseFactory` ganha o state `parcelada(int $n)` que gera as parcelas **chamando a Action real**, não montando as linhas à mão — uma fixture que replica a lógica faria o teste validar a si mesmo.

`DatabaseSeeder` cria um usuário e três cartões com compras parecidas com as das telas do Stitch, para desenvolvimento com dados plausíveis.

## Fora de escopo

- Importação de fatura e OCR (Spec 2)
- Categorias de gasto
- Gastos fixos recorrentes
- Planos, limites e cobrança
- Exportação em PDF
- Marcação manual de parcela paga
- Tela de detalhe de cartão
- Dicas de finanças além da regra única especificada
- **Edição de compra.** Só criar e apagar. Editar valor ou número de parcelas exigiria regerar parcelas já vencidas, o que muda o passado. Corrigir um lançamento é apagar e recriar.
- Arquivos de tradução `pt_BR`

## Riscos conhecidos

**A regra de fechamento não cobre todos os bancos.** Alguns emissores usam "melhor dia de compra" ou deslocam o vencimento quando cai em fim de semana ou feriado. A regra especificada é a mais comum no Brasil, e o comando `installments:rebuild` existe para absorver a correção quando um caso real divergir.

**"Comprometido" vai divergir do app do banco.** É esperado e está declarado na UI. O Spec 2, ao importar faturas reais, dá o caminho para aproximar os números.
