<?php

namespace App\Http\Controllers;

use App\Actions\CalculateFirstDueDate;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class InvoiceImportController extends Controller
{
    public function create(Request $request, ResolveInvoiceParser $resolveParser): Response|RedirectResponse
    {
        if (! $resolveParser->hasParsers()) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => 'A importação de fatura ainda não está disponível. Continue lançando suas compras manualmente.',
            ]);

            return to_route('dashboard');
        }

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
        $import = $this->importFromSession($request);

        if ($import === null) {
            $request->session()->forget('invoice_import');

            return to_route('invoice-imports.create');
        }

        $card = Card::query()
            ->where('user_id', $request->user()->id)
            ->find($import['card_id']);

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
        CalculateFirstDueDate $calculateFirstDueDate,
    ): RedirectResponse {
        $import = $this->importFromSession($request);

        if ($import === null) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => 'A revisão da fatura expirou. Envie o PDF novamente.',
            ]);

            return to_route('invoice-imports.create');
        }

        $card = Card::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($import['card_id']);

        /** @var array<int, array{index: int, purchased_at: string}> $lines */
        $lines = $request->validated('lines');

        $imported = 0;

        DB::transaction(function () use ($lines, $import, $card, $generateInstallments, $purchaseAlreadyExists, $calculateFirstDueDate, &$imported): void {
            $alreadyInHistory = $this->fingerprintsAlreadyInHistory(
                $lines,
                $import['candidates'],
                $card,
                $purchaseAlreadyExists,
                $calculateFirstDueDate,
            );

            foreach ($lines as $line) {
                $candidate = InvoiceCandidate::fromArray($import['candidates'][$line['index']]);

                if (! $candidate->status->isImportable()) {
                    continue;
                }

                $purchasedAt = CarbonImmutable::parse($line['purchased_at']);
                $firstDueDate = $this->firstDueDateFor($calculateFirstDueDate, $card, $purchasedAt);

                if ($alreadyInHistory[$this->fingerprint($candidate, $firstDueDate)] ?? false) {
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

        Inertia::flash('toast', $this->importToast($imported, count($lines) - $imported));

        return to_route('history.index');
    }

    /**
     * A duplicata que interessa e a fatura ja importada antes, nao a linha que
     * se repete dentro do lote: duas assinaturas iguais cobradas no mesmo dia
     * sao duas compras reais. Perguntar dentro do laco consultaria uma tabela
     * que o proprio laco esta gravando, e a segunda linha identica seria
     * descartada por causa da primeira. A resposta e colhida aqui, contra o
     * estado anterior a qualquer escrita do lote.
     *
     * A assinatura usa o cronograma da data final vinda da revisao, nao o
     * inferido, porque e a data final que sera persistida e e dela que as
     * parcelas gravadas nascem.
     *
     * @param  array<int, array{index: int, purchased_at: string}>  $lines
     * @param  non-empty-array<int, array<string, mixed>>  $candidates
     * @return array<string, bool>
     */
    private function fingerprintsAlreadyInHistory(
        array $lines,
        array $candidates,
        Card $card,
        PurchaseAlreadyExists $purchaseAlreadyExists,
        CalculateFirstDueDate $calculateFirstDueDate,
    ): array {
        $existing = [];

        foreach ($lines as $line) {
            $candidate = InvoiceCandidate::fromArray($candidates[$line['index']]);
            $purchasedAt = CarbonImmutable::parse($line['purchased_at']);
            $firstDueDate = $this->firstDueDateFor($calculateFirstDueDate, $card, $purchasedAt);
            $fingerprint = $this->fingerprint($candidate, $firstDueDate);

            if (array_key_exists($fingerprint, $existing)) {
                continue;
            }

            $existing[$fingerprint] = $purchaseAlreadyExists->handle(
                $card,
                $candidate->totalAmountCents,
                $candidate->installmentsCount,
                $firstDueDate,
            );
        }

        return $existing;
    }

    /**
     * O vencimento da primeira parcela que a compra tera depois de gravada:
     * GenerateInstallments monta o cronograma a partir da data confirmada, e a
     * deteccao de duplicata precisa perguntar pelo mesmo cronograma que a
     * escrita vai produzir.
     */
    private function firstDueDateFor(
        CalculateFirstDueDate $calculateFirstDueDate,
        Card $card,
        CarbonImmutable $purchasedAt,
    ): CarbonImmutable {
        return $calculateFirstDueDate->handle($purchasedAt, $card->closing_day, $card->due_day);
    }

    /**
     * Chave local do mapa de compras preexistentes. O cartao fica de fora
     * porque uma confirmacao inteira grava num unico cartao.
     */
    private function fingerprint(InvoiceCandidate $candidate, CarbonImmutable $firstDueDate): string
    {
        return implode('|', [
            $candidate->totalAmountCents,
            $candidate->installmentsCount,
            $firstDueDate->toDateString(),
        ]);
    }

    /**
     * O usuario marcou N linhas e o botao prometeu N compras. Quando a
     * gravacao pula alguma, a mensagem precisa dizer isso em vez de comemorar
     * um numero que nao aconteceu.
     *
     * Sao dois os motivos de pulo — a compra ja existia ou o candidato nao
     * chegou em estado que possa ser gravado — e a mensagem cobre os dois numa
     * disjuncao em vez de afirmar so o primeiro. Separar os motivos custaria um
     * contador e uma combinatoria de frases para um caso que so aparece com a
     * sessao adulterada.
     *
     * @return array{type: string, message: string}
     */
    private function importToast(int $imported, int $skipped): array
    {
        $skippedText = $skipped === 1
            ? 'ela já constava no histórico ou não pôde ser lida'
            : 'elas já constavam no histórico ou não puderam ser lidas';

        if ($imported === 0) {
            return [
                'type' => 'info',
                'message' => "Nenhuma compra foi importada: {$skippedText}.",
            ];
        }

        $importedText = $imported === 1
            ? '1 compra importada.'
            : "{$imported} compras importadas.";

        if ($skipped === 0) {
            return ['type' => 'success', 'message' => $importedText];
        }

        $ignoredText = $skipped === 1
            ? '1 foi ignorada'
            : "{$skipped} foram ignoradas";

        return [
            'type' => 'info',
            'message' => "{$importedText} {$ignoredText}: {$skippedText}.",
        ];
    }

    /**
     * A sessao dura duas horas e sobrevive a deploy, entao a forma guardada
     * pode ser de uma versao anterior do app.
     *
     * store() so grava a sessao com pelo menos uma linha parcelada, entao
     * uma lista de candidatos vazia nao e um lote vazio: e uma sessao que
     * nao da mais para usar. Um candidato sem o vencimento da primeira
     * parcela e o mesmo caso: sem essa ancora a deteccao de duplicata nao
     * funciona, e a revisao so teria a oferecer uma lista que a gravacao
     * inteira recusaria em silencio. Reconhecer os dois aqui, no unico ponto
     * que le a sessao, e o que impede review() e confirm() de discordarem
     * sobre quais indices existem e o que da ao usuario a instrucao de
     * enviar o PDF de novo em vez de um lote que nao grava nada.
     *
     * @return array{card_id: int, issuer: string, due_date: string, candidates: non-empty-array<int, array<string, mixed>>}|null
     */
    private function importFromSession(Request $request): ?array
    {
        $import = $request->session()->get('invoice_import');

        if (! is_array($import)
            || ! is_numeric($import['card_id'] ?? null)
            || ! is_string($import['issuer'] ?? null)
            || ! is_string($import['due_date'] ?? null)
            || ! is_array($import['candidates'] ?? null)) {
            return null;
        }

        $candidates = array_values(array_filter($import['candidates'], 'is_array'));

        if ($candidates === [] || ! $this->everyCandidateIsAnchored($candidates)) {
            return null;
        }

        return [
            'card_id' => (int) $import['card_id'],
            'issuer' => $import['issuer'],
            'due_date' => $import['due_date'],
            'candidates' => $candidates,
        ];
    }

    /**
     * O vencimento da primeira parcela e a ancora da deteccao de duplicata.
     * A sessao inteira e gravada de uma vez, entao ou todos os candidatos o
     * trazem ou nenhum traz — e nenhum e uma sessao de outra versao do app.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function everyCandidateIsAnchored(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate['first_due_date'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A extracao e a fronteira com o processo externo: e o unico ponto onde
     * a falha vira mensagem de validacao em vez de subir.
     *
     * So a mensagem da excecao vai para o log. Uma fatura carrega nome, CPF,
     * numero do cartao e todas as compras do usuario: nem o texto extraido,
     * nem o caminho do arquivo, nem a senha podem sair daqui.
     */
    private function extractText(ExtractsInvoiceText $extractor, AnalyzeInvoiceRequest $request): string
    {
        try {
            return $extractor->extract(
                $request->file('invoice')->getRealPath(),
                $request->string('password')->value(),
            );
        } catch (RuntimeException $exception) {
            Log::warning('Falha ao extrair o texto do PDF da fatura.', [
                'exception' => $exception->getMessage(),
            ]);

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
