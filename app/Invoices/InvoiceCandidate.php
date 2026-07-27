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
        public CarbonImmutable $firstDueDate,
        public CandidateStatus $status,
    ) {}

    /**
     * @return array{description: string, merchant: string|null, total_amount_cents: int,
     *     installments_count: int, installment_number: int, installment_amount_cents: int,
     *     purchased_at: string, first_due_date: string, status: string}
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
            'first_due_date' => $this->firstDueDate->toDateString(),
            'status' => $this->status->value,
        ];
    }

    /**
     * O candidato volta da sessao, que dura duas horas e sobrevive a deploy:
     * a forma guardada pode ser de uma versao anterior do app. Um campo que
     * nao chega reconhecivel cai no status Duplicate, o unico que a gravacao
     * recusa, para que forma estranha nunca vire compra. O vencimento da
     * primeira parcela entra nessa regra: sem ele a deteccao de duplicata
     * nao tem ancora e a compra ja importada seria gravada de novo.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $purchasedAt = is_string($data['purchased_at'] ?? null)
            ? $data['purchased_at']
            : null;

        $firstDueDate = is_string($data['first_due_date'] ?? null)
            ? $data['first_due_date']
            : null;

        $status = $firstDueDate !== null && is_string($data['status'] ?? null)
            ? CandidateStatus::tryFrom($data['status'])
            : null;

        return new self(
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            merchant: is_string($data['merchant'] ?? null) ? $data['merchant'] : null,
            totalAmountCents: (int) ($data['total_amount_cents'] ?? 0),
            installmentsCount: (int) ($data['installments_count'] ?? 0),
            installmentNumber: (int) ($data['installment_number'] ?? 0),
            installmentAmountCents: (int) ($data['installment_amount_cents'] ?? 0),
            purchasedAt: $purchasedAt === null
                ? CarbonImmutable::today()
                : CarbonImmutable::parse($purchasedAt),
            firstDueDate: $firstDueDate === null
                ? CarbonImmutable::today()
                : CarbonImmutable::parse($firstDueDate),
            status: $status ?? CandidateStatus::Duplicate,
        );
    }
}
