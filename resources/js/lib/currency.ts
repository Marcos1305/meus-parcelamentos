const BRL = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
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
