# Meus Parcelamentos — Planejamento Mensal (Spec 3)

**Data:** 2026-07-27
**Escopo:** contas fixas recorrentes, renda mensal e uma planilha anual que soma tudo com os parcelamentos.
**Depende de:** Spec 1 (núcleo) — `cards`, `purchases`, `installments`. Não depende do Spec 2.
**Referência visual:** tela "Gestão de Contas Fixas e Planejamento Mensal" do Stitch (projeto `6109854161931976959`, screen `ad6ef83e08584be3ba4b3d58acbcd24f`).

## Objetivo

Responder "quanto entra, quanto sai e quanto sobra em cada mês do ano", juntando o que o app já sabe (parcelamentos) com o que ele ainda não sabe (contas fixas e renda) — e deixar o usuário corrigir o valor de um cartão num mês para o que ele de fato vai pagar, quando a fatura chega com compras à vista além das parcelas.

## Decisões que moldam o resto

1. **Renda e saldo entram.** A tela não é só de gastos: tem ENTRADA, SAÍDA e SALDO. Sem isso ela responde "quanto vou pagar" mas não "quanto sobra", que é a pergunta que faz alguém abrir um planejamento anual.
2. **Renda e conta fixa são a mesma estrutura.** Ambas são um valor mensal recorrente com um padrão e um valor real quando conhecido. Diferem no sinal, não na forma. Uma tabela com `kind`, não duas quase idênticas.
3. **Cartão não é uma dessas linhas.** O valor de cartão nasce calculado dos parcelamentos; o de uma conta fixa nasce de um chute do usuário. São origens diferentes e por isso tabelas diferentes.
4. **A correção de cartão substitui, sem esconder o calculado.** O usuário digita o total da fatura. O valor calculado continua visível ao lado. Ver a decisão detalhada abaixo.
5. **Uma linha por cartão.** Cada fatura corrige a sua própria linha. Uma linha agregada obrigaria a somar quatro faturas de cabeça antes de digitar.
6. **Ano civil, com troca de ano.** Colunas de janeiro a dezembro, `/planejamento?ano=2026`.
7. **Tudo ancorado no vencimento.** A coluna de um mês contém o que vence naquele mês. Sem deslocamento por regime de caixa. Ver "Convenção de mês" abaixo.
8. **Cada célula salva sozinha.** Não existe botão "Salvar Alterações". Ver a decisão detalhada abaixo.

## Modelo de dados

Três tabelas novas. Todos os valores em centavos inteiros, como no resto do app.

### `budget_lines`

As linhas recorrentes que o usuário mantém.

| coluna | tipo | nota |
|---|---|---|
| `id` | id | |
| `user_id` | foreignId, cascade | |
| `kind` | string | `income` \| `expense` (enum `BudgetLineKind`) |
| `name` | string | "Salário", "Energia", "Financiamento casa" |
| `default_amount_cents` | unsignedInteger | o valor presumido dos meses não preenchidos |
| `position` | unsignedInteger | ordem na tabela |
| `archived_at` | timestamp, nullable | linha arquivada some da grade e preserva o histórico |

Índice em `user_id`.

`default_amount_cents` é sempre não-negativo. O sinal quem dá é o `kind`.

### `budget_line_amounts`

O valor real de uma linha num mês.

| coluna | tipo | nota |
|---|---|---|
| `id` | id | |
| `budget_line_id` | foreignId, cascade | |
| `month` | date | sempre o dia 1 do mês |
| `amount_cents` | unsignedInteger | |

Único em (`budget_line_id`, `month`).

### `card_month_amounts`

A correção de um cartão num mês.

| coluna | tipo | nota |
|---|---|---|
| `id` | id | |
| `card_id` | foreignId, cascade | |
| `month` | date | sempre o dia 1 do mês |
| `amount_cents` | unsignedInteger | |

Único em (`card_id`, `month`).

Ausência de linha significa "sem correção". Não existe correção que valha zero por omissão: zero é um valor legítimo (fatura zerada) e é gravado como zero.

## Como o valor de um mês é resolvido

| | sem registro no mês | com registro no mês |
|---|---|---|
| conta fixa / renda | `default_amount_cents` | `amount_cents` |
| cartão | soma das parcelas que vencem no mês | `amount_cents`, com o calculado ainda visível |

É essa regra que faz água e luz funcionarem. O padrão é a estimativa; conforme as contas chegam o usuário preenche o mês. Meses não preenchidos seguem na estimativa.

### Por que a correção de cartão mostra o calculado ao lado

Substituir puro é o que o usuário quer digitar — ele abre a fatura, vê o total e transcreve. O risco é a correção envelhecer: se depois disso uma fatura importada jogar parcelas novas naquele mês, o valor fixo continua mandando e a diferença some sem aviso.

Mostrar o calculado junto do corrigido custa uma linha de interface e transforma um erro silencioso num erro visível. Não há reconciliação automática nem alerta: a tela mostra os dois números e o usuário decide.

### Convenção de mês

A coluna de setembro contém o que vence em setembro — parcelas, contas fixas e a renda que o usuário lançar ali.

Isto foi decidido contra a convenção de caixa que o usuário mantinha na planilha dele ("a fatura de setembro é paga com o salário de agosto, logo entra na coluna de agosto"). Duas razões: todas as outras telas do app já ancoram no vencimento, e a importação de fatura existe justamente porque a data de vencimento impressa é a âncora confiável. Uma planilha com convenção própria discordaria de todas elas.

O usuário optou por adaptar a leitura. Nenhum rótulo secundário de "pago com a renda de agosto" será implementado.

## Cálculo

### `BuildAnnualPlan`

Recebe usuário e ano, devolve os 12 meses resolvidos: por cartão o calculado e a correção, por linha o valor efetivo, e os três totais.

O valor calculado de um cartão num mês é a soma de `installments.amount_cents` cujo `due_date` cai naquele mês, das compras daquele cartão.

**Todas as parcelas do mês contam, vencidas ou não.** É a diferença que importa em relação ao `BuildMonthlyProjection` do dashboard, que filtra por `due_date >= hoje`. Aquele filtro está certo para a pergunta que o dashboard faz ("quanto ainda vou pagar"), e errado para uma planilha anual: se hoje é julho, janeiro a junho apareceriam zerados e julho apareceria pela metade.

`BuildMonthlyProjection` **não será alterado**. As duas ações respondem perguntas diferentes.

Consequência aceita: no mês corrente, dashboard e planilha mostram números diferentes para o mesmo cartão. O dashboard diz "falta pagar R$ 800 em julho"; a planilha diz "julho custou R$ 2.100". Ambos corretos.

### Totais

- **ENTRADA** — soma das linhas `income` já resolvidas.
- **SAÍDA** — soma das linhas `expense` resolvidas, mais o valor efetivo de cada cartão.
- **SALDO** — ENTRADA menos SAÍDA. Negativo é exibido como negativo.

Meses futuros usam os padrões, então o saldo de dezembro é uma projeção. É essa a utilidade da tela.

## Tela

Rota `GET /planejamento?ano=2026`, nome `plan.index`, item novo na sidebar. Ano ausente ou inválido cai no ano corrente.

Grade com a primeira coluna fixa (a categoria) e rolagem horizontal nas 12 colunas de mês. É o que torna a tela usável no celular, onde 12 colunas não cabem.

Blocos, nesta ordem: renda, cartões, contas fixas, e o rodapé com os três totais.

- Linhas de cartão vêm dos cartões do usuário e não podem ser removidas na tela. Cartão sem parcelamento nenhum aparece zerado.
- Linhas de renda e de conta fixa são criadas, renomeadas, reordenadas e arquivadas na própria tabela, com uma linha "nova" ao fim de cada bloco.
- Apagar o conteúdo de uma célula devolve ela ao padrão. É assim que se desfaz uma correção.

### Salvamento

Cada célula salva ao perder o foco. Não existe botão de salvar em lote, ao contrário do mock.

O botão exigiria rastrear o estado sujo de cerca de cem células e criaria um jeito silencioso de perder trabalho: o usuário digita seis contas, troca de ano, e perde tudo. Também cria a dúvida permanente de "isso está salvo?" — exatamente a dúvida que uma planilha não deve provocar. Salvando por célula, cada valor é durável no instante em que o campo perde o foco.

Custo aceito: uma requisição por célula editada, e nenhum "desfazer tudo".

### Endpoints

| rota | efeito |
|---|---|
| `PUT /planejamento/linhas/{budgetLine}/{month}` | grava `amount_cents`; `null` remove o registro |
| `PUT /planejamento/cartoes/{card}/{month}` | grava a correção; `null` remove a correção |
| `POST /planejamento/linhas` | cria linha (`kind`, `name`, `default_amount_cents`) |
| `PATCH /planejamento/linhas/{budgetLine}` | renomeia, muda padrão ou posição |
| `DELETE /planejamento/linhas/{budgetLine}` | arquiva (preenche `archived_at`) |

`{month}` no formato `YYYY-MM`.

Nos dois `PUT`, o campo é `amount_cents`. Enviá-lo como `null` — não ausente — apaga o registro do mês. Campo ausente é requisição malformada, para que um erro de serialização no cliente não vire um apagamento silencioso.

Cada rota verifica o dono. Linha ou cartão de outro usuário devolve 404, como no filtro por cartão do dashboard — um recurso alheio não existe para quem pergunta.

## Erros e validação

- `amount_cents` negativo é recusado. Uma conta é uma saída; o sinal vem do `kind`.
- `month` fora do formato `YYYY-MM` é recusado.
- `name` obrigatório, não vazio.
- `kind` só aceita os dois valores do enum.
- Linha arquivada não aceita novos valores mensais.

## Testes

**`BuildAnnualPlan`:**
- mês sem registro usa o padrão da linha
- mês com registro vence o padrão
- correção de cartão vence o calculado, e o calculado continua no retorno
- mês passado soma parcela já vencida — o caso onde o filtro do dashboard erraria
- ENTRADA, SAÍDA e SALDO com renda e despesa juntas
- saldo negativo é preservado como negativo
- parcelas de outro usuário não entram
- linha arquivada não aparece

**Endpoints:**
- gravar valor de célula de linha e de cartão
- corpo vazio limpa o registro
- valor negativo é recusado
- mês malformado é recusado
- linha e cartão de outro usuário devolvem 404
- criar, renomear e arquivar linha

**Tela:**
- os 12 meses chegam ao componente
- ano inválido cai no corrente

## Fora de escopo

- **Linha com data de início e fim.** `FINANCIAMENTO CASA` um dia acaba, mas aqui a linha vale até ser arquivada. Campo que talvez nunca seja usado.
- **Dia de vencimento da conta.** A planilha é mensal; o dia não altera nenhum número dela.
- **Categorias e ícones por linha.** O mock mostra ícones por categoria. Nome basta.
- **Múltiplas fontes de renda como conceito próprio.** Quem quiser duas rendas cria duas linhas `income`.
- **Deslocamento por regime de caixa.** Decidido acima.
- **Reconciliação automática entre correção de cartão e valor calculado.** A tela mostra os dois; o usuário decide.
