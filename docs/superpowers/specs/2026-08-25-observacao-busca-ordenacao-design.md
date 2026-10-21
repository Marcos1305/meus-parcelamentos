# Observação, Busca e Ordenação (Spec 5)

**Data:** 2026-08-25
**Escopo:** um campo de anotação livre no parcelamento, busca por texto no Histórico e em Gastos, e ordenação por coluna nas duas tabelas.

## Objetivo

As duas listas do app já respondem "o que vence este mês" e "para onde foi o dinheiro" — mas só na fatia que os filtros de mês, status, cartão e categoria recortam. Quando o usuário procura *uma* compra específica ("aquele sofá"), ou quer ver o mês do maior para o menor vencimento, não tem caminho: precisa varrer página por página. E não há onde registrar o que a descrição de 120 caracteres não cabe — quem dividiu a compra, até quando vale a garantia, por que aquele lançamento existe.

## Decisões que moldam o resto

1. **A observação é um campo novo, não um `description` maior.** `description` continua sendo o rótulo curto que a tabela exibe e a fatura preenche. A anotação é outra coisa: longa, opcional, escrita pelo usuário. Misturar as duas num campo só faria a coluna "Item" da tabela exibir parágrafos.
2. **A observação mora na compra, não na parcela.** Mesma regra já adotada para categoria: "esse sofá foi presente da Ana" vale para as doze parcelas, não para a de março.
3. **A observação é editável inline no Histórico.** Não existe tela de edição de compra, e a maior parte das compras entra por importação de fatura, sem nunca passar por formulário. Um campo editável só na criação nasceria inútil para o acervo existente.
4. **Busca e ordenação são estado de URL, resolvidos no servidor.** O Histórico é paginado em 20 linhas: ordenar no cliente ordenaria apenas a página visível e apresentaria como "o mês inteiro" algo que não é. Gastos segue a mesma regra por consistência, e porque `BuildSpendingBreakdown` já carrega as coleções na memória — filtrar e ordenar ali é um `usort`.
5. **Parâmetro inválido cai no default em silêncio.** Mesma regra que `mes` e `categorias` já seguem: veio da barra de endereços, não derruba a tela nem vira erro de validação.
6. **Em Gastos, a busca filtra só a tabela.** Os dois gráficos e o balde "sem categoria" continuam enxergando o mês inteiro. É a decisão oposta à do filtro de categoria — e é deliberada: o filtro de categoria é uma partição do gasto (as fatias somam o subconjunto e a reconciliação continua verdadeira), enquanto a busca é uma lente sobre a lista. Um gráfico que muda a cada tecla digitada não comunica nada.
7. **Status não é coluna ordenável.** `InstallmentStatus` é derivado de `due_date` comparada com hoje. Ordenar por status seria ordenar por vencimento com outro nome, e ofereceria ao usuário dois controles que fazem a mesma coisa.

## Modelo de dados

### `purchases` — coluna nova

| Coluna | Tipo | Notas |
|---|---|---|
| `notes` | text, nullable | Anotação livre do usuário. `null` quando não há nota; string vazia é normalizada para `null` na escrita. |

`Purchase::MAX_NOTES_LENGTH = 1000`, ao lado de `MAX_DESCRIPTION_LENGTH` e `MAX_INSTALLMENTS_COUNT` — as constantes de domínio moram no modelo para que todo caminho de escrita valide o mesmo limite.

`notes` entra no `#[Fillable]` e no bloco `@property` do modelo. Não há cast: é string ou `null`.

Nenhuma outra tabela muda. Gastos não ganha observação — a compra à vista existe para responder "para onde foi o dinheiro" e nada mais.

## Escrita da observação

### Rota

`PATCH lancamentos/{purchase}/observacao` → `PurchaseController@updateNotes`, com `->can('update', 'purchase')`. `PurchasePolicy::update` já existe e compara `card.user_id`.

`UpdatePurchaseNotesRequest`:

| Campo | Regras |
|---|---|
| `notes` | `nullable`, `string`, `max:1000` |

O controller grava `null` quando o texto chega vazio ou só com espaços, para que "sem nota" tenha uma representação só no banco — caso contrário a mesma ausência apareceria ora como `null`, ora como `''`, e o indicador visual da linha teria que conhecer as duas.

Retorna `back()` — a tela de origem é o Histórico, com `preserveScroll`.

### Criação

`lancamentos/novo` ganha a textarea de observação, e `StorePurchaseRequest` a mesma regra `nullable|string|max:1000`. O campo passa a existir nos dois caminhos de escrita manual; a importação de fatura continua gravando `notes` nulo.

## Busca

### Histórico

`BuildHistoryQuery::filters()` passa a devolver `q` (string, default `''`), somando-se a `month`, `status` e `card`.

Quando `q` não está vazio, `handle()` acrescenta:

```
whereHas('purchase', fn ($purchases) => $purchases
    ->where('description', 'like', "%{$q}%")
    ->orWhere('merchant', 'like', "%{$q}%")
    ->orWhere('notes', 'like', "%{$q}%"))
```

Os três `orWhere` ficam agrupados dentro do `whereHas`, que já é um `exists` isolado — não vazam para os demais filtros.

Como `ExportHistoryController` reusa `BuildHistoryQuery::filters()` e `handle()`, o CSV passa a respeitar a busca sem alteração própria. O CSV também ganha a coluna `observacao`, depois de `estabelecimento`.

Na tela: um `Input` de busca ao lado dos três `Select` existentes, com debounce, disparando `router.get` com os demais filtros preservados e sem `page` — uma busca nova sempre começa na primeira página, senão o usuário cai numa página vazia.

### Gastos

`SpendingController` lê `?busca=` (nomenclatura em português, como `mes` e `categorias` da mesma tela) e repassa a `BuildSpendingBreakdown::handle()` como argumento nomeado `search`.

O termo é aplicado **exclusivamente** em `entries()`, comparando com `description` de forma insensível a maiúsculas. `categories()`, `series()`, `uncategorizedCount()`, `uncategorizedTotalCents()` e `availableCategories()` continuam recebendo as coleções não filtradas pela busca — o filtro de categoria segue sendo o único que as atravessa. O docblock de `handle()` hoje documenta a garantia de reconciliação sob filtro de categoria; ganha o parágrafo que registra por que a busca é a exceção.

O payload devolve `search` junto de `active_categories`, para a tela repopular o input após o reload.

`spendingQuery()` no front passa a carregar o termo junto de `mes` e `categorias`, com a mesma regra de omissão quando vazio: navegar de mês preserva a busca, e buscar preserva mês e categorias.

Estado vazio: quando a busca não casa nada, a mensagem do `EmptyState` diz que nenhum gasto casou com o termo — distinto de "nenhum gasto neste mês" e de "nenhum gasto nas categorias selecionadas", que já existem.

### Limitação aceita: acentos — e, no Histórico, maiúsculas em letra acentuada

O banco é SQLite, e as duas telas casam o termo por caminhos diferentes. A limitação, por isso, tem tamanhos diferentes em cada uma.

Em **Gastos** o casamento acontece em PHP (`mb_strtolower` + `str_contains`): insensível a maiúsculas em qualquer letra, acentuada ou não — `AÇAÍ` encontra "Açaí" —, mas não insensível a acento: `acai` não encontra "Açaí".

No **Histórico** o casamento é o `LIKE` do SQLite, que dobra caixa só em ASCII. `acai` não encontra "Açaí", como em Gastos — mas `AÇAÍ` também não, porque `Ç` e `Í` não são dobrados. Em letra acentuada, o Histórico perde também a insensibilidade a maiúsculas; a parte ASCII do termo continua dobrando normalmente.

Resolver exigiria uma coluna normalizada mantida na escrita, e o custo não se paga num app de usuário único que digita o que está vendo na tela. Degradar Gastos para igualar ao Histórico não traria ganho nenhum — só perda. Fica registrado como limitação conhecida, não como defeito, com teste em cada tela fixando o que de fato acontece, para que a divergência não precise ser redescoberta lendo o código.

O termo do usuário é escapado antes de entrar no `LIKE` (`%`, `_` e `\`, com cláusula `ESCAPE`). Sem isso, `%` e `_` valeriam como curinga no Histórico e como caractere literal em Gastos, e a mesma busca daria listas diferentes nas duas telas.

## Ordenação

### Componente compartilhado

`resources/js/components/sortable-header.tsx` exporta um `<th>` clicável:

| Prop | Tipo | Papel |
|---|---|---|
| `column` | `string` | Chave que vai para a URL |
| `label` | `string` | Texto do cabeçalho |
| `active` | `string` | Coluna ordenada no momento |
| `direction` | `'asc' \| 'desc'` | Direção atual |
| `href` | `(column: string) => string` | Monta o link do próximo estado; a tela é quem sabe a direção inicial de cada coluna |
| `align` | `'left' \| 'right'` (opcional) | Acompanha as colunas numéricas |

Renderiza um `Link` do Inertia dentro do `<th>`, com seta indicando a direção quando a coluna está ativa, `aria-sort` correspondente (`ascending` / `descending` / `none`) e rótulo acessível descrevendo o que o clique fará. Clicar na coluna ativa inverte a direção; clicar em outra coluna começa pelo default dela.

As duas telas montam suas tabelas em `<table>` cru hoje; o componente entrega só o `<th>`, sem impor estrutura ao resto.

### Histórico

`BuildHistoryQuery::filters()` ganha `sort` (default `due_date`) e `dir` (default `asc`). Valor fora da lista branca cai no default.

| `sort` | Ordena por | Direção inicial |
|---|---|---|
| `description` | `purchases.description` | asc |
| `total` | `purchases.total_amount_cents` | desc |
| `card` | `cards.name` | asc |
| `number` | `installments.number` | asc |
| `due_date` (default) | `installments.due_date` | asc |

As colunas de `purchases` e `cards` são ordenadas por subconsulta correlacionada (`orderBy(Purchase::select(...)->whereColumn(...))`), não por join: o `whereHas` do filtro de cartão e o `with` do carregamento já dependem da query não ter joins que dupliquem linhas.

`installments.id` permanece como desempate final em toda ordenação, para que a paginação seja estável — sem ele, duas parcelas com o mesmo vencimento podem trocar de página entre requisições.

O CSV usa a mesma ordenação da tela, pela mesma razão de já compartilhar os filtros.

### Gastos

`SpendingController` lê `?ordem=` e `?direcao=`, repassados a `handle()` e aplicados no `usort` de `entries()`.

| `ordem` | Ordena por | Direção inicial |
|---|---|---|
| `descricao` | `description` | asc |
| `cartao` | `card_name` | asc |
| `data` | `purchased_at` | desc |
| `valor` (default) | `amount_cents` | desc |
| `categoria` | rótulo da categoria; sem categoria por último | asc |

O default (`valor`, desc) é exatamente a ordenação fixa de hoje: a tela abre igual e nada muda para quem não clicar em nada.

`ordem` e `direcao` entram em `spendingQuery()` junto de `mes`, `categorias` e `busca`, e sobrevivem à troca de qualquer um dos outros.

### Comparação de texto: uma regra só, nas duas telas

Toda coluna de texto — `description` e nome do cartão no Histórico, `descricao`, `cartao` e o rótulo de `categoria` em Gastos — compara de forma **insensível a maiúsculas e sem ordenação natural**. Com `("Item 10", "Item 9", "Item 2")`, a ordem crescente é `Item 10 | Item 2 | Item 9` nas duas telas.

A ordenação natural (`Item 2 | Item 9 | Item 10`) é mais agradável de ler, e Gastos chegou a usá-la, via `strnatcasecmp`. Não sobreviveu: o Histórico é paginado no servidor, o SQLite não sabe ordenar naturalmente, e alinhar por cima exigiria trazer o mês inteiro para o PHP para ordenar — exatamente o que a paginação existe para evitar. Duas tabelas do mesmo app respondendo "ordene por nome" de formas diferentes é vivido como instabilidade, e o usuário não tem como saber qual tela usa qual regra. Então Gastos alinha por baixo: `strcasecmp` no `usort`, espelhando o `lower()` do SQL do Histórico.

## Testes

Feature, em Pest, sobre entrada e saída HTTP — sem espiar o interior das actions.

**Histórico** (`HistoryTest`)
- Busca casa `description`, `merchant` e `notes`, um caso por campo.
- Busca combina com os filtros de mês e cartão em vez de substituí-los.
- Busca sem resultado devolve lista vazia, não erro.
- `%` e `_` no termo casam como texto literal, não como curinga do `LIKE`.
- Letra acentuada não dobra caixa (`AÇAÍ` não encontra "Açaí"), enquanto a parte ASCII do termo dobra — o par do teste de Gastos que prova o contrário lá.
- Cada coluna ordenável devolve as linhas na ordem esperada, asc e desc.
- Colunas de texto ordenam sem ordenação natural (`Item 10` antes de `Item 2`).
- `sort` e `dir` inválidos caem em vencimento ascendente.

**Exportação** (`ExportHistoryTest`)
- CSV respeita a busca ativa.
- CSV traz a coluna `observacao` preenchida e vazia.

**Observação** (`PurchaseTest`)
- `PATCH` grava a nota e ela reaparece no Histórico.
- Texto vazio ou só espaços grava `null`.
- Acima de 1000 caracteres falha na validação.
- Compra de outro usuário devolve 403.
- Criação manual com observação persiste o campo.

**Gastos** (`SpendingTest`, `BuildSpendingBreakdownTest`)
- Busca estreita `entries` sem alterar `categories`, `months` nem os `uncategorized_*` — este é o caso central da decisão 6.
- Busca **sem nenhum resultado** num mês com gasto continua devolvendo `categories` e `uncategorized_*` populados: é o que autoriza a tela a manter os gráficos e o alerta na dobra do estado vazio.
- Busca e filtro de categoria convivem.
- Letra acentuada dobra caixa (`AÇAÍ` encontra "Açaí") e acento continua não dobrando (`acai` não encontra) — o par do teste do Histórico.
- Cada coluna ordenável devolve `entries` na ordem esperada, asc e desc.
- Colunas de texto ordenam sem ordenação natural, como o Histórico.
- `ordem` inválida mantém valor decrescente.
- Busca e ordenação sobrevivem à troca de mês.

## Fora de escopo

- Observação em compra à vista.
- Ordenação nas tabelas de Cartões e de Revisão de importação.
- Busca global entre meses em Gastos.
- Busca insensível a acentos.
- Tela de edição completa de compra.
