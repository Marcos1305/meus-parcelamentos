<?php

namespace App\Invoices;

use Carbon\CarbonImmutable;

readonly class InvoiceCandidate
{
    public function __construct(
        public string $description,
        public ?string $merchant,
        public int $totalAmountCents,
        public int $installmentsCount,
        public int $installmentNumber,
        public int $installmentAmountCents,
        public CarbonImmutable $purchasedAt,
        public CandidateStatus $status,
    ) {}

    /**
     * @return array{description: string, merchant: string|null, total_amount_cents: int,
     *     installments_count: int, installment_number: int, installment_amount_cents: int,
     *     purchased_at: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'merchant' => $this->merchant,
            'total_amount_cents' => $this->totalAmountCents,
            'installments_count' => $this->installmentsCount,
            'installment_number' => $this->installmentNumber,
            'installment_amount_cents' => $this->installmentAmountCents,
            'purchased_at' => $this->purchasedAt->toDateString(),
            'status' => $this->status->value,
        ];
    }

    /**
     * @param  array{description: string, merchant: string|null, total_amount_cents: int,
     *     installments_count: int, installment_number: int, installment_amount_cents: int,
     *     purchased_at: string, status: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            description: $data['description'],
            merchant: $data['merchant'],
            totalAmountCents: $data['total_amount_cents'],
            installmentsCount: $data['installments_count'],
            installmentNumber: $data['installment_number'],
            installmentAmountCents: $data['installment_amount_cents'],
            purchasedAt: CarbonImmutable::parse($data['purchased_at']),
            status: CandidateStatus::from($data['status']),
        );
    }
}
