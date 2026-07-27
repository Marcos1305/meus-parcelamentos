<?php

namespace App\Invoices\Parsers;

use App\Invoices\InvoiceParser;
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
}
