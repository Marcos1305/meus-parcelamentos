# Observação, Busca e Ordenação — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dar ao parcelamento um campo de anotação livre, e às tabelas de Histórico e Gastos busca por texto e ordenação por coluna.

**Architecture:** busca e ordenação são estado de URL resolvido no servidor — `BuildHistoryQuery` traduz os parâmetros em cláusulas Eloquent (o Histórico é paginado, ordenar no cliente mentiria), e `BuildSpendingBreakdown` os aplica no `usort` das entries que já carrega em memória. A observação é uma coluna nova em `purchases`, editável inline no Histórico por um `PATCH` dedicado. Um único componente React (`SortableHeader`) desenha o cabeçalho ordenável das duas tabelas.

**Tech Stack:** Laravel 13 / PHP 8.4, Inertia v3 + React 19, Wayfinder, Pest 4, SQLite, Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-08-25-observacao-busca-ordenacao-design.md`

## Global Constraints

- Limite da observação: **1000 caracteres**, na constante `Purchase::MAX_NOTES_LENGTH`. Toda validação referencia a constante, nunca o literal.
- Observação em branco (vazia ou só espaços) grava **`null`**, nunca `''`.
- Parâmetro de URL inválido **cai no default em silêncio** — sem exceção, sem erro de validação. Mesma regra que `mes` e `categorias` já seguem.
- Defaults preservam o comportamento atual: Histórico ordena por `due_date` asc; Gastos por `amount_cents` desc.
- `installments.id` é o desempate final de toda ordenação do Histórico, para a paginação ser estável.
- Nomes de parâmetro seguem a língua de cada tela: Histórico usa `q`, `sort`, `dir` (como `month`, `status`, `card`); Gastos usa `busca`, `ordem`, `direcao` (como `mes`, `categorias`).
- Em Gastos, a busca entra **só** em `entries()`. `categories()`, `series()`, `uncategorizedCount()`, `uncategorizedTotalCents()` e `availableCategories()` nunca a recebem.
- `status` **não** é coluna ordenável no Histórico (é derivado de `due_date`).
- Depois de mexer em PHP: `vendor/bin/pint --dirty --format agent`. Depois de mexer em rotas: `php artisan wayfinder:generate`.
- Rodar testes com `php artisan test --compact --filter=...`.

---

### Task 1: Coluna `notes` e observação no lançamento manual

**Files:**
- Create: `database/migrations/<timestamp>_add_notes_to_purchases_table.php`
- Create: `app/Concerns/NormalizesNotes.php`
- Create: `resources/js/components/ui/textarea.tsx`
- Modify: `app/Models/Purchase.php`
- Modify: `app/Http/Requests/StorePurchaseRequest.php`
- Modify: `database/factories/PurchaseFactory.php`
- Modify: `resources/js/pages/purchases/create.tsx`
- Test: `tests/Feature/PurchaseTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `Purchase::MAX_NOTES_LENGTH` (int, 1000); coluna `purchases.notes` (`text`, nullable); trait `App\Concerns\NormalizesNotes` com `protected function normalizeNotes(): void`; componente React `Textarea` exportado de `@/components/ui/textarea`.

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar ao final de `tests/Feature/PurchaseTest.php`:

```php
it('grava a observacao no lancamento manual', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Sofá',
            'notes' => 'Dividido com o João, ele paga metade em janeiro.',
            'total_amount_cents' => 300_000,
            'installments_count' => 10,
            'purchased_at' => '2026-03-02',
        ])
        ->assertRedirect(route('dashboard'));

    expect(Purchase::sole()->notes)->toBe('Dividido com o João, ele paga metade em janeiro.');
});

it('grava null quando a observacao vem vazia ou so com espacos', function (string $notes): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Sofá',
            'notes' => $notes,
            'total_amount_cents' => 300_000,
            'installments_count' => 10,
            'purchased_at' => '2026-03-02',
        ])
        ->assertRedirect(route('dashboard'));

    expect(Purchase::sole()->notes)->toBeNull();
})->with(['', '   ', "\n\t "]);

it('recusa observacao acima do limite', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'card_id' => $card->id,
            'description' => 'Sofá',
            'notes' => str_repeat('a', Purchase::MAX_NOTES_LENGTH + 1),
            'total_amount_cents' => 300_000,
            'installments_count' => 10,
            'purchased_at' => '2026-03-02',
        ])
        ->assertSessionHasErrors('notes');

    expect(Purchase::count())->toBe(0);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter=observacao`
Expected: FAIL — a coluna `notes` não existe e `MAX_NOTES_LENGTH` não está definida.

- [ ] **Step 3: Criar a migration**

Run: `php artisan make:migration add_notes_to_purchases_table --no-interaction`

Preencher o arquivo gerado:

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
            $table->text('notes')->nullable()->after('merchant');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
```

- [ ] **Step 4: Declarar o campo no modelo**

Em `app/Models/Purchase.php`, acrescentar ao bloco `@property` (logo abaixo de `@property string|null $merchant`):

```php
 * @property string|null $notes
```

Acrescentar `'notes'` ao atributo `#[Fillable]`, depois de `'merchant'`:

```php
#[Fillable(['card_id', 'description', 'merchant', 'notes', 'total_amount_cents', 'installments_count', 'purchased_at', 'category', 'category_is_manual'])]
```

E a constante, depois de `MAX_DESCRIPTION_LENGTH`:

```php
    /**
     * A observacao e texto corrido escrito pelo usuario, nao rotulo: cabe
     * mais que os 120 caracteres da descricao, mas segue limitada para que a
     * coluna nao vire deposito de documento.
     */
    public const int MAX_NOTES_LENGTH = 1000;
```

- [ ] **Step 5: Criar o trait de normalização**

Criar `app/Concerns/NormalizesNotes.php`:

```php
<?php

namespace App\Concerns;

trait NormalizesNotes
{
    /**
     * "Sem observacao" precisa de uma representacao so no banco. Sem isso a
     * mesma ausencia chegaria ora como null, ora como string vazia, e o
     * indicador de "tem nota" da linha do historico teria que conhecer as
     * duas formas para acertar.
     */
    protected function normalizeNotes(): void
    {
        if (! $this->has('notes')) {
            return;
        }

        $notes = trim((string) $this->input('notes'));

        $this->merge(['notes' => $notes === '' ? null : $notes]);
    }
}
```

- [ ] **Step 6: Validar na criação**

Em `app/Http/Requests/StorePurchaseRequest.php`, usar o trait, acrescentar a regra e o hook:

```php
use App\Concerns\NormalizesNotes;
```

Dentro da classe, antes de `rules()`:

```php
    use NormalizesNotes;

    protected function prepareForValidation(): void
    {
        $this->normalizeNotes();
    }
```

E a regra, depois de `merchant`:

```php
            'notes' => ['nullable', 'string', 'max:'.Purchase::MAX_NOTES_LENGTH],
```

- [ ] **Step 7: Rodar os testes**

Run: `php artisan test --compact --filter=observacao`
Expected: PASS (3 testes, 5 asserções de dataset).

- [ ] **Step 8: Declarar o campo na factory**

Em `database/factories/PurchaseFactory.php`, dentro de `definition()`, depois de `'merchant'`:

```php
            'notes' => null,
```

- [ ] **Step 9: Criar o primitivo Textarea**

Criar `resources/js/components/ui/textarea.tsx`, no mesmo padrão de `ui/input.tsx`:

```tsx
import * as React from "react"

import { cn } from "@/lib/utils"

function Textarea({ className, ...props }: React.ComponentProps<"textarea">) {
  return (
    <textarea
      data-slot="textarea"
      className={cn(
        "border-input placeholder:text-muted-foreground flex field-sizing-content min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm",
        "focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]",
        "aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
        className
      )}
      {...props}
    />
  )
}

export { Textarea }
```

- [ ] **Step 10: Adicionar o campo ao formulário de criação**

Em `resources/js/pages/purchases/create.tsx`, importar o primitivo:

```tsx
import { Textarea } from '@/components/ui/textarea';
```

E inserir o bloco logo **depois** do `div` do campo `merchant` e **antes** do `grid` de valor/parcelas/data:

```tsx
                                <div className="grid gap-2">
                                    <Label htmlFor="notes">
                                        Observação (opcional)
                                    </Label>
                                    <Textarea
                                        id="notes"
                                        name="notes"
                                        rows={3}
                                        maxLength={1000}
                                        placeholder="Dividido com o João, ele paga metade em janeiro."
                                    />
                                    <InputError message={errors.notes} />
                                </div>
```

- [ ] **Step 11: Formatar e conferir o front**

Run: `vendor/bin/pint --dirty --format agent && npm run format && npm run types:check && npm run lint:check`
Expected: sem erros.

- [ ] **Step 12: Commit**

```bash
git add app database resources/js/components/ui/textarea.tsx resources/js/pages/purchases/create.tsx tests
git commit -m "feat: observacao livre no lancamento de parcelamento"
```

---

### Task 2: Editar a observação inline no Histórico

**Files:**
- Create: `app/Http/Requests/UpdatePurchaseNotesRequest.php`
- Create: `resources/js/components/purchase-notes-dialog.tsx`
- Modify: `routes/parcelamentos.php`
- Modify: `app/Http/Controllers/PurchaseController.php`
- Modify: `app/Http/Controllers/HistoryController.php`
- Modify: `app/Http/Controllers/ExportHistoryController.php`
- Modify: `resources/js/types/parcelamentos.ts`
- Modify: `resources/js/pages/history/index.tsx`
- Test: `tests/Feature/PurchaseTest.php`, `tests/Feature/ExportHistoryTest.php`

**Interfaces:**
- Consumes: `Purchase::MAX_NOTES_LENGTH`, `App\Concerns\NormalizesNotes`, `Textarea` (Task 1).
- Produces: rota nomeada `purchases.notes` (`PATCH lancamentos/{purchase}/observacao`), disponível no front como `purchases.notes(id).url`; `HistoryRow['purchase']['notes']: string | null` no payload do Inertia; componente `PurchaseNotesDialog` exportado de `@/components/purchase-notes-dialog`.

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar ao final de `tests/Feature/PurchaseTest.php`:

```php
it('atualiza a observacao de uma compra existente', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create(['notes' => null]);

    $this->actingAs($user)
        ->patch(route('purchases.notes', $purchase), ['notes' => 'Garantia até 2028.'])
        ->assertRedirect();

    expect($purchase->refresh()->notes)->toBe('Garantia até 2028.');
});

it('limpa a observacao quando o texto vem so com espacos', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create(['notes' => 'Nota antiga']);

    $this->actingAs($user)
        ->patch(route('purchases.notes', $purchase), ['notes' => '   ']);

    expect($purchase->refresh()->notes)->toBeNull();
});

it('recusa observacao acima do limite na edicao', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create(['notes' => 'Nota antiga']);

    $this->actingAs($user)
        ->patch(route('purchases.notes', $purchase), ['notes' => str_repeat('a', Purchase::MAX_NOTES_LENGTH + 1)])
        ->assertSessionHasErrors('notes');

    expect($purchase->refresh()->notes)->toBe('Nota antiga');
});

it('exige o campo notes na edicao', function (): void {
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create();

    $this->actingAs($user)
        ->patch(route('purchases.notes', $purchase), [])
        ->assertSessionHasErrors('notes');
});

it('nao deixa editar a observacao de compra de outro usuario', function (): void {
    $user = User::factory()->create();
    $foreign = Purchase::factory()->create(['notes' => 'Nota alheia']);

    $this->actingAs($user)
        ->patch(route('purchases.notes', $foreign), ['notes' => 'Invasão'])
        ->assertForbidden();

    expect($foreign->refresh()->notes)->toBe('Nota alheia');
});
```

E em `tests/Feature/HistoryTest.php`:

```php
it('entrega a observacao da compra na linha do historico', function (): void {
    $user = historyUser();
    $installment = installmentFor($user, '2026-07-10', 100_00);
    $installment->purchase->update(['notes' => 'Garantia até 2028.']);

    $this->actingAs($user)
        ->get(route('history.index'))
        ->assertInertia(fn ($page) => $page
            ->where('installments.data.0.purchase.notes', 'Garantia até 2028.'));
});
```

E em `tests/Feature/ExportHistoryTest.php`:

```php
it('exporta a observacao numa coluna propria', function (): void {
    $user = User::factory()->create();
    $comNota = exportInstallment($user, '2026-07-10', 100_00);
    $comNota->purchase->update(['notes' => 'Garantia até 2028.']);
    exportInstallment($user, '2026-07-11', 100_00);

    $csv = csvFrom($this->actingAs($user)->get(route('history.export')));

    expect($csv)->toContain('observacao')
        ->and($csv)->toContain('Garantia até 2028.');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter="observacao|historico"`
Expected: FAIL — a rota `purchases.notes` não existe.

- [ ] **Step 3: Criar o form request**

Criar `app/Http/Requests/UpdatePurchaseNotesRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Concerns\NormalizesNotes;
use App\Models\Purchase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseNotesRequest extends FormRequest
{
    use NormalizesNotes;

    protected function prepareForValidation(): void
    {
        $this->normalizeNotes();
    }

    /**
     * `present` em vez de `required`: apagar a nota e uma operacao legitima e
     * manda null, que `required` recusaria. O campo ainda precisa vir, para
     * que um corpo vazio nunca seja lido como "nao mexe" e o PATCH vire uma
     * escrita silenciosamente sem efeito.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notes' => ['present', 'nullable', 'string', 'max:'.Purchase::MAX_NOTES_LENGTH],
        ];
    }
}
```

- [ ] **Step 4: Criar a rota**

Em `routes/parcelamentos.php`, depois da linha de `purchases.store`:

```php
    Route::patch('lancamentos/{purchase}/observacao', [PurchaseController::class, 'updateNotes'])
        ->name('purchases.notes')
        ->can('update', 'purchase');
```

- [ ] **Step 5: Implementar a ação no controller**

Em `app/Http/Controllers/PurchaseController.php`, importar o request:

```php
use App\Http\Requests\UpdatePurchaseNotesRequest;
```

E acrescentar o método, entre `store()` e `destroy()`:

```php
    /**
     * A observacao mora na compra, nao na parcela: anotar "presente da Ana"
     * vale para as doze parcelas, nao para a de marco. Mesma regra ja adotada
     * para categoria.
     */
    public function updateNotes(UpdatePurchaseNotesRequest $request, Purchase $purchase): RedirectResponse
    {
        $purchase->update($request->validated());

        return back();
    }
```

- [ ] **Step 6: Publicar a observação no payload do Histórico**

Em `app/Http/Controllers/HistoryController.php`, dentro do array `'purchase' => [...]`, depois de `'merchant'`:

```php
                    'notes' => $installment->purchase->notes,
```

- [ ] **Step 7: Acrescentar a coluna ao CSV**

Em `app/Http/Controllers/ExportHistoryController.php`, no `fputcsv` do cabeçalho, inserir `'observacao'` depois de `'estabelecimento'`:

```php
            fputcsv($handle, [
                'cartao', 'descricao', 'estabelecimento', 'observacao', 'valor_total_compra',
                'parcela', 'total_parcelas', 'valor_parcela', 'vencimento', 'status',
            ], ';', '"', '');
```

E no método `row()`, depois de `$purchase->merchant ?? ''`:

```php
            $purchase->notes ?? '',
```

- [ ] **Step 8: Rodar os testes de backend**

Run: `php artisan test --compact --filter="observacao|historico|exporta"`
Expected: PASS.

- [ ] **Step 9: Regenerar as rotas tipadas e o tipo do front**

Run: `php artisan wayfinder:generate`

Em `resources/js/types/parcelamentos.ts`, no tipo `HistoryRow`, dentro de `purchase`, depois de `merchant`:

```ts
        notes: string | null;
```

- [ ] **Step 10: Criar o diálogo de edição**

Criar `resources/js/components/purchase-notes-dialog.tsx`:

```tsx
import { router } from '@inertiajs/react';
import { NotebookPen } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import purchases from '@/routes/purchases';

const MAX_NOTES_LENGTH = 1000;

type PurchaseNotesDialogProps = {
    purchaseId: number;
    description: string;
    notes: string | null;
};

export function PurchaseNotesDialog({
    purchaseId,
    description,
    notes,
}: PurchaseNotesDialogProps) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(notes ?? '');
    const [saving, setSaving] = useState(false);

    // O rascunho volta ao valor gravado sempre que o diálogo abre: fechar sem
    // salvar não pode deixar texto órfão esperando o próximo abrir.
    function toggle(next: boolean) {
        if (next) {
            setDraft(notes ?? '');
        }

        setOpen(next);
    }

    function save() {
        setSaving(true);

        router.patch(
            purchases.notes(purchaseId).url,
            { notes: draft },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setOpen(false),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={toggle}>
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label={
                        notes
                            ? `Editar observação de ${description}`
                            : `Adicionar observação a ${description}`
                    }
                >
                    <NotebookPen
                        className={notes ? undefined : 'text-muted-foreground'}
                    />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Observação</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <Textarea
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    rows={5}
                    maxLength={MAX_NOTES_LENGTH}
                    placeholder="Dividido com o João, ele paga metade em janeiro."
                />
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => toggle(false)}
                        disabled={saving}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={save} disabled={saving}>
                        Salvar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
```

- [ ] **Step 11: Ligar o diálogo na tabela do Histórico**

Em `resources/js/pages/history/index.tsx`, importar:

```tsx
import { PurchaseNotesDialog } from '@/components/purchase-notes-dialog';
```

Na célula "Item", depois do bloco que renderiza `merchant`, acrescentar a nota truncada:

```tsx
                                                {row.purchase.notes && (
                                                    <p className="line-clamp-2 max-w-xs text-xs text-muted-foreground italic">
                                                        {row.purchase.notes}
                                                    </p>
                                                )}
```

E na última célula (a das ações), colocar o diálogo antes do botão de remover:

```tsx
                                            <td className="flex gap-1 p-3">
                                                <PurchaseNotesDialog
                                                    purchaseId={row.purchase.id}
                                                    description={
                                                        row.purchase.description
                                                    }
                                                    notes={row.purchase.notes}
                                                />
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        removePurchase(row)
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </td>
```

- [ ] **Step 12: Formatar e conferir**

Run: `vendor/bin/pint --dirty --format agent && npm run format && npm run types:check && npm run lint:check`
Expected: sem erros.

- [ ] **Step 13: Commit**

```bash
git add app routes resources tests
git commit -m "feat: edicao inline da observacao no historico"
```

---

### Task 3: Busca por texto no Histórico

**Files:**
- Modify: `app/Actions/BuildHistoryQuery.php`
- Modify: `resources/js/types/parcelamentos.ts`
- Modify: `resources/js/pages/history/index.tsx`
- Test: `tests/Feature/HistoryTest.php`, `tests/Feature/ExportHistoryTest.php`

**Interfaces:**
- Consumes: coluna `purchases.notes` (Task 1); `HistoryRow['purchase']['notes']` (Task 2).
- Produces: `BuildHistoryQuery::filters()` passa a devolver a chave `q` (string, `''` quando ausente); `HistoryFilters` no TS ganha `q: string`.

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar a `tests/Feature/HistoryTest.php`:

```php
it('busca por descricao, estabelecimento e observacao', function (string $termo): void {
    $user = historyUser();
    $alvo = installmentFor($user, '2026-07-10', 100_00);
    $alvo->purchase->update([
        'description' => 'Sofá de canto',
        'merchant' => 'Tok Stok',
        'notes' => 'Dividido com o João',
    ]);

    $outro = installmentFor($user, '2026-07-11', 100_00);
    $outro->purchase->update([
        'description' => 'Tênis',
        'merchant' => 'Centauro',
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->get(route('history.index', ['q' => $termo]))
        ->assertInertia(fn ($page) => $page
            ->has('installments.data', 1)
            ->where('installments.data.0.purchase.description', 'Sofá de canto')
            ->where('filters.q', $termo));
})->with(['Sofá', 'Tok', 'João']);

it('ignora maiusculas na busca', function (): void {
    $user = historyUser();
    $alvo = installmentFor($user, '2026-07-10', 100_00);
    $alvo->purchase->update(['description' => 'Sofa de canto', 'merchant' => null, 'notes' => null]);

    $this->actingAs($user)
        ->get(route('history.index', ['q' => 'SOFA']))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));
});

it('combina a busca com os filtros de mes e cartao', function (): void {
    $user = historyUser();
    $card = Card::factory()->for($user)->create();

    $julho = installmentFor($user, '2026-07-10', 100_00, $card);
    $julho->purchase->update(['description' => 'Sofá', 'merchant' => null, 'notes' => null]);

    $agosto = installmentFor($user, '2026-08-10', 100_00, $card);
    $agosto->purchase->update(['description' => 'Sofá', 'merchant' => null, 'notes' => null]);

    $outroCartao = installmentFor($user, '2026-07-10', 100_00);
    $outroCartao->purchase->update(['description' => 'Sofá', 'merchant' => null, 'notes' => null]);

    $this->actingAs($user)
        ->get(route('history.index', ['q' => 'Sofá', 'month' => '2026-07', 'card' => $card->id]))
        ->assertInertia(fn ($page) => $page->has('installments.data', 1));
});

it('devolve lista vazia quando a busca nao casa nada', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-10', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['q' => 'zzzzzz']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('installments.data', 0));
});
```

E a `tests/Feature/ExportHistoryTest.php`:

```php
it('exporta apenas o que casa com a busca', function (): void {
    $user = User::factory()->create();
    $alvo = exportInstallment($user, '2026-07-10', 100_00);
    $alvo->purchase->update(['description' => 'Sofá de canto']);

    $outro = exportInstallment($user, '2026-07-11', 100_00);
    $outro->purchase->update(['description' => 'Tênis Nike']);

    $csv = csvFrom($this->actingAs($user)->get(route('history.export', ['q' => 'Sofá'])));

    expect($csv)->toContain('Sofá de canto')
        ->and($csv)->not->toContain('Tênis Nike');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter="busca|maiusculas|combina|vazia|exporta apenas"`
Expected: FAIL — `filters.q` não existe e a busca não filtra nada.

- [ ] **Step 3: Ler o parâmetro em `filters()`**

Em `app/Actions/BuildHistoryQuery.php`, substituir o método `filters()` inteiro:

```php
    /**
     * @return array{month: string, status: string, card: string, q: string}
     */
    public function filters(Request $request): array
    {
        $term = $request->query('q', '');

        return [
            'month' => (string) $request->query('month', CarbonImmutable::now()->format('Y-m')),
            'status' => (string) $request->query('status', 'all'),
            'card' => (string) $request->query('card', 'all'),
            'q' => is_string($term) ? trim($term) : '',
        ];
    }
```

- [ ] **Step 4: Aplicar a busca na query**

No mesmo arquivo, atualizar o docblock de `handle()` para a nova forma do array:

```php
     * @param  array{month: string, status: string, card: string, q: string}  $filters
```

E inserir o bloco logo **depois** do `if` do filtro de cartão, antes de `$today = CarbonImmutable::now();`:

```php
        if ($filters['q'] !== '') {
            $term = '%'.$filters['q'].'%';

            $query->whereHas('purchase', fn (Builder $purchases) => $purchases
                ->where('description', 'like', $term)
                ->orWhere('merchant', 'like', $term)
                ->orWhere('notes', 'like', $term));
        }
```

Os três `orWhere` vivem dentro do `whereHas`, que já é um `exists` isolado — não vazam para os demais filtros.

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --compact --filter="busca|maiusculas|combina|vazia|exporta apenas"`
Expected: PASS.

- [ ] **Step 6: Atualizar o tipo do front**

Em `resources/js/types/parcelamentos.ts`, no tipo `HistoryFilters`:

```ts
export type HistoryFilters = {
    month: string;
    status: string;
    card: string;
    q: string;
};
```

- [ ] **Step 7: Adicionar o campo de busca à tela**

Em `resources/js/pages/history/index.tsx`, acrescentar os imports:

```tsx
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
```

Dentro do componente, antes de `applyFilter`:

```tsx
    const [term, setTerm] = useState(filters.q);

    // Debounce de 300ms: sem ele cada tecla vira uma requisição e a lista
    // pisca. `replace` evita empilhar uma entrada de histórico por letra.
    useEffect(() => {
        if (term === filters.q) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                index().url,
                { ...filters, q: term },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, filters]);
```

E, no `div` que agrupa os três `Select`, inserir o input como primeiro filho:

```tsx
                    <Input
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder="Buscar por descrição, loja ou observação"
                        aria-label="Buscar parcelamentos"
                        className="w-[320px]"
                    />
```

`applyFilter` já espalha `...filters`, então trocar mês, status ou cartão preserva a busca sem alteração própria. E como `filters` não carrega `page`, qualquer busca nova volta para a primeira página.

- [ ] **Step 8: Formatar e conferir**

Run: `vendor/bin/pint --dirty --format agent && npm run format && npm run types:check && npm run lint:check`
Expected: sem erros.

- [ ] **Step 9: Commit**

```bash
git add app resources tests
git commit -m "feat: busca por texto no historico de parcelas"
```

---

### Task 4: Ordenação por coluna no Histórico

**Files:**
- Create: `resources/js/components/sortable-header.tsx`
- Modify: `app/Actions/BuildHistoryQuery.php`
- Modify: `app/Http/Controllers/HistoryController.php`
- Modify: `app/Http/Controllers/ExportHistoryController.php`
- Modify: `resources/js/types/parcelamentos.ts`
- Modify: `resources/js/pages/history/index.tsx`
- Test: `tests/Feature/HistoryTest.php`

**Interfaces:**
- Consumes: `BuildHistoryQuery::filters()` com a chave `q` (Task 3).
- Produces: `BuildHistoryQuery::filters()` devolve também `sort` (default `'due_date'`) e `dir` (default `'asc'`); `BuildHistoryQuery::handle()` passa a devolver a query **já ordenada** — os dois controllers deixam de chamar `orderBy`; componente React `SortableHeader` exportado de `@/components/sortable-header` com as props `{ column, label, active, direction, href, align? }`.

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar a `tests/Feature/HistoryTest.php`:

```php
function purchaseInstallment(User $user, string $dueDate, int $amountCents, array $purchaseAttributes, ?Card $card = null): Installment
{
    $purchase = Purchase::factory()
        ->for($card ?? Card::factory()->for($user))
        ->create($purchaseAttributes);

    return Installment::factory()->for($purchase)->create([
        'number' => 1,
        'due_date' => $dueDate,
        'amount_cents' => $amountCents,
    ]);
}

it('ordena por descricao', function (): void {
    $user = historyUser();
    purchaseInstallment($user, '2026-07-10', 100_00, ['description' => 'Zebra']);
    purchaseInstallment($user, '2026-07-11', 100_00, ['description' => 'Abacate']);

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'description', 'dir' => 'asc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.purchase.description', 'Abacate'));

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'description', 'dir' => 'desc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.purchase.description', 'Zebra'));
});

it('ordena por valor total da compra', function (): void {
    $user = historyUser();
    purchaseInstallment($user, '2026-07-10', 100_00, ['total_amount_cents' => 50_00]);
    purchaseInstallment($user, '2026-07-11', 100_00, ['total_amount_cents' => 900_00]);

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'total', 'dir' => 'desc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.purchase.total_amount_cents', 900_00));

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'total', 'dir' => 'asc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.purchase.total_amount_cents', 50_00));
});

it('ordena por nome do cartao', function (): void {
    $user = historyUser();
    purchaseInstallment($user, '2026-07-10', 100_00, [], Card::factory()->for($user)->create(['name' => 'Zeta']));
    purchaseInstallment($user, '2026-07-11', 100_00, [], Card::factory()->for($user)->create(['name' => 'Alfa']));

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'card', 'dir' => 'asc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.card.name', 'Alfa'));

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'card', 'dir' => 'desc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.card.name', 'Zeta'));
});

it('ordena pelo numero da parcela', function (): void {
    $user = historyUser();
    $purchase = Purchase::factory()->for(Card::factory()->for($user))->create(['installments_count' => 3]);
    Installment::factory()->for($purchase)->create(['number' => 3, 'due_date' => '2026-07-05', 'amount_cents' => 100_00]);
    Installment::factory()->for($purchase)->create(['number' => 1, 'due_date' => '2026-07-20', 'amount_cents' => 100_00]);

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'number', 'dir' => 'asc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.number', 1));
});

it('ordena por vencimento descendente quando pedido', function (): void {
    $user = historyUser();
    installmentFor($user, '2026-07-02', 100_00);
    installmentFor($user, '2026-07-28', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', ['sort' => 'due_date', 'dir' => 'desc']))
        ->assertInertia(fn ($page) => $page->where('installments.data.0.due_date', '2026-07-28'));
});

it('cai em vencimento ascendente quando sort ou dir sao invalidos', function (array $query): void {
    $user = historyUser();
    installmentFor($user, '2026-07-28', 100_00);
    installmentFor($user, '2026-07-02', 100_00);

    $this->actingAs($user)
        ->get(route('history.index', $query))
        ->assertInertia(fn ($page) => $page
            ->where('installments.data.0.due_date', '2026-07-02')
            ->where('filters.sort', 'due_date')
            ->where('filters.dir', 'asc'));
})->with([
    [['sort' => 'status']],
    [['sort' => 'nao-existe']],
    [['dir' => 'sideways']],
    [['sort' => 'due_date', 'dir' => '']],
]);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter="ordena por|ordena pelo|cai em vencimento"`
Expected: FAIL — `filters.sort` não existe e a ordenação ignora os parâmetros.

- [ ] **Step 3: Ler e validar os parâmetros em `filters()`**

Em `app/Actions/BuildHistoryQuery.php`, importar o que falta no topo:

```php
use App\Models\Card;
use App\Models\Purchase;
```

Acrescentar a lista branca como constante da classe, antes de `filters()`:

```php
    /**
     * Colunas que a tela oferece para ordenar. `status` fica de fora de
     * proposito: ele e derivado de `due_date` comparada com hoje, entao
     * "ordenar por status" seria ordenar por vencimento com outro nome, e a
     * tela ofereceria dois controles que fazem a mesma coisa.
     */
    private const array SORTABLE_COLUMNS = ['description', 'total', 'card', 'number', 'due_date'];
```

E substituir `filters()`:

```php
    /**
     * @return array{month: string, status: string, card: string, q: string, sort: string, dir: string}
     */
    public function filters(Request $request): array
    {
        $term = $request->query('q', '');
        $sort = $request->query('sort');
        $direction = $request->query('dir');

        return [
            'month' => (string) $request->query('month', CarbonImmutable::now()->format('Y-m')),
            'status' => (string) $request->query('status', 'all'),
            'card' => (string) $request->query('card', 'all'),
            'q' => is_string($term) ? trim($term) : '',
            'sort' => in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'due_date',
            'dir' => in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc',
        ];
    }
```

- [ ] **Step 4: Ordenar dentro de `handle()`**

No mesmo arquivo, atualizar o docblock de `handle()`:

```php
     * @param  array{month: string, status: string, card: string, q: string, sort: string, dir: string}  $filters
```

Substituir o `return $query;` final por:

```php
        $this->applySort($query, $filters);

        return $query;
    }

    /**
     * As colunas de `purchases` e `cards` sao ordenadas por subconsulta
     * correlacionada, e nao por join: o `whereHas` do filtro de cartao e o
     * `with` do carregamento contam com uma query sem joins que dupliquem
     * linhas.
     *
     * O desempate por `id` fecha toda ordenacao. Sem ele, duas parcelas com o
     * mesmo vencimento podem trocar de posicao entre requisicoes e sumir da
     * paginacao — a linha aparece na pagina 1 numa carga e na pagina 2 na
     * seguinte, sem nunca ser vista.
     *
     * @param  Builder<Installment>  $query
     * @param  array{month: string, status: string, card: string, q: string, sort: string, dir: string}  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $direction = $filters['dir'];

        match ($filters['sort']) {
            'description' => $query->orderBy(
                Purchase::select('description')->whereColumn('purchases.id', 'installments.purchase_id'),
                $direction,
            ),
            'total' => $query->orderBy(
                Purchase::select('total_amount_cents')->whereColumn('purchases.id', 'installments.purchase_id'),
                $direction,
            ),
            'card' => $query->orderBy(
                Card::select('cards.name')
                    ->join('purchases', 'purchases.card_id', '=', 'cards.id')
                    ->whereColumn('purchases.id', 'installments.purchase_id'),
                $direction,
            ),
            'number' => $query->orderBy('number', $direction),
            default => $query->orderBy('due_date', $direction),
        };

        $query->orderBy('id');
    }
```

- [ ] **Step 5: Tirar o `orderBy` dos controllers**

Em `app/Http/Controllers/HistoryController.php`, remover as duas linhas de ordenação, deixando:

```php
        $installments = $buildHistoryQuery->handle($user, $filters)
            ->paginate(20)
```

Em `app/Http/Controllers/ExportHistoryController.php`, deixar:

```php
        $query = $buildHistoryQuery
            ->handle($request->user(), $buildHistoryQuery->filters($request));
```

A ordenação passa a viver num lugar só, e o CSV herda a mesma ordem da tela pelo mesmo motivo que já herda os filtros.

- [ ] **Step 6: Rodar os testes**

Run: `php artisan test --compact --filter=History`
Expected: PASS — inclusive os testes de ordenação e paginação que já existiam.

- [ ] **Step 7: Criar o componente de cabeçalho ordenável**

Criar `resources/js/components/sortable-header.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import { cn } from '@/lib/utils';

export type SortDirection = 'asc' | 'desc';

type SortableHeaderProps = {
    column: string;
    label: string;
    active: string;
    direction: SortDirection;
    href: (column: string) => string;
    align?: 'left' | 'right';
};

export function SortableHeader({
    column,
    label,
    active,
    direction,
    href,
    align = 'left',
}: SortableHeaderProps) {
    const isActive = active === column;
    const Icon = !isActive
        ? ChevronsUpDown
        : direction === 'asc'
          ? ArrowUp
          : ArrowDown;

    return (
        <th
            className="p-0"
            aria-sort={
                isActive
                    ? direction === 'asc'
                        ? 'ascending'
                        : 'descending'
                    : 'none'
            }
        >
            <Link
                href={href(column)}
                preserveState
                replace
                className={cn(
                    'flex w-full items-center gap-1 p-3 text-xs tracking-wide uppercase transition-colors hover:text-foreground',
                    isActive ? 'text-foreground' : 'text-muted-foreground',
                    align === 'right' && 'justify-end',
                )}
            >
                {label}
                <Icon
                    aria-hidden="true"
                    className={cn(
                        'size-3',
                        !isActive && 'opacity-40',
                    )}
                />
            </Link>
        </th>
    );
}
```

- [ ] **Step 8: Atualizar o tipo dos filtros**

Em `resources/js/types/parcelamentos.ts`:

```ts
export type HistoryFilters = {
    month: string;
    status: string;
    card: string;
    q: string;
    sort: string;
    dir: 'asc' | 'desc';
};
```

- [ ] **Step 9: Usar o componente na tabela do Histórico**

Em `resources/js/pages/history/index.tsx`, importar:

```tsx
import {
    SortableHeader,
    type SortDirection,
} from '@/components/sortable-header';
```

Acrescentar, acima do componente `History`, o mapa de direções iniciais e o gerador de link:

```tsx
/**
 * Direção com que cada coluna abre no primeiro clique. Valor e vencimento
 * abrem decrescentes porque "o mais caro" e "o mais recente" são o que se
 * procura primeiro; texto abre crescente porque A→Z é o que se espera.
 */
const HISTORY_SORT_DEFAULTS: Record<string, SortDirection> = {
    description: 'asc',
    total: 'desc',
    card: 'asc',
    number: 'asc',
    due_date: 'asc',
};
```

Dentro do componente, depois de `applyFilter`:

```tsx
    function sortHref(column: string) {
        const direction: SortDirection =
            filters.sort === column
                ? filters.dir === 'asc'
                    ? 'desc'
                    : 'asc'
                : (HISTORY_SORT_DEFAULTS[column] ?? 'asc');

        return index({
            query: { ...filters, sort: column, dir: direction },
        }).url;
    }
```

Substituir o `<tr>` do `<thead>` inteiro por:

```tsx
                                    <tr>
                                        <SortableHeader
                                            column="description"
                                            label="Item"
                                            active={filters.sort}
                                            direction={filters.dir}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="total"
                                            label="Valor total"
                                            active={filters.sort}
                                            direction={filters.dir}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="card"
                                            label="Cartão"
                                            active={filters.sort}
                                            direction={filters.dir}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="number"
                                            label="Parcela"
                                            active={filters.sort}
                                            direction={filters.dir}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="due_date"
                                            label="Vencimento"
                                            active={filters.sort}
                                            direction={filters.dir}
                                            href={sortHref}
                                        />
                                        <th className="p-3">Status</th>
                                        <th className="p-3" />
                                    </tr>
```

O `<thead>` perde as classes de cor e alinhamento que agora vivem em cada célula:

```tsx
                                <thead className="border-b text-left">
```

- [ ] **Step 10: Formatar e conferir**

Run: `vendor/bin/pint --dirty --format agent && npm run format && npm run types:check && npm run lint:check`
Expected: sem erros.

- [ ] **Step 11: Commit**

```bash
git add app resources tests
git commit -m "feat: ordenacao por coluna no historico de parcelas"
```

---

### Task 5: Busca por texto em Gastos

**Files:**
- Modify: `app/Actions/BuildSpendingBreakdown.php`
- Modify: `app/Http/Controllers/SpendingController.php`
- Modify: `resources/js/types/parcelamentos.ts`
- Modify: `resources/js/pages/spending/index.tsx`
- Test: `tests/Feature/BuildSpendingBreakdownTest.php`, `tests/Feature/SpendingTest.php`

**Interfaces:**
- Consumes: nada das tarefas anteriores.
- Produces: `BuildSpendingBreakdown::handle()` ganha o parâmetro nomeado `string $search = ''` (depois de `$categoryFilter`); o payload ganha a chave `search` (string); `SpendingBreakdown['search']: string` no TS.

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar a `tests/Feature/BuildSpendingBreakdownTest.php`:

```php
it('a busca estreita as entries sem mexer nos graficos', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Padaria do Zé',
        'amount_cents' => 30_00,
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Posto Ipiranga',
        'amount_cents' => 200_00,
        'category' => ExpenseCategory::Transporte,
        'billed_at' => '2026-07-12',
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        search: 'padaria',
    );

    expect($breakdown['entries'])->toHaveCount(1)
        ->and($breakdown['entries'][0]['description'])->toBe('Padaria do Zé')
        ->and($breakdown['categories'])->toHaveCount(2)
        ->and(array_sum(array_column($breakdown['categories'], 'total_cents')))->toBe(230_00)
        ->and($breakdown['search'])->toBe('padaria');
});

it('a busca convive com o filtro de categoria', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Padaria do Zé',
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Padaria da esquina',
        'category' => ExpenseCategory::Transporte,
        'billed_at' => '2026-07-11',
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        categoryFilter: ['mercado'],
        search: 'padaria',
    );

    expect($breakdown['entries'])->toHaveCount(1)
        ->and($breakdown['entries'][0]['description'])->toBe('Padaria do Zé');
});

it('a busca ignora maiusculas e casa parte da descricao', function (string $termo): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Padaria do Zé',
        'billed_at' => '2026-07-10',
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        search: $termo,
    );

    expect($breakdown['entries'])->toHaveCount(1);
})->with(['PADARIA', 'padaria', 'daria do']);
```

E a `tests/Feature/SpendingTest.php`:

```php
it('filtra a lista de gastos pela busca da url', function (): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Padaria do Zé',
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->forUser($user)->create([
        'description' => 'Posto Ipiranga',
        'billed_at' => '2026-07-12',
    ]);

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-07', 'busca' => 'padaria']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('breakdown.entries', 1)
            ->where('breakdown.entries.0.description', 'Padaria do Zé')
            ->where('breakdown.search', 'padaria'));
});

it('trata busca ausente ou hostil como busca vazia', function (mixed $busca): void {
    $user = User::factory()->create();
    CashPurchase::factory()->forUser($user)->create(['billed_at' => '2026-07-10']);

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-07', 'busca' => $busca]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('breakdown.entries', 1)
            ->where('breakdown.search', ''));
})->with([['   '], [''], [['a', 'b']]]);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter="a busca|filtra a lista de gastos|hostil"`
Expected: FAIL — `handle()` não aceita `search`.

- [ ] **Step 3: Aceitar e propagar o termo na action**

Em `app/Actions/BuildSpendingBreakdown.php`, na assinatura de `handle()`:

```php
    public function handle(User $user, CarbonImmutable $month, int $months = 12, array $categoryFilter = [], string $search = ''): array
```

Acrescentar ao docblock de `handle()`, junto dos demais `@param`:

```php
     * @param  string  $search  termo livre casado contra a descricao; vazio significa "sem busca"
```

E ao final da lista de decisões do mesmo docblock, o parágrafo que registra a exceção:

```php
     * A busca livre e a excecao deliberada a tudo isso: ela entra so em
     * entries(). O filtro de categoria e uma particao do gasto — as fatias
     * somam o subconjunto e a reconciliacao segue verdadeira. A busca e uma
     * lente sobre a lista: um grafico que muda a cada tecla digitada nao
     * comunica nada, e o "total do mes" deixaria de ser o total do mes.
```

Trocar a montagem do retorno para passar o termo só a `entries()` e devolvê-lo no payload:

```php
            'entries' => $this->entries($cash, $installments, $start, $search),
```

e, junto de `'active_categories'`:

```php
            'search' => $search,
```

Acrescentar a chave ao array shape do `@return` de `handle()`, depois de `active_categories`:

```php
     *   search: string,
```

- [ ] **Step 4: Filtrar dentro de `entries()`**

No mesmo arquivo, trocar a assinatura e o `return` de `entries()`:

```php
    private function entries(Collection $cash, Collection $installments, CarbonImmutable $month, string $search = ''): array
```

E, imediatamente antes do `usort` final:

```php
        if ($search !== '') {
            $needle = mb_strtolower($search);

            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => str_contains(mb_strtolower($entry['description']), $needle),
            ));
        }
```

Acrescentar o `@param` correspondente ao docblock de `entries()`:

```php
     * @param  string  $search  termo ja normalizado pelo controller; vazio significa "sem busca"
```

- [ ] **Step 5: Ler o parâmetro no controller**

Em `app/Http/Controllers/SpendingController.php`, passar o termo em `index()`:

```php
            'breakdown' => $buildSpendingBreakdown->handle(
                $request->user(),
                $this->selectedMonth($request),
                categoryFilter: $this->categoryFilter($request),
                search: $this->search($request),
            ),
```

E acrescentar o método privado, ao lado de `categoryFilter()`:

```php
    /**
     * Mesma regra do mes e da categoria: o termo vem da barra de enderecos,
     * entao qualquer coisa que nao seja texto vira busca vazia em vez de
     * derrubar a tela. O trim evita que um espaco solto conte como busca
     * ativa e esvazie a lista sem o usuario entender por que.
     */
    private function search(Request $request): string
    {
        $term = $request->query('busca');

        return is_string($term) ? trim($term) : '';
    }
```

- [ ] **Step 6: Rodar os testes**

Run: `php artisan test --compact --filter="Spending|SpendingBreakdown"`
Expected: PASS — inclusive os testes de filtro de categoria já existentes.

- [ ] **Step 7: Atualizar o tipo do front**

Em `resources/js/types/parcelamentos.ts`, no tipo `SpendingBreakdown`, depois de `available_categories`:

```ts
    /**
     * Termo de busca ja normalizado pelo servidor. Filtra apenas `entries`;
     * `categories`, `months` e os `uncategorized_*` seguem enxergando o mes
     * inteiro.
     */
    search: string;
```

- [ ] **Step 8: Adicionar o campo de busca à tela**

Em `resources/js/pages/spending/index.tsx`, acrescentar os imports:

```tsx
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
```

Trocar a assinatura de `spendingQuery` para carregar o termo:

```tsx
function spendingQuery(
    targetMonth: string,
    activeCategories: string[],
    search: string,
): Record<string, string> {
    const query: Record<string, string> = { mes: targetMonth };

    if (activeCategories.length > 0) {
        query.categorias = activeCategories.join(',');
    }

    if (search !== '') {
        query.busca = search;
    }

    return query;
}
```

Dentro do componente, ler a chave nova na desestruturação de `breakdown`:

```tsx
        search,
```

Atualizar as duas chamadas existentes de `spendingQuery` para passar `search`:

```tsx
    function goToMonth(target: string) {
        return spending.index({
            query: spendingQuery(target, activeCategories, search),
        });
    }

    function toggleCategoryFilter(category: string): string {
        const next = activeCategories.includes(category)
            ? activeCategories.filter((value) => value !== category)
            : [...activeCategories, category];

        return spending.index({ query: spendingQuery(month, next, search) }).url;
    }
```

Acrescentar o estado e o debounce, antes de `goToMonth`:

```tsx
    const [term, setTerm] = useState(search);

    // Debounce de 300ms, mesma razão do Histórico: sem ele cada tecla vira
    // uma requisição e a lista pisca.
    useEffect(() => {
        if (term === search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                spending.index({
                    query: spendingQuery(month, activeCategories, term),
                }).url,
                {},
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, search, month, activeCategories]);
```

Inserir o input logo depois do `CategoryFilterChips`:

```tsx
                <Input
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    placeholder="Buscar por descrição"
                    aria-label="Buscar gastos"
                    className="w-[320px]"
                />
```

- [ ] **Step 9: Cobrir o estado vazio da busca**

Ainda em `resources/js/pages/spending/index.tsx`, o `EmptyState` precisa distinguir os três motivos. Substituir o bloco `{entries.length === 0 && (...)}` por:

```tsx
                {entries.length === 0 && (
                    <EmptyState
                        title={
                            search !== ''
                                ? 'Nenhum gasto casa com a busca'
                                : isFiltered
                                  ? 'Nenhum gasto nas categorias selecionadas'
                                  : 'Nenhum gasto neste mês'
                        }
                        description={
                            search !== ''
                                ? 'Os gráficos continuam mostrando o mês inteiro. Limpe a busca para ver a lista completa.'
                                : isFiltered
                                  ? 'Ajuste os filtros acima ou troque de mês para ver outros gastos.'
                                  : 'A análise por categoria começa depois da primeira importação de fatura.'
                        }
                    />
                )}
```

- [ ] **Step 10: Formatar e conferir**

Run: `vendor/bin/pint --dirty --format agent && npm run format && npm run types:check && npm run lint:check`
Expected: sem erros.

- [ ] **Step 11: Commit**

```bash
git add app resources tests
git commit -m "feat: busca por texto na lista de gastos"
```

---

### Task 6: Ordenação por coluna em Gastos

**Files:**
- Modify: `app/Actions/BuildSpendingBreakdown.php`
- Modify: `app/Http/Controllers/SpendingController.php`
- Modify: `resources/js/types/parcelamentos.ts`
- Modify: `resources/js/pages/spending/index.tsx`
- Test: `tests/Feature/BuildSpendingBreakdownTest.php`, `tests/Feature/SpendingTest.php`

**Interfaces:**
- Consumes: `SortableHeader` de `@/components/sortable-header` (Task 4); `handle(..., string $search = '')` e a chave `search` do payload (Task 5).
- Produces: `BuildSpendingBreakdown::handle()` ganha `string $sort = 'valor'` e `string $direction = 'desc'` (depois de `$search`); o payload ganha `sort` e `direction`; `SpendingBreakdown['sort']: string` e `SpendingBreakdown['direction']: 'asc' | 'desc'` no TS.

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar a `tests/Feature/BuildSpendingBreakdownTest.php`:

```php
function gastosDeJulho(User $user): void
{
    $card = Card::factory()->for($user)->create(['name' => 'Zeta']);
    $outro = Card::factory()->for($user)->create(['name' => 'Alfa']);

    CashPurchase::factory()->for($card)->create([
        'description' => 'Zebra',
        'amount_cents' => 10_00,
        'purchased_at' => '2026-07-20',
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Transporte,
    ]);

    CashPurchase::factory()->for($outro)->create([
        'description' => 'Abacate',
        'amount_cents' => 500_00,
        'purchased_at' => '2026-07-02',
        'billed_at' => '2026-07-10',
        'category' => ExpenseCategory::Mercado,
    ]);
}

it('ordena as entries de gastos pela coluna pedida', function (string $ordem, string $direcao, string $primeiro): void {
    $user = User::factory()->create();
    gastosDeJulho($user);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        sort: $ordem,
        direction: $direcao,
    );

    expect($breakdown['entries'][0]['description'])->toBe($primeiro);
})->with([
    ['descricao', 'asc', 'Abacate'],
    ['descricao', 'desc', 'Zebra'],
    ['cartao', 'asc', 'Abacate'],
    ['cartao', 'desc', 'Zebra'],
    ['data', 'desc', 'Zebra'],
    ['data', 'asc', 'Abacate'],
    ['valor', 'desc', 'Abacate'],
    ['valor', 'asc', 'Zebra'],
]);

it('joga o gasto sem categoria para o fim ao ordenar por categoria', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    CashPurchase::factory()->for($card)->create([
        'description' => 'Sem rótulo',
        'category' => null,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->for($card)->create([
        'description' => 'Com rótulo',
        'category' => ExpenseCategory::Mercado,
        'billed_at' => '2026-07-10',
    ]);

    $breakdown = new BuildSpendingBreakdown()->handle(
        $user,
        CarbonImmutable::parse('2026-07-01'),
        sort: 'categoria',
        direction: 'asc',
    );

    expect($breakdown['entries'][1]['description'])->toBe('Sem rótulo');
});
```

E a `tests/Feature/SpendingTest.php`:

```php
it('ordena os gastos pela coluna da url', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    CashPurchase::factory()->for($card)->create([
        'description' => 'Zebra',
        'amount_cents' => 10_00,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->for($card)->create([
        'description' => 'Abacate',
        'amount_cents' => 500_00,
        'billed_at' => '2026-07-10',
    ]);

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-07', 'ordem' => 'descricao', 'direcao' => 'asc']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.entries.0.description', 'Abacate')
            ->where('breakdown.sort', 'descricao')
            ->where('breakdown.direction', 'asc'));
});

it('cai em valor decrescente quando ordem ou direcao sao invalidas', function (array $query): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    CashPurchase::factory()->for($card)->create([
        'description' => 'Barato',
        'amount_cents' => 10_00,
        'billed_at' => '2026-07-10',
    ]);
    CashPurchase::factory()->for($card)->create([
        'description' => 'Caro',
        'amount_cents' => 500_00,
        'billed_at' => '2026-07-10',
    ]);

    $this->actingAs($user)
        ->get(route('spending.index', [...$query, 'mes' => '2026-07']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.entries.0.description', 'Caro')
            ->where('breakdown.sort', 'valor')
            ->where('breakdown.direction', 'desc'));
})->with([
    [['ordem' => 'nao-existe']],
    [['direcao' => 'sideways']],
    [['ordem' => '']],
]);

it('a ordenacao sobrevive a troca de mes', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    CashPurchase::factory()->for($card)->create([
        'description' => 'Zebra',
        'amount_cents' => 10_00,
        'billed_at' => '2026-06-10',
    ]);
    CashPurchase::factory()->for($card)->create([
        'description' => 'Abacate',
        'amount_cents' => 500_00,
        'billed_at' => '2026-06-10',
    ]);

    $this->actingAs($user)
        ->get(route('spending.index', ['mes' => '2026-06', 'ordem' => 'descricao', 'direcao' => 'asc']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('breakdown.month', '2026-06')
            ->where('breakdown.entries.0.description', 'Abacate'));
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --compact --filter="ordena as entries|ordena os gastos|sem categoria para o fim|cai em valor|sobrevive a troca"`
Expected: FAIL — `handle()` não aceita `sort`.

- [ ] **Step 3: Aceitar os parâmetros na action**

Em `app/Actions/BuildSpendingBreakdown.php`, acrescentar a lista branca como constante, ao lado de `UNCATEGORIZED_CATEGORY`:

```php
    /**
     * Colunas ordenaveis da lista, na nomenclatura em portugues que a tela de
     * gastos ja usa na url (mes, categorias, busca).
     */
    public const array SORTABLE_COLUMNS = ['descricao', 'cartao', 'data', 'valor', 'categoria'];
```

Nova assinatura de `handle()`:

```php
    public function handle(User $user, CarbonImmutable $month, int $months = 12, array $categoryFilter = [], string $search = '', string $sort = 'valor', string $direction = 'desc'): array
```

Acrescentar ao docblock de `handle()`:

```php
     * @param  string  $sort  coluna de ordenacao ja validada pelo controller (self::SORTABLE_COLUMNS)
     * @param  string  $direction  'asc' ou 'desc', ja validada pelo controller
```

Passar adiante e publicar no payload:

```php
            'entries' => $this->entries($cash, $installments, $start, $search, $sort, $direction),
```

e, junto de `'search'`:

```php
            'sort' => $sort,
            'direction' => $direction,
```

Acrescentar as duas chaves ao array shape do `@return`, depois de `search`:

```php
     *   sort: string,
     *   direction: string,
```

- [ ] **Step 4: Ordenar dentro de `entries()`**

Nova assinatura:

```php
    private function entries(Collection $cash, Collection $installments, CarbonImmutable $month, string $search = '', string $sort = 'valor', string $direction = 'desc'): array
```

Substituir o `usort` fixo do final por:

```php
        $signal = $direction === 'asc' ? 1 : -1;

        usort($entries, fn (array $a, array $b): int => $signal * match ($sort) {
            'descricao' => strnatcasecmp($a['description'], $b['description']),
            'cartao' => strnatcasecmp($a['card_name'], $b['card_name']),
            'data' => strcmp($a['purchased_at'], $b['purchased_at']),
            'categoria' => strnatcasecmp($this->categoryLabel($a['category']), $this->categoryLabel($b['category'])),
            default => $a['amount_cents'] <=> $b['amount_cents'],
        });

        return $entries;
```

E acrescentar o helper privado, logo depois de `entries()`:

```php
    /**
     * Rotulo usado so para ordenar. O prefixo do balde "sem categoria" e um
     * caractere alto de proposito: ordenar alfabeticamente colocaria "Sem
     * categoria" no meio da lista, e o que o usuario quer ver junto e o que
     * ainda falta classificar — no fim, num bloco so.
     */
    private function categoryLabel(?string $category): string
    {
        return $category === null
            ? '~~~'
            : ExpenseCategory::from($category)->label();
    }
```

Acrescentar os `@param` novos ao docblock de `entries()`:

```php
     * @param  string  $sort  coluna de ordenacao (self::SORTABLE_COLUMNS)
     * @param  string  $direction  'asc' ou 'desc'
```

- [ ] **Step 5: Ler e validar os parâmetros no controller**

Em `app/Http/Controllers/SpendingController.php`, passar em `index()`:

```php
            'breakdown' => $buildSpendingBreakdown->handle(
                $request->user(),
                $this->selectedMonth($request),
                categoryFilter: $this->categoryFilter($request),
                search: $this->search($request),
                sort: $this->sort($request),
                direction: $this->direction($request),
            ),
```

E os dois métodos privados, ao lado de `search()`:

```php
    /**
     * Coluna fora da lista branca cai no default em silencio, como o mes e a
     * categoria: e parametro de url, nao entrada de formulario.
     */
    private function sort(Request $request): string
    {
        $sort = $request->query('ordem');

        return in_array($sort, BuildSpendingBreakdown::SORTABLE_COLUMNS, true) ? $sort : 'valor';
    }

    private function direction(Request $request): string
    {
        $direction = $request->query('direcao');

        return in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';
    }
```

- [ ] **Step 6: Rodar os testes**

Run: `php artisan test --compact --filter="Spending|SpendingBreakdown"`
Expected: PASS — inclusive os testes antigos, que dependem do default valor/desc não ter mudado.

- [ ] **Step 7: Atualizar o tipo do front**

Em `resources/js/types/parcelamentos.ts`, no tipo `SpendingBreakdown`, depois de `search`:

```ts
    sort: string;
    direction: 'asc' | 'desc';
```

- [ ] **Step 8: Usar o componente na tabela de Gastos**

Em `resources/js/pages/spending/index.tsx`, importar:

```tsx
import {
    SortableHeader,
    type SortDirection,
} from '@/components/sortable-header';
```

Acrescentar, acima do componente `Spending`, o mapa de direções iniciais:

```tsx
const SPENDING_SORT_DEFAULTS: Record<string, SortDirection> = {
    descricao: 'asc',
    cartao: 'asc',
    data: 'desc',
    valor: 'desc',
    categoria: 'asc',
};
```

Estender `spendingQuery` para carregar ordem e direção — a assinatura completa passa a ser:

```tsx
function spendingQuery(
    targetMonth: string,
    activeCategories: string[],
    search: string,
    sort: string,
    direction: SortDirection,
): Record<string, string> {
    const query: Record<string, string> = { mes: targetMonth };

    if (activeCategories.length > 0) {
        query.categorias = activeCategories.join(',');
    }

    if (search !== '') {
        query.busca = search;
    }

    if (sort !== 'valor' || direction !== 'desc') {
        query.ordem = sort;
        query.direcao = direction;
    }

    return query;
}
```

Omitir ordem e direção quando são o default mantém a url limpa no caso comum, pela mesma razão que `categorias` já é omitido quando vazio.

Ler as chaves novas na desestruturação de `breakdown`:

```tsx
        sort,
        direction,
```

Atualizar as três chamadas de `spendingQuery` (em `goToMonth`, `toggleCategoryFilter` e no `useEffect` da busca) para passar `sort` e `direction` ao final. E acrescentar o gerador de link, depois de `toggleCategoryFilter`:

```tsx
    function sortHref(column: string) {
        const next: SortDirection =
            sort === column
                ? direction === 'asc'
                    ? 'desc'
                    : 'asc'
                : (SPENDING_SORT_DEFAULTS[column] ?? 'asc');

        return spending.index({
            query: spendingQuery(
                month,
                activeCategories,
                search,
                column,
                next,
            ),
        }).url;
    }
```

Substituir o `<tr>` do `<thead>` da tabela de gastos por:

```tsx
                                    <tr>
                                        <SortableHeader
                                            column="descricao"
                                            label="Descrição"
                                            active={sort}
                                            direction={direction}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="cartao"
                                            label="Cartão"
                                            active={sort}
                                            direction={direction}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="data"
                                            label="Data"
                                            active={sort}
                                            direction={direction}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="valor"
                                            label="Valor"
                                            active={sort}
                                            direction={direction}
                                            href={sortHref}
                                        />
                                        <SortableHeader
                                            column="categoria"
                                            label="Categoria"
                                            active={sort}
                                            direction={direction}
                                            href={sortHref}
                                        />
                                    </tr>
```

E o `<thead>` perde as classes que agora vivem em cada célula:

```tsx
                                <thead className="border-b text-left">
```

- [ ] **Step 9: Rodar a suíte inteira e conferir o front**

Run: `php artisan test --compact`
Expected: PASS, sem regressão em nenhum arquivo.

Run: `vendor/bin/pint --dirty --format agent && npm run format && npm run types:check && npm run lint:check && ./vendor/bin/phpstan analyse --memory-limit=2G`
Expected: sem erros.

- [ ] **Step 10: Commit**

```bash
git add app resources tests
git commit -m "feat: ordenacao por coluna na lista de gastos"
```

---

## Verificação final

- [ ] `php artisan test --compact` — suíte inteira verde.
- [ ] `./vendor/bin/phpstan analyse --memory-limit=2G` — sem erros novos.
- [ ] `npm run types:check && npm run lint:check && npm run format:check` — limpos.
- [ ] Conferir na UI servida (não em screenshot): criar um lançamento com observação, editar a observação de uma compra importada, buscar nas duas telas, e clicar em cada cabeçalho ordenável verificando que a seta acompanha e a URL carrega o estado.
