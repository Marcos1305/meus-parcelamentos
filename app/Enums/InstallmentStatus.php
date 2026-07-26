<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum InstallmentStatus: string
{
    case Paid = 'paid';
    case Upcoming = 'upcoming';
    case Pending = 'pending';

    /**
     * Deriva o status de uma parcela. Nunca persistido: e sempre funcao
     * da data de vencimento contra hoje.
     */
    public static function forDueDate(CarbonImmutable $dueDate, CarbonImmutable $today): self
    {
        if ($dueDate->startOfDay()->lessThan($today->startOfDay())) {
            return self::Paid;
        }

        return $dueDate->isSameMonth($today, true)
            ? self::Upcoming
            : self::Pending;
    }

    /**
     * Uma parcela esta "em aberto" quando ainda vai ser cobrada.
     */
    public function isOpen(): bool
    {
        return $this !== self::Paid;
    }

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Pago',
            self::Upcoming => 'Próximo',
            self::Pending => 'Pendente',
        };
    }
}
