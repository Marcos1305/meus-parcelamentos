export type CardBrandOption = {
    value: string;
    label: string;
};

export type CardSummary = {
    id: number;
    name: string;
    brand: string;
    brand_label: string;
    label: string | null;
    last_four_digits: string;
    credit_limit_cents: number;
    closing_day: number;
    due_day: number;
};

export type SchedulePreviewRow = {
    number: number;
    amount_cents: number;
    due_date: string;
};

export type CardOption = {
    id: number;
    name: string;
    last_four_digits: string;
    closing_day: number;
    due_day: number;
};

export type CardFormValues = {
    id?: number;
    name: string;
    brand: string;
    label: string | null;
    last_four_digits: string;
    credit_limit_cents: number;
    closing_day: number;
    due_day: number;
};
