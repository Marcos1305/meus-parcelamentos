import type { SchedulePreviewRow } from '@/types';

function daysInMonth(year: number, month: number): number {
    return new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
}

function onDay(year: number, month: number, day: number): string {
    const clamped = Math.min(day, daysInMonth(year, month));

    return new Date(Date.UTC(year, month, clamped)).toISOString().slice(0, 10);
}

function firstDueMonth(
    purchasedAt: string,
    closingDay: number,
    dueDay: number,
): [number, number] {
    const purchased = new Date(`${purchasedAt}T00:00:00Z`);

    let year = purchased.getUTCFullYear();
    let month = purchased.getUTCMonth();

    if (purchased.getUTCDate() > closingDay) {
        month += 1;
    }

    if (dueDay <= closingDay) {
        month += 1;
    }

    year += Math.floor(month / 12);
    month = ((month % 12) + 12) % 12;

    return [year, month];
}

export function buildSchedulePreview(
    totalCents: number,
    count: number,
    purchasedAt: string,
    closingDay: number,
    dueDay: number,
): SchedulePreviewRow[] {
    if (totalCents <= 0 || count <= 0 || purchasedAt === '') {
        return [];
    }

    const base = Math.floor(totalCents / count);
    const remainder = totalCents % count;
    const [year, month] = firstDueMonth(purchasedAt, closingDay, dueDay);

    return Array.from({ length: count }, (_, index) => ({
        number: index + 1,
        amount_cents: index === 0 ? base + remainder : base,
        due_date: onDay(year, month + index, dueDay),
    }));
}
