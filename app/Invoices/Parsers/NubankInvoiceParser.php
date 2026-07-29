<?php

namespace App\Invoices\Parsers;

use App\Invoices\InvoiceParser;
use App\Invoices\ParsedCashLine;
use App\Invoices\ParsedInvoice;
use App\Invoices\ParsedInvoiceLine;
use App\Invoices\UnreadableInvoiceException;
use Carbon\CarbonImmutable;

class NubankInvoiceParser implements InvoiceParser
{
    private const ISSUER = 'Nubank';

    private const SIGNATURE = 'Nu Pagamentos';

    /**
     * @var array<string, int>
     */
    private const MONTHS = [
        'JAN' => 1,
        'FEV' => 2,
        'MAR' => 3,
        'ABR' => 4,
        'MAI' => 5,
        'JUN' => 6,
        'JUL' => 7,
        'AGO' => 8,
        'SET' => 9,
        'OUT' => 10,
        'NOV' => 11,
        'DEZ' => 12,
    ];

    private const DUE_DATE_PATTERN = '/Data de vencimento:\s*(\d{2})\s+(JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\s+(\d{4})/u';

    /**
     * Uma linha parcelada tem dia, mes, um numero de cartao virtual opcional
     * (a fatura Nubank lista varios), descricao, o sufixo "- Parcela N/M" e
     * o valor. O sufixo e obrigatorio: e o que distingue uma compra
     * parcelada de uma transacao avulsa ou da secao Pagamentos e
     * Financiamentos, que nunca o carrega.
     */
    private const INSTALLMENT_LINE_PATTERN = '/^\s*(\d{2})\s+(JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\s+(?:••••\s*\d{4}\s+)?(.+?)\s+-\s+Parcela\s+(\d+)\/(\d+)\s+(−)?R\$\s*([\d.,]+)\s*$/u';

    /**
     * A linha a vista e a mesma da parcelada sem o sufixo "- Parcela N/M".
     * A distincao nao pode ser feita so por este padrao, que casaria tambem a
     * linha parcelada engolindo o sufixo dentro da descricao: por isso o
     * extrator testa o padrao parcelado primeiro e pula quem casar.
     */
    private const CASH_LINE_PATTERN = '/^\s*(\d{2})\s+(JAN|FEV|MAR|ABR|MAI|JUN|JUL|AGO|SET|OUT|NOV|DEZ)\s+(?:••••\s*\d{4}\s+)?(.+?)\s+(−)?R\$\s*([\d.,]+)\s*$/u';

    public function supports(string $text): bool
    {
        return str_contains($text, self::SIGNATURE);
    }

    /**
     * @throws UnreadableInvoiceException
     */
    public function parse(string $text): ParsedInvoice
    {
        return new ParsedInvoice(
            issuer: self::ISSUER,
            dueDate: $this->extractDueDate($text),
            lastFourDigits: null,
            lines: $this->extractLines($text),
            cashLines: $this->extractCashLines($text),
        );
    }

    /**
     * @throws UnreadableInvoiceException
     */
    private function extractDueDate(string $text): CarbonImmutable
    {
        if (! preg_match(self::DUE_DATE_PATTERN, $text, $matches)) {
            throw new UnreadableInvoiceException(
                'Não foi possível ler a data de vencimento desta fatura.',
            );
        }

        return CarbonImmutable::create(
            (int) $matches[3],
            self::MONTHS[$matches[2]],
            (int) $matches[1],
        );
    }

    /**
     * @return array<int, ParsedInvoiceLine>
     */
    private function extractLines(string $text): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $rawLine) {
            if (! preg_match(self::INSTALLMENT_LINE_PATTERN, $rawLine, $matches)) {
                continue;
            }

            if ($matches[6] !== '') {
                continue;
            }

            $lines[] = new ParsedInvoiceLine(
                description: trim($matches[3]),
                merchant: null,
                transactionDay: (int) $matches[1],
                installmentNumber: (int) $matches[4],
                installmentsCount: (int) $matches[5],
                amountCents: $this->parseAmountCents($matches[7]),
            );
        }

        return $lines;
    }

    private function parseAmountCents(string $raw): int
    {
        $normalized = str_replace(['.', ','], ['', '.'], $raw);

        return (int) round(((float) $normalized) * 100);
    }

    /**
     * O sinal de menos aqui e o U+2212 da fatura, nao o hifen do teclado: ele
     * marca credito — pagamento recebido, estorno — que nao e gasto.
     *
     * O valor zero tem dono conhecido: a linha "Saldo restante da fatura
     * anterior", que casa o padrao de transacao mas nao e compra. Descartar
     * aqui, e nao no construtor, e o que impede que ela derrube a importacao
     * inteira de uma fatura legitima.
     *
     * @return array<int, ParsedCashLine>
     */
    private function extractCashLines(string $text): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $rawLine) {
            if (preg_match(self::INSTALLMENT_LINE_PATTERN, $rawLine)) {
                continue;
            }

            if (! preg_match(self::CASH_LINE_PATTERN, $rawLine, $matches)) {
                continue;
            }

            if ($matches[4] !== '') {
                continue;
            }

            $amountCents = $this->parseAmountCents($matches[5]);

            if ($amountCents < 1) {
                continue;
            }

            $lines[] = new ParsedCashLine(
                description: trim($matches[3]),
                transactionDay: (int) $matches[1],
                transactionMonth: self::MONTHS[$matches[2]],
                amountCents: $amountCents,
            );
        }

        return $lines;
    }
}
