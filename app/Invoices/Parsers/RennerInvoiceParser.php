<?php

namespace App\Invoices\Parsers;

use App\Invoices\InvoiceParser;
use App\Invoices\ParsedInvoice;
use App\Invoices\ParsedInvoiceLine;
use App\Invoices\UnreadableInvoiceException;
use Carbon\CarbonImmutable;

class RennerInvoiceParser implements InvoiceParser
{
    private const ISSUER = 'Renner';

    /**
     * A fatura do Itaú cita "RENNER" como estabelecimento de uma compra, entao
     * a palavra sozinha nao serve de assinatura: hijackaria a fatura do Itaú.
     * A razao social da financeira que emite a fatura e exclusiva dela.
     */
    private const SIGNATURE = 'Realize Sociedade';

    private const DUE_DATE_PATTERN = '/Vencimento:\s*(\d{2})\/(\d{2})\/(\d{4})/u';

    private const CARD_PATTERN = '/\d{4}\*{2}\.\*{6}\.(\d{4})/u';

    /**
     * Titulo da secao que projeta as parcelas ainda nao cobradas. Dali para
     * baixo cada parcelamento da secao de detalhe reaparece com o numero da
     * parcela seguinte, num formato diferente ("Parc. N/M", com ponto), o que
     * geraria uma segunda compra fantasma por plano.
     */
    private const FUTURE_INSTALLMENTS_MARKER = 'Compras parceladas | Próximas faturas:';

    /**
     * Uma linha parcelada tem data completa, a descricao literal
     * "Compra Parcelada Parc N/M", o estabelecimento numa coluna propria e o
     * valor. O literal "Compra Parcelada Parc" e obrigatorio: e o que
     * distingue uma compra parcelada de uma "Compra a Vista" ou de uma linha
     * de pagamento, que nunca o carregam.
     */
    private const INSTALLMENT_LINE_PATTERN = '/^(\d{2})\/(\d{2})\/(\d{4})\s+Compra Parcelada Parc\s+(\d{1,2})\/(\d{1,2})\s+(.+?)\s{2,}(-?)(\d{1,3}(?:\.\d{3})*,\d{2})\s*$/u';

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
            lastFourDigits: $this->extractLastFourDigits($text),
            lines: $this->extractLines($this->cutFutureInstallments($text)),
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

    private function extractLastFourDigits(string $text): ?string
    {
        if (! preg_match(self::CARD_PATTERN, $text, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function cutFutureInstallments(string $text): string
    {
        $marker = mb_strpos($text, self::FUTURE_INSTALLMENTS_MARKER);

        if ($marker === false) {
            return $text;
        }

        return mb_substr($text, 0, $marker);
    }

    /**
     * @return array<int, ParsedInvoiceLine>
     *
     * @throws UnreadableInvoiceException
     */
    private function extractLines(string $text): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $rawLine) {
            if (! preg_match(self::INSTALLMENT_LINE_PATTERN, $rawLine, $matches)) {
                continue;
            }

            if ($matches[7] !== '') {
                continue;
            }

            $lines[] = new ParsedInvoiceLine(
                description: trim($matches[6]),
                merchant: null,
                transactionDay: (int) $matches[1],
                installmentNumber: (int) $matches[4],
                installmentsCount: (int) $matches[5],
                amountCents: $this->parseAmountCents($matches[8]),
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
