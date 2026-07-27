<?php

use App\Invoices\ExtractsInvoiceText;
use App\Invoices\InvoiceParser;
use App\Invoices\ParsedInvoice;
use App\Invoices\ParsedInvoiceLine;
use App\Invoices\ResolveInvoiceParser;
use App\Models\Card;
use App\Models\Purchase;
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

it('nao expoe fatura de outro usuario guardada na sessao', function (): void {
    $user = User::factory()->create();
    $foreign = Card::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->withSession(['invoice_import' => [
            'card_id' => $foreign->id,
            'issuer' => 'Nubank',
            'due_date' => '2026-05-10',
            'candidates' => [],
        ]])
        ->get(route('invoice-imports.review'))
        ->assertRedirect(route('invoice-imports.create'))
        ->assertSessionMissing('invoice_import');
});

it('recusa arquivo que nao e pdf', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create(['last_four_digits' => '8291']);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine());

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

    $purchase = Purchase::sole();

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

    expect(Purchase::sole()->purchased_at->toDateString())->toBe('2026-02-20');
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
