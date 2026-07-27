<?php

namespace App\Invoices\Parsers;

use App\Invoices\InvoiceParser;
use App\Invoices\ParsedInvoice;
use App\Invoices\ParsedInvoiceLine;
use App\Invoices\UnreadableInvoiceException;
use Carbon\CarbonImmutable;

class MercadoPagoInvoiceParser implements InvoiceParser
{
    private const ISSUER = 'Mercado Pago';

    private const SIGNATURE = 'Mercado Pago';

    private const DUE_DATE_PATTERN = '/Vencimento:\s*(\d{2})\/(\d{2})\/(\d{4})/u';

    /**
     * Uma linha parcelada tem dia, mes, estabelecimento, o marcador
     * "Parcela N de M" e o valor. O marcador e obrigatorio: e o que
     * distingue uma compra parcelada de uma transacao avulsa ou da linha
     * de pagamento da fatura, que nunca o carrega. Exigir que a linha
     * comece com a data tambem descarta a prosa explicativa sobre
     * parcelamento de fatura, que menciona "Parcela" sem esse padrao
     * numerico e nao comeca com data.
     */
    private const INSTALLMENT_LINE_PATTERN = '/^\s*(\d{2})\/(\d{2})\s+(.+?)\s+Parcela\s+(\d+)\s+de\s+(\d+)\s+(-)?R\$\s*([\d.,]+)\s*$/u';

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
            (int) $matches[2],
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
