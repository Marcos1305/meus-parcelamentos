<?php

namespace App\Invoices;

enum CandidateStatus: string
{
    case Matched = 'matched';
    case Duplicate = 'duplicate';
    case Divergent = 'divergent';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Pronto',
            self::Duplicate => 'Já importado',
            self::Divergent => 'Conferir data',
        };
    }

    /**
     * Duplicata nunca e importada; divergente pode ser, apos correcao.
     */
    public function isImportable(): bool
    {
        return $this !== self::Duplicate;
    }
}
