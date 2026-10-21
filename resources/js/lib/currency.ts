const BRL = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const BRL_COMPACT = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    notation: 'compact',
    maximumFractionDigits: 1,
});

const MONTH = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const DATE = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

export function formatBRL(cents: number): string {
    return BRL.format(cents / 100);
}

/**
 * Valor compactado para rótulos de eixo, onde "R$ 1,2 mil" cabe e
 * "R$ 1.234,56" não. Nunca use isto onde o valor exato importa.
 */
export function formatBRLCompact(cents: number): string {
    return BRL_COMPACT.format(cents / 100);
}

export function parseBRLToCents(input: string): number {
    const digits = input.replace(/\D/g, '');

    return digits === '' ? 0 : Number.parseInt(digits, 10);
}

export function formatMonthLabel(iso: string): string {
    return MONTH.format(new Date(`${iso.slice(0, 7)}-01T00:00:00Z`));
}

export function formatDate(iso: string): string {
    return DATE.format(new Date(`${iso.slice(0, 10)}T00:00:00Z`));
}
