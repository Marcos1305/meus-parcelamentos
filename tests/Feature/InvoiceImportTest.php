<?php

use App\Actions\GenerateInstallments;
use App\Invoices\ExtractsInvoiceText;
use App\Invoices\InvoiceParser;
use App\Invoices\ParsedInvoice;
use App\Invoices\ParsedInvoiceLine;
use App\Invoices\Parsers\ItauInvoiceParser;
use App\Invoices\Parsers\MercadoPagoInvoiceParser;
use App\Invoices\Parsers\NubankInvoiceParser;
use App\Invoices\Parsers\RennerInvoiceParser;
use App\Invoices\ResolveInvoiceParser;
use App\Models\Card;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Guarda a senha que chegou ao extrator para provar que o campo do
 * formulario continua ligado ao binario.
 */
final class RecordingExtractor implements ExtractsInvoiceText
{
    public ?string $password = null;

    public function extract(string $path, ?string $password = null): string
    {
        $this->password = $password;

        return 'texto qualquer';
    }
}

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

/**
 * Monta o estado em que nenhum emissor esta suportado. O gate deriva da lista
 * de parsers, entao esvazia-la e a unica forma de exercitar esse caminho.
 */
function noParsersRegistered(): void
{
    app()->bind(ResolveInvoiceParser::class, fn (): ResolveInvoiceParser => new ResolveInvoiceParser([]));
}

/**
 * Uma compra ja no historico, gravada com o cronograma de parcelas como fazem
 * os dois caminhos de escrita do app.
 */
function importedPurchase(Card $card, string $purchasedAt): void
{
    $purchase = Purchase::create([
        'card_id' => $card->id,
        'description' => 'Mercado Livre',
        'merchant' => 'Mercado Livre',
        'total_amount_cents' => 129_900,
        'installments_count' => 10,
        'purchased_at' => $purchasedAt,
    ]);

    app(GenerateInstallments::class)->handle($purchase->load('card'));
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

/**
 * Duas assinaturas iguais cobradas no mesmo dia e no mesmo cartao: mesma data,
 * mesmo valor e mesma parcela, com o texto do estabelecimento variando.
 */
function invoiceWithTwoIdenticalLines(): ParsedInvoice
{
    return new ParsedInvoice(
        issuer: 'Nubank',
        dueDate: CarbonImmutable::parse('2026-05-10'),
        lastFourDigits: '8291',
        lines: [
            new ParsedInvoiceLine('MERCADOLIVRE*ABC', 'Mercado Livre', 2, 3, 10, 12_990),
            new ParsedInvoiceLine('MERCADO LIVRE', 'Mercado Livre', 2, 3, 10, 12_990),
        ],
    );
}

it('mostra o formulario de upload quando ha parser registrado', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();

    fakeParserFor(invoiceWithOneLine());

    $this->actingAs($user)
        ->get(route('invoice-imports.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('invoices/import')
            ->where('invoiceImportAvailable', true));
});

/**
 * Sem fake nenhum: o gate deriva da lista de parsers montada no container, e e
 * esse binding real que precisa anunciar a importacao como disponivel.
 */
it('anuncia a importacao como disponivel com os parsers de producao', function (): void {
    $user = User::factory()->create();

    expect(app(ResolveInvoiceParser::class)->hasParsers())->toBeTrue();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('invoiceImportAvailable', true));
});

it('esconde a importacao enquanto nenhum parser estiver registrado', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create();

    noParsersRegistered();

    $this->actingAs($user)
        ->get(route('invoice-imports.create'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(
            'inertia.flash_data.toast.message',
            'A importação de fatura ainda não está disponível. Continue lançando suas compras manualmente.',
        );

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('invoiceImportAvailable', false));
});

it('redireciona para o cadastro de cartao quando nao ha nenhum', function (): void {
    $user = User::factory()->create();

    fakeParserFor(invoiceWithOneLine());

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

/**
 * Sem fake de parser: cada emissor suportado precisa continuar registrado no
 * container. Quem sair da lista para de reconhecer a fatura que atende, e a
 * importacao passa a recusar esse emissor em producao.
 */
it('reconhece a fatura de cada emissor com os parsers registrados', function (string $fixture, string $parser): void {
    $text = file_get_contents(base_path("tests/Fixtures/faturas/{$fixture}"));

    expect(app(ResolveInvoiceParser::class)->handle($text))->toBeInstanceOf($parser);
})->with([
    'nubank' => ['nubank.txt', NubankInvoiceParser::class],
    'mercado pago' => ['mercado-pago.txt', MercadoPagoInvoiceParser::class],
    'itau' => ['itau.txt', ItauInvoiceParser::class],
    'renner' => ['renner.txt', RennerInvoiceParser::class],
]);

/**
 * O unico teste que atravessa parser e controller com o texto de uma fatura
 * real: so o extrator e falso, o resto e a montagem de producao.
 */
it('leva a fatura de cada emissor do upload ate a revisao', function (string $fixture, string $issuer, int $candidatos): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create(['closing_day' => 5, 'due_day' => 9]);

    fakeExtractorReturning(file_get_contents(base_path("tests/Fixtures/faturas/{$fixture}")));

    $this->actingAs($user)
        ->post(route('invoice-imports.store'), [
            'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
            'card_id' => $card->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('invoice-imports.review'));

    $this->actingAs($user)
        ->get(route('invoice-imports.review'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('issuer', $issuer)
            ->has('candidates', $candidatos));
})->with([
    'nubank' => ['nubank.txt', 'Nubank', 20],
    'mercado pago' => ['mercado-pago.txt', 'Mercado Pago', 22],
    'itau' => ['itau.txt', 'Itaú', 19],
    'renner' => ['renner.txt', 'Renner', 15],
]);

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

/**
 * O Nubank carimba a parcela 1 com o dia real da compra e as parcelas
 * seguintes com o dia de abertura do ciclo, entao a mesma compra chega com dia
 * diferente em cada fatura e resolve para datas de compra diferentes. Importar
 * duas faturas consecutivas nao pode gravar a compra duas vezes: seria o
 * historico inteiro dobrando de tamanho a cada mes.
 */
it('nao reimporta a compra que a fatura seguinte mostra na parcela seguinte', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create([
        'closing_day' => 5,
        'due_day' => 9,
        'last_four_digits' => '8291',
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(new ParsedInvoice(
        issuer: 'Nubank',
        dueDate: CarbonImmutable::parse('2026-07-09'),
        lastFourDigits: '8291',
        lines: [new ParsedInvoiceLine('MERCADOLIVRE*ABC', 'Mercado Livre', 7, 1, 2, 50_000)],
    ));

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)
        ->get(route('invoice-imports.review'))
        ->assertInertia(fn ($page) => $page
            ->where('candidates.0.status', 'matched')
            ->where('candidates.0.purchased_at', '2026-06-07'));

    $this->actingAs($user)->post(route('invoice-imports.confirm'), [
        'lines' => [['index' => 0, 'purchased_at' => '2026-06-07']],
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(new ParsedInvoice(
        issuer: 'Nubank',
        dueDate: CarbonImmutable::parse('2026-08-09'),
        lastFourDigits: '8291',
        lines: [new ParsedInvoiceLine('MERCADO LIVRE', 'Mercado Livre', 2, 2, 2, 50_000)],
    ));

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)
        ->get(route('invoice-imports.review'))
        ->assertInertia(fn ($page) => $page
            ->where('candidates.0.purchased_at', '2026-07-02')
            ->where('candidates.0.status', 'duplicate'));

    $this->actingAs($user)->post(route('invoice-imports.confirm'), [
        'lines' => [['index' => 0, 'purchased_at' => '2026-07-02']],
    ]);

    expect(Purchase::count())->toBe(1)
        ->and(Installment::count())->toBe(2);
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

it('recusa indices repetidos no mesmo payload de confirmacao', function (): void {
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
            'lines' => [
                ['index' => 0, 'purchased_at' => '2026-03-02'],
                ['index' => 0, 'purchased_at' => '2026-03-02'],
            ],
        ])
        ->assertSessionHasErrors('lines.0.index');

    expect(Purchase::count())->toBe(0);
});

it('nao grava candidato duplicado mesmo que o indice seja submetido diretamente', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    importedPurchase($card, '2026-03-02');

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)
        ->get(route('invoice-imports.review'))
        ->assertInertia(fn ($page) => $page->where('candidates.0.status', 'duplicate'));

    // Data diferente da compra ja existente: a checagem de duplicata na escrita
    // compara a data e por isso nao pega este caso, entao quem protege aqui e o
    // guard de isImportable(), que rejeita candidato ja marcado como duplicado.
    $this->actingAs($user)->post(route('invoice-imports.confirm'), [
        'lines' => [['index' => 0, 'purchased_at' => '2026-04-15']],
    ]);

    expect(Purchase::count())->toBe(1);
});

it('nao grava compra em cartao de outro usuario mesmo com a sessao manipulada', function (): void {
    $user = User::factory()->create();
    $foreignCard = Card::factory()->for(User::factory())->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    $this->actingAs($user)
        ->withSession(['invoice_import' => [
            'card_id' => $foreignCard->id,
            'issuer' => 'Nubank',
            'due_date' => '2026-05-10',
            'candidates' => [[
                'description' => 'MERCADOLIVRE*ABC',
                'merchant' => 'Mercado Livre',
                'total_amount_cents' => 129_900,
                'installments_count' => 10,
                'installment_number' => 3,
                'installment_amount_cents' => 12_990,
                'purchased_at' => '2026-03-02',
                'status' => 'matched',
            ]],
        ]])
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
        ])
        ->assertNotFound();

    expect(Purchase::count())->toBe(0);
});

it('limpa a sessao apos confirmar e nao reimporta a mesma sessao', function (): void {
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
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
        ])
        ->assertSessionMissing('invoice_import');

    $this->actingAs($user)
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
        ])
        ->assertRedirect(route('invoice-imports.create'));

    expect(Purchase::count())->toBe(1);
});

it('nao duplica a compra quando a data editada na revisao repete uma importacao anterior', function (): void {
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

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)
        ->get(route('invoice-imports.review'))
        ->assertInertia(fn ($page) => $page->where('candidates.0.status', 'duplicate'));

    $this->actingAs($user)->post(route('invoice-imports.confirm'), [
        'lines' => [['index' => 0, 'purchased_at' => '2026-02-20']],
    ]);

    expect(Purchase::count())->toBe(1);
});

/**
 * A revisao nao viu duplicata porque a compra do historico venceu em outro mes
 * que o inferido. Quem corrige a data para a da compra ja importada so colide
 * com ela na gravacao, e e la que a segunda copia precisa ser recusada.
 */
it('nao grava a compra quando a data corrigida na revisao cai no cronograma de uma ja importada', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    importedPurchase($card, '2026-04-02');

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)
        ->get(route('invoice-imports.review'))
        ->assertInertia(fn ($page) => $page->where('candidates.0.status', 'matched'));

    $this->actingAs($user)->post(route('invoice-imports.confirm'), [
        'lines' => [['index' => 0, 'purchased_at' => '2026-04-02']],
    ]);

    expect(Purchase::count())->toBe(1);
});

it('entrega ao extrator a senha informada no formulario', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    $extractor = new RecordingExtractor;
    app()->instance(ExtractsInvoiceText::class, $extractor);
    fakeParserFor(invoiceWithOneLine());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
        'password' => 'senha-da-fatura',
    ])->assertRedirect(route('invoice-imports.review'));

    expect($extractor->password)->toBe('senha-da-fatura');
});

it('nao guarda o pdf enviado em lugar nenhum', function (): void {
    Storage::fake('local');
    Storage::fake('public');

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

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('importa as duas linhas identicas da mesma fatura como compras distintas', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithTwoIdenticalLines());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)
        ->get(route('invoice-imports.review'))
        ->assertInertia(fn ($page) => $page
            ->has('candidates', 2)
            ->where('candidates.0.status', 'matched')
            ->where('candidates.1.status', 'matched'));

    $this->actingAs($user)->post(route('invoice-imports.confirm'), [
        'lines' => [
            ['index' => 0, 'purchased_at' => '2026-03-02'],
            ['index' => 1, 'purchased_at' => '2026-03-02'],
        ],
    ])->assertSessionHas('inertia.flash_data.toast.message', '2 compras importadas.');

    expect(Purchase::count())->toBe(2);
});

it('nao reimporta a fatura de duas linhas identicas ja importada', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithTwoIdenticalLines());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);
    $this->actingAs($user)->post(route('invoice-imports.confirm'), [
        'lines' => [
            ['index' => 0, 'purchased_at' => '2026-03-02'],
            ['index' => 1, 'purchased_at' => '2026-03-02'],
        ],
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithTwoIdenticalLines());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)
        ->get(route('invoice-imports.review'))
        ->assertInertia(fn ($page) => $page
            ->where('candidates.0.status', 'duplicate')
            ->where('candidates.1.status', 'duplicate'));

    $this->actingAs($user)->post(route('invoice-imports.confirm'), [
        'lines' => [
            ['index' => 0, 'purchased_at' => '2026-03-02'],
            ['index' => 1, 'purchased_at' => '2026-03-02'],
        ],
    ]);

    expect(Purchase::count())->toBe(2);
});

it('avisa quando nenhuma das compras marcadas foi importada', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    importedPurchase($card, '2026-03-02');

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(invoiceWithOneLine());

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)->post(route('invoice-imports.confirm'), [
        'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
    ])->assertSessionHas(
        'inertia.flash_data.toast.message',
        'Nenhuma compra foi importada: ela já constava no histórico ou não pôde ser lida.',
    );

    expect(Purchase::count())->toBe(1);
});

it('anuncia sucesso quando todas as compras marcadas foram importadas', function (): void {
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
    ])
        ->assertSessionHas('inertia.flash_data.toast.message', '1 compra importada.')
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');
});

it('corrige a data de um candidato divergente e importa com ela', function (): void {
    $user = User::factory()->create();
    Card::factory()->for($user)->create([
        'closing_day' => 30,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    fakeExtractorReturning('texto qualquer');
    fakeParserFor(new ParsedInvoice(
        issuer: 'Nubank',
        dueDate: CarbonImmutable::parse('2026-04-10'),
        lastFourDigits: '8291',
        lines: [new ParsedInvoiceLine('NOTEBOOK', 'Loja', 31, 1, 3, 50_000)],
    ));

    $this->actingAs($user)->post(route('invoice-imports.store'), [
        'invoice' => UploadedFile::fake()->create('fatura.pdf', 40, 'application/pdf'),
    ]);

    $this->actingAs($user)
        ->get(route('invoice-imports.review'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('candidates.0.status', 'divergent')
            ->where('candidates.0.purchased_at', '2026-02-28'));

    $this->actingAs($user)
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-15']],
        ])
        ->assertRedirect(route('history.index'));

    $purchase = Purchase::sole();

    expect($purchase->purchased_at->toDateString())->toBe('2026-03-15')
        ->and($purchase->installments_count)->toBe(3)
        ->and($purchase->installments()->where('number', 1)->value('due_date'))
        ->toEqual(CarbonImmutable::parse('2026-04-10'));
});

it('recusa data de compra no futuro', function (): void {
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
            'lines' => [[
                'index' => 0,
                'purchased_at' => CarbonImmutable::now()->addDay()->toDateString(),
            ]],
        ])
        ->assertSessionHasErrors('lines.0.purchased_at');

    expect(Purchase::count())->toBe(0);
});

it('recusa candidato com descricao maior que o permitido', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    $this->actingAs($user)
        ->withSession(['invoice_import' => [
            'card_id' => $card->id,
            'issuer' => 'Nubank',
            'due_date' => '2026-05-10',
            'candidates' => [[
                'description' => str_repeat('A', 400),
                'merchant' => 'Mercado Livre',
                'total_amount_cents' => 129_900,
                'installments_count' => 10,
                'installment_number' => 3,
                'installment_amount_cents' => 12_990,
                'purchased_at' => '2026-03-02',
                'status' => 'matched',
            ]],
        ]])
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
        ])
        ->assertSessionHasErrors('lines.0.index');

    expect(Purchase::count())->toBe(0);
});

it('recusa candidato com parcelas alem do limite do lancamento manual', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    $this->actingAs($user)
        ->withSession(['invoice_import' => [
            'card_id' => $card->id,
            'issuer' => 'Nubank',
            'due_date' => '2026-05-10',
            'candidates' => [[
                'description' => 'MERCADOLIVRE*ABC',
                'merchant' => 'Mercado Livre',
                'total_amount_cents' => 129_900,
                'installments_count' => 200,
                'installment_number' => 3,
                'installment_amount_cents' => 12_990,
                'purchased_at' => '2026-03-02',
                'status' => 'matched',
            ]],
        ]])
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
        ])
        ->assertSessionHasErrors('lines.0.index');

    expect(Purchase::count())->toBe(0);
});

it('recusa candidato com valor total zerado', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    $this->actingAs($user)
        ->withSession(['invoice_import' => [
            'card_id' => $card->id,
            'issuer' => 'Nubank',
            'due_date' => '2026-05-10',
            'candidates' => [[
                'description' => 'MERCADOLIVRE*ABC',
                'merchant' => 'Mercado Livre',
                'total_amount_cents' => 0,
                'installments_count' => 10,
                'installment_number' => 3,
                'installment_amount_cents' => 0,
                'purchased_at' => '2026-03-02',
                'status' => 'matched',
            ]],
        ]])
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
        ])
        ->assertSessionHasErrors('lines.0.index');

    expect(Purchase::count())->toBe(0);
});

it('sobrevive a uma sessao de importacao com forma desconhecida', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create();

    $this->actingAs($user)
        ->withSession(['invoice_import' => [
            'card_id' => $card->id,
            'issuer' => 'Nubank',
            'due_date' => '2026-05-10',
            'candidates' => 'de outra versao',
        ]])
        ->get(route('invoice-imports.review'))
        ->assertRedirect(route('invoice-imports.create'));

    $this->actingAs($user)
        ->withSession(['invoice_import' => ['formato' => 'de outra versao']])
        ->get(route('invoice-imports.review'))
        ->assertRedirect(route('invoice-imports.create'));

    $this->actingAs($user)
        ->withSession(['invoice_import' => ['formato' => 'de outra versao']])
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
        ])
        ->assertRedirect(route('invoice-imports.create'))
        ->assertSessionHas(
            'inertia.flash_data.toast.message',
            'A revisão da fatura expirou. Envie o PDF novamente.',
        );

    expect(Purchase::count())->toBe(0);
});

it('nao quebra quando a sessao de importacao nao tem candidato nenhum', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    $this->actingAs($user)
        ->withSession(['invoice_import' => [
            'card_id' => $card->id,
            'issuer' => 'Nubank',
            'due_date' => '2026-05-10',
            'candidates' => [],
        ]])
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
        ])
        ->assertRedirect(route('invoice-imports.create'))
        ->assertSessionHas(
            'inertia.flash_data.toast.message',
            'A revisão da fatura expirou. Envie o PDF novamente.',
        );

    expect(Purchase::count())->toBe(0);
});

it('nao grava candidato cujo status guardado na sessao nao e reconhecido', function (): void {
    $user = User::factory()->create();
    $card = Card::factory()->for($user)->create([
        'closing_day' => 3,
        'due_day' => 10,
        'last_four_digits' => '8291',
    ]);

    $this->actingAs($user)
        ->withSession(['invoice_import' => [
            'card_id' => $card->id,
            'issuer' => 'Nubank',
            'due_date' => '2026-05-10',
            'candidates' => [[
                'description' => 'MERCADOLIVRE*ABC',
                'merchant' => 'Mercado Livre',
                'total_amount_cents' => 129_900,
                'installments_count' => 10,
                'installment_number' => 3,
                'installment_amount_cents' => 12_990,
                'purchased_at' => '2026-03-02',
                'status' => 'de outra versao',
            ]],
        ]])
        ->post(route('invoice-imports.confirm'), [
            'lines' => [['index' => 0, 'purchased_at' => '2026-03-02']],
        ])
        ->assertRedirect(route('history.index'))
        ->assertSessionHas(
            'inertia.flash_data.toast.message',
            'Nenhuma compra foi importada: ela já constava no histórico ou não pôde ser lida.',
        );

    expect(Purchase::count())->toBe(0);
});
