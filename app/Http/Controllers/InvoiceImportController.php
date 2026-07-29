<?php

namespace App\Http\Controllers;

use App\Actions\CalculateFirstDueDate;
use App\Actions\GenerateInstallments;
use App\Actions\WriteCashPurchases;
use App\Http\Requests\AnalyzeInvoiceRequest;
use App\Http\Requests\ConfirmInvoiceImportRequest;
use App\Invoices\BuildInvoiceCandidates;
use App\Invoices\ExtractorUnavailableException;
use App\Invoices\ExtractsInvoiceText;
use App\Invoices\InvoiceCandidate;
use App\Invoices\ParsedCashLine;
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
    /**
     * O que sobra de um PDF sem camada de texto: o pdftotext devolve uma
     * quebra de pagina por pagina e mais nada. O \f nao entra na lista
     * padrao do trim().
     */
    private const BLANK_CHARS = " \t\n\r\0\x0B\f";

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

        $this->refuseImageOnlyInvoice($text);

        try {
            $invoice = $resolveParser->handle($text)->parse($text);
        } catch (UnsupportedInvoiceException|UnreadableInvoiceException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }

        if ($invoice->lines === [] && $invoice->cashLines === []) {
            throw ValidationException::withMessages([
                'invoice' => 'Nenhuma compra encontrada nesta fatura.',
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
            'cash_lines' => array_map(
                fn (ParsedCashLine $line): array => [
                    'description' => $line->description,
                    'day' => $line->transactionDay,
                    'month' => $line->transactionMonth,
                    'amount_cents' => $line->amountCents,
                ],
                $invoice->cashLines,
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
            'cash_lines_count' => count($import['cash_lines']),
        ]);
    }

    public function confirm(
        ConfirmInvoiceImportRequest $request,
        GenerateInstallments $generateInstallments,
        PurchaseAlreadyExists $purchaseAlreadyExists,
        CalculateFirstDueDate $calculateFirstDueDate,
        WriteCashPurchases $writeCashPurchases,
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
        $cashImported = 0;

        DB::transaction(function () use ($lines, $import, $card, $generateInstallments, $purchaseAlreadyExists, $calculateFirstDueDate, $writeCashPurchases, &$imported, &$cashImported): void {
            $alreadyInHistory = $this->fingerprintsAlreadyInHistory(
                $lines,
                $import['candidates'],
                $card,
                $purchaseAlreadyExists,
                $calculateFirstDueDate,
            );

            foreach ($lines as $line) {
                $candidate = InvoiceCandidate::fromArray($import['candidates'][$line['index']] ?? []);

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

            $cashImported = $writeCashPurchases->handle(
                $import['cash_lines'],
                $card,
                CarbonImmutable::parse($import['due_date']),
            );
        });

        $request->session()->forget('invoice_import');

        Inertia::flash('toast', $this->importToast($imported, count($lines) - $imported, $cashImported));

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
     * O `?? []` no acesso a $candidates[$indice] cobre a fatura sem nenhuma
     * parcelada: a validacao do formulario permite indice 0 mesmo com a
     * lista vazia, para nao competir com a mensagem de sessao expirada de
     * confirm(). Sem o fallback, esse indice inexistente vira erro fatal em
     * vez de um candidato vazio que isImportable() descarta.
     *
     * @param  array<int, array{index: int, purchased_at: string}>  $lines
     * @param  array<int, array<string, mixed>>  $candidates
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
            $candidate = InvoiceCandidate::fromArray($candidates[$line['index']] ?? []);
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
     * O usuario marcou N linhas parceladas e o botao prometeu N compras.
     * Quando a gravacao pula alguma, a mensagem precisa dizer isso em vez de
     * comemorar um numero que nao aconteceu.
     *
     * Sao dois os motivos de pulo — a compra ja existia ou o candidato nao
     * chegou em estado que possa ser gravado — e a mensagem cobre os dois numa
     * disjuncao em vez de afirmar so o primeiro. Separar os motivos custaria um
     * contador e uma combinatoria de frases para um caso que so aparece com a
     * sessao adulterada.
     *
     * As compras a vista tem contagem propria e sempre certa: WriteCashPurchases
     * usa insertOrIgnore, entao nao ha estado intermediario para relatar como
     * ha para as parceladas. Elas entram na mensagem sempre que $cashImported
     * for maior que zero — inclusive quando nenhuma parcelada foi importada,
     * porque "nenhuma compra foi importada" seria mentira depois de gravar
     * compras a vista, e o usuario nao teria como saber que a fatura so-a-vista
     * funcionou.
     *
     * @return array{type: string, message: string}
     */
    private function importToast(int $imported, int $skipped, int $cashImported): array
    {
        $skippedText = $skipped === 1
            ? 'ela já constava no histórico ou não pôde ser lida'
            : 'elas já constavam no histórico ou não puderam ser lidas';

        if ($imported === 0 && $cashImported === 0) {
            return [
                'type' => 'info',
                'message' => "Nenhuma compra foi importada: {$skippedText}.",
            ];
        }

        $cashText = match (true) {
            $cashImported === 0 => null,
            $cashImported === 1 => '1 compra à vista salva para análise de gastos.',
            default => "{$cashImported} compras à vista salvas para análise de gastos.",
        };

        if ($imported === 0) {
            if ($skipped === 0) {
                return ['type' => 'success', 'message' => $cashText];
            }

            return [
                'type' => 'info',
                'message' => "{$cashText} Nenhuma parcelada foi importada: {$skippedText}.",
            ];
        }

        $importedText = $imported === 1
            ? '1 compra importada.'
            : "{$imported} compras importadas.";

        $summary = $cashText === null ? $importedText : "{$importedText} {$cashText}";

        if ($skipped === 0) {
            return ['type' => 'success', 'message' => $summary];
        }

        $ignoredText = $skipped === 1
            ? '1 foi ignorada'
            : "{$skipped} foram ignoradas";

        return [
            'type' => 'info',
            'message' => "{$summary} {$ignoredText}: {$skippedText}.",
        ];
    }

    /**
     * A sessao dura duas horas e sobrevive a deploy, entao a forma guardada
     * pode ser de uma versao anterior do app.
     *
     * Uma fatura pode nao ter nenhuma parcelada e ainda assim ter dezenas de
     * compras a vista para gravar, entao lista de candidatos vazia deixou de
     * ser sinal de sessao imprestavel. Quem invalida a sessao e um candidato
     * sem o vencimento da primeira parcela: sem essa ancora a deteccao de
     * duplicata nao funciona, e a revisao so teria a oferecer uma lista que a
     * gravacao inteira recusaria em silencio. Reconhecer isso aqui, no unico
     * ponto que le a sessao, e o que impede review() e confirm() de
     * discordarem sobre quais indices existem e o que da ao usuario a
     * instrucao de enviar o PDF de novo em vez de um lote que nao grava nada.
     *
     * @return array{card_id: int, issuer: string, due_date: string, candidates: array<int, array<string, mixed>>, cash_lines: array<int, array{description: string, day: int, month: int, amount_cents: int}>}|null
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

        if (! $this->everyCandidateIsAnchored($candidates)) {
            return null;
        }

        $cashLines = is_array($import['cash_lines'] ?? null)
            ? array_values(array_filter($import['cash_lines'], $this->isCashLine(...)))
            : [];

        return [
            'card_id' => (int) $import['card_id'],
            'issuer' => $import['issuer'],
            'due_date' => $import['due_date'],
            'candidates' => $candidates,
            'cash_lines' => $cashLines,
        ];
    }

    /**
     * A sessao dura duas horas e sobrevive a deploy: a forma guardada pode
     * ser de uma versao anterior do app. Uma linha a vista irreconhecivel e
     * descartada em silencio, e nao invalida a sessao inteira — ao contrario
     * do candidato parcelado, cuja forma estranha viraria compra errada. Aqui
     * o pior caso e um gasto de fora do grafico.
     */
    private function isCashLine(mixed $line): bool
    {
        return is_array($line)
            && is_string($line['description'] ?? null)
            && is_int($line['day'] ?? null)
            && is_int($line['month'] ?? null)
            && is_int($line['amount_cents'] ?? null);
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
        } catch (ExtractorUnavailableException $exception) {
            Log::warning('O extrator de PDF nao esta acessivel.', [
                'exception' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'invoice' => 'O leitor de PDF não está instalado ou não está acessível no servidor. Não é problema do seu arquivo nem da senha.',
            ]);
        } catch (RuntimeException $exception) {
            Log::warning('Falha ao extrair o texto do PDF da fatura.', [
                'exception' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'invoice' => 'Não foi possível ler o PDF. Se ele tiver senha, informe-a abaixo.',
            ]);
        }
    }

    /**
     * Um PDF que so contem imagem nao chega a ser lido: nenhum emissor casa
     * porque nao ha uma letra para casar. Sem esta parada o usuario receberia
     * "emissor nao reconhecido", que o manda procurar defeito no banco errado
     * — o arquivo e que nao tem texto.
     *
     * @throws ValidationException
     */
    private function refuseImageOnlyInvoice(string $text): void
    {
        if (trim($text, self::BLANK_CHARS) !== '') {
            return;
        }

        throw ValidationException::withMessages([
            'invoice' => 'Este PDF não tem texto, só imagem — é o caso de uma digitalização ou de uma página impressa pelo navegador. Baixe o arquivo original da fatura no app ou no site do banco.',
        ]);
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
