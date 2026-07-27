<?php

namespace App\Invoices;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class PopplerTextExtractor implements ExtractsInvoiceText
{
    public function extract(string $path, ?string $password = null): string
    {
        $command = ['pdftotext', '-layout', '-enc', 'UTF-8'];

        if ($password !== null && $password !== '') {
            $command[] = '-upw';
            $command[] = $password;
        }

        $command[] = $path;
        $command[] = '-';

        $result = Process::run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                'Nao foi possivel ler o PDF: '.trim($result->errorOutput()),
            );
        }

        return $result->output();
    }
}
