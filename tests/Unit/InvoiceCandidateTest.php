<?php

use App\Invoices\CandidateStatus;
use App\Invoices\InvoiceCandidate;
use Carbon\CarbonImmutable;

it('sobrevive a ida e volta para array', function (): void {
    $candidate = new InvoiceCandidate(
        description: 'MERCADOLIVRE*ABC',
        merchant: 'Mercado Livre',
        totalAmountCents: 129_900,
        installmentsCount: 10,
        installmentNumber: 3,
        installmentAmountCents: 12_990,
        purchasedAt: CarbonImmutable::parse('2026-03-15'),
        status: CandidateStatus::Matched,
    );

    $restored = InvoiceCandidate::fromArray($candidate->toArray());

    expect($restored->description)->toBe('MERCADOLIVRE*ABC')
        ->and($restored->merchant)->toBe('Mercado Livre')
        ->and($restored->totalAmountCents)->toBe(129_900)
        ->and($restored->installmentsCount)->toBe(10)
        ->and($restored->installmentNumber)->toBe(3)
        ->and($restored->installmentAmountCents)->toBe(12_990)
        ->and($restored->purchasedAt->toDateString())->toBe('2026-03-15')
        ->and($restored->status)->toBe(CandidateStatus::Matched);
});

it('so considera importavel o candidato casado ou divergente', function (): void {
    expect(CandidateStatus::Matched->isImportable())->toBeTrue()
        ->and(CandidateStatus::Divergent->isImportable())->toBeTrue()
        ->and(CandidateStatus::Duplicate->isImportable())->toBeFalse();
});
