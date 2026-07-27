<?php

namespace App\Invoices;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class PopplerTextExtractor implements ExtractsInvoiceText
{
    /**
     * Codigo que o shell devolve quando nao encontra o comando.
     */
    private const COMMAND_NOT_FOUND = 127;

    public function __construct(private string $binary = 'pdftotext') {}

    /**
     * @throws ExtractorUnavailableException quando o binario nao esta no PATH
     *                                       do processo que atende a requisicao.
     * @throws RuntimeException quando o PDF nao pode ser lido.
     */
    public function extract(string $path, ?string $password = null): string
    {
        $command = [$this->binary, '-layout', '-enc', 'UTF-8'];

        if ($password !== null && $password !== '') {
            $command[] = '-upw';
            $command[] = $password;
        }

        $command[] = $path;
        $command[] = '-';

        $result = Process::run($command);

        if ($result->exitCode() === self::COMMAND_NOT_FOUND) {
            throw new ExtractorUnavailableException(
                "O binario '{$this->binary}' nao foi encontrado: ".trim($result->errorOutput()),
            );
        }

        if ($result->failed()) {
            throw new RuntimeException(
                'Nao foi possivel ler o PDF: '.trim($result->errorOutput()),
            );
        }

        return $result->output();
    }
}
