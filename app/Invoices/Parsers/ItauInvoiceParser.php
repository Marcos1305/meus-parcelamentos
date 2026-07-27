<?php

namespace App\Invoices\Parsers;

use App\Invoices\InvoiceParser;
use App\Invoices\ParsedInvoice;
use App\Invoices\ParsedInvoiceLine;
use App\Invoices\UnreadableInvoiceException;
use Carbon\CarbonImmutable;

class ItauInvoiceParser implements InvoiceParser
{
    private const ISSUER = 'Itaú';

    /**
     * @var array<int, string>
     */
    private const SIGNATURES = ['ITAU UNIBANCO', 'Banco Itaú'];

    private const DUE_DATE_PATTERN = '/Vencimento:\s*(\d{2})\/(\d{2})\/(\d{4})/u';

    private const CARD_PATTERN = '/Cartão\s+(?:[0-9A-Z]{4}\.){3}(\d{4})/u';

    /**
     * Titulo da secao que projeta as parcelas ainda nao cobradas. Dali para
     * baixo cada parcelamento da secao de detalhe reaparece com o numero da
     * parcela seguinte, o que geraria uma segunda compra fantasma por plano.
     */
    private const FUTURE_INSTALLMENTS_MARKER = 'Compras parceladas - próximas faturas';

    /**
     * Uma linha parcelada tem dia, mes, estabelecimento, o marcador "N/M"
     * colado ao fim do estabelecimento e o valor. Esta fatura nao escreve a
     * palavra "Parcela" em lugar nenhum: o marcador numerico e o unico sinal
     * que distingue uma compra parcelada de uma transacao avulsa.
     *
     * O estabelecimento e uma sequencia de palavras separadas por no maximo
     * dois espacos. Esse teto e o que impede a descricao de atravessar a
     * fronteira entre as duas colunas que o layout imprime numa mesma linha
     * de texto: a corrida de espacos mais estreita que separa colunas
     * vizinhas tem quatro espacos (a distancia entre DATA e ESTABELECIMENTO),
     * e a fronteira entre as colunas em si tem oito. Parar em dois deixa
     * margem para os dois lados: absorve um espaco duplo dentro do nome do
     * estabelecimento sem nunca alcancar a coluna ao lado.
     */
    private const INSTALLMENT_LINE_PATTERN = '/(?<!\d)(\d{2})\/(\d{2}) {2,}((?:\S+ {1,2})+?)(\d{1,2})\/(\d{1,2}) {2,}(-?)(\d{1,3}(?:\.\d{3})*,\d{2})(?!\d)/u';

    public function supports(string $text): bool
    {
        foreach (self::SIGNATURES as $signature) {
            if (str_contains($text, $signature)) {
                return true;
            }
        }

        return false;
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
            /**
             * Uma linha de texto pode carregar duas transacoes independentes,
             * uma por coluna, entao todas as ocorrencias sao coletadas.
             *
             * @var array<int, array<int, string>> $matches
             */
            preg_match_all(self::INSTALLMENT_LINE_PATTERN, $rawLine, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if ($match[6] !== '') {
                    continue;
                }

                $lines[] = new ParsedInvoiceLine(
                    description: trim($match[3]),
                    merchant: null,
                    transactionDay: (int) $match[1],
                    installmentNumber: (int) $match[4],
                    installmentsCount: (int) $match[5],
                    amountCents: $this->parseAmountCents($match[7]),
                );
            }
        }

        return $lines;
    }

    private function parseAmountCents(string $raw): int
    {
        $normalized = str_replace(['.', ','], ['', '.'], $raw);

        return (int) round(((float) $normalized) * 100);
    }
}
