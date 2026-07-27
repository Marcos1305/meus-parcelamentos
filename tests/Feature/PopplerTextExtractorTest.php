<?php

use App\Invoices\ExtractorUnavailableException;
use App\Invoices\PopplerTextExtractor;

function minimalPdfBytes(): string
{
    $content = "BT /F1 12 Tf 20 100 Td (FATURA TESTE) Tj ET\n";

    return "%PDF-1.4\n"
        ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        .'3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]'
        ."/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
        .'4 0 obj<</Length '.strlen($content).">>stream\n"
        .$content
        ."endstream\nendobj\n"
        ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
        ."trailer<</Root 1 0 R/Size 6>>\n"
        ."%%EOF\n";
}

it('extrai o texto de um pdf', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fatura').'.pdf';
    file_put_contents($path, minimalPdfBytes());

    $text = (new PopplerTextExtractor)->extract($path);

    unlink($path);

    expect($text)->toContain('FATURA TESTE');
})->skip(fn (): bool => exec('command -v pdftotext') === '', 'pdftotext nao instalado');

it('falha com mensagem clara quando o arquivo nao e um pdf', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fatura').'.pdf';
    file_put_contents($path, 'isto nao e um pdf');

    expect(fn (): string => (new PopplerTextExtractor)->extract($path))
        ->toThrow(RuntimeException::class);

    unlink($path);
})->skip(fn (): bool => exec('command -v pdftotext') === '', 'pdftotext nao instalado');

it('distingue binario ausente de pdf ilegivel', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fatura').'.pdf';
    file_put_contents($path, minimalPdfBytes());

    $extractor = new PopplerTextExtractor('pdftotext-que-nao-existe');

    expect(fn (): string => $extractor->extract($path))
        ->toThrow(ExtractorUnavailableException::class);

    unlink($path);
});
