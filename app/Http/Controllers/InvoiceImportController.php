<?php

namespace App\Http\Controllers;

use App\Actions\GenerateInstallments;
use App\Http\Requests\AnalyzeInvoiceRequest;
use App\Http\Requests\ConfirmInvoiceImportRequest;
use App\Invoices\BuildInvoiceCandidates;
use App\Invoices\ExtractsInvoiceText;
use App\Invoices\InvoiceCandidate;
use App\Invoices\ParsedInvoice;
use App\Invoices\PurchaseAlreadyExists;
use App\Invoices\ResolveInvoiceParser;
use App\Invoices\UnreadableInvoiceException;
use App\Invoices\UnsupportedInvoiceException;
use App\Models\Card;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class InvoiceImportController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $cards = $this->cardOptions($request);

        if ($cards->isEmpty()) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => 'Cadastre um cartão antes de importar uma fatura.',
            ]);

            return to_route('cards.create');
        }

        return Inertia::render('invoices/import', ['cards' => $cards]);
    }

    public function store(
        AnalyzeInvoiceRequest $request,
        ExtractsInvoiceText $extractor,
        ResolveInvoiceParser $resolveParser,
        BuildInvoiceCandidates $buildCandidates,
    ): RedirectResponse {
        $text = $this->extractText($extractor, $request);

        try {
            $invoice = $resolveParser->handle($text)->parse($text);
        } catch (UnsupportedInvoiceException|UnreadableInvoiceException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }

        if ($invoice->lines === []) {
            throw ValidationException::withMessages([
                'invoice' => 'Nenhuma compra parcelada encontrada nesta fatura.',
            ]);
        }

        $card = $this->resolveCard($request, $invoice);
        $candidates = $buildCandidates->handle($invoice, $card);

        $request->session()->put('invoice_import', [
            'card_id' => $card->id,
            'issuer' => $invoice->issuer,
            'due_date' => $invoice->dueDate->toDateString(),
            'candidates' => array_map(
                fn (InvoiceCandidate $candidate): array => $candidate->toArray(),
                $candidates,
            ),
        ]);

        return to_route('invoice-imports.review');
    }

    public function review(Request $request): Response|RedirectResponse
    {
        $import = $request->session()->get('invoice_import');

        if (! is_array($import)) {
            return to_route('invoice-imports.create');
        }

        $card = Card::query()
            ->where('user_id', $request->user()->id)
            ->find((int) $import['card_id']);

        if ($card === null) {
            $request->session()->forget('invoice_import');

            return to_route('invoice-imports.create');
        }

        return Inertia::render('invoices/review', [
            'issuer' => $import['issuer'],
            'due_date' => $import['due_date'],
            'card' => [
                'id' => $card->id,
                'name' => $card->name,
                'last_four_digits' => $card->last_four_digits,
            ],
            'candidates' => $import['candidates'],
        ]);
    }

    public function confirm(
        ConfirmInvoiceImportRequest $request,
        GenerateInstallments $generateInstallments,
        PurchaseAlreadyExists $purchaseAlreadyExists,
    ): RedirectResponse {
        $import = $request->session()->get('invoice_import');

        if (! is_array($import)) {
            return to_route('invoice-imports.create');
        }

        $card = Card::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail((int) $import['card_id']);

        /** @var array<int, array{index: int, purchased_at: string}> $lines */
        $lines = $request->validated('lines');

        $imported = 0;

        DB::transaction(function () use ($lines, $import, $card, $generateInstallments, $purchaseAlreadyExists, &$imported): void {
            foreach ($lines as $line) {
                $candidate = InvoiceCandidate::fromArray($import['candidates'][$line['index']]);

                if (! $candidate->status->isImportable()) {
                    continue;
                }

                $purchasedAt = CarbonImmutable::parse($line['purchased_at']);

                if ($purchaseAlreadyExists->handle(
                    $card,
                    $candidate->totalAmountCents,
                    $candidate->installmentsCount,
                    $purchasedAt,
                )) {
                    continue;
                }

                $purchase = Purchase::create([
                    'card_id' => $card->id,
                    'description' => $candidate->description,
                    'merchant' => $candidate->merchant,
                    'total_amount_cents' => $candidate->totalAmountCents,
                    'installments_count' => $candidate->installmentsCount,
                    'purchased_at' => $purchasedAt,
                ]);

                $generateInstallments->handle($purchase->setRelation('card', $card));

                $imported++;
            }
        });

        $request->session()->forget('invoice_import');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $imported === 1
                ? '1 compra importada.'
                : "{$imported} compras importadas.",
        ]);

        return to_route('history.index');
    }

    /**
     * A extracao e a fronteira com o processo externo: e o unico ponto onde
     * a falha vira mensagem de validacao em vez de subir.
     */
    private function extractText(ExtractsInvoiceText $extractor, AnalyzeInvoiceRequest $request): string
    {
        try {
            return $extractor->extract(
                $request->file('invoice')->getRealPath(),
                $request->string('password')->value(),
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'invoice' => 'Não foi possível ler o PDF. Se ele tiver senha, informe-a abaixo.',
            ]);
        }
    }

    private function resolveCard(AnalyzeInvoiceRequest $request, ParsedInvoice $invoice): Card
    {
        if ($request->filled('card_id')) {
            return Card::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($request->integer('card_id'));
        }

        $card = Card::query()
            ->where('user_id', $request->user()->id)
            ->where('last_four_digits', $invoice->lastFourDigits)
            ->first();

        if ($card === null) {
            throw ValidationException::withMessages([
                'card_id' => 'Não identificamos o cartão desta fatura. Selecione qual é.',
            ]);
        }

        return $card;
    }

    /**
     * @return Collection<int, array{id: int, name: string, last_four_digits: string}>
     */
    private function cardOptions(Request $request): Collection
    {
        return $request->user()->cards()->orderBy('name')->get()->map(
            fn (Card $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'last_four_digits' => $card->last_four_digits,
            ],
        );
    }
}
