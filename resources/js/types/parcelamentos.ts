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

export type FeaturedPurchase = {
    description: string;
    merchant: string | null;
    total_amount_cents: number;
    paid_cents: number;
    remaining_cents: number;
    current_number: number;
    installments_count: number;
};

export type CardOverview = CardSummary & {
    committed_cents: number;
    available_cents: number;
    committed_ratio: number;
    next_due_date: string | null;
    next_due_cents: number;
    featured: FeaturedPurchase | null;
};

export type MonthlyProjectionRow = {
    month: string;
    total_cents: number;
};

export type DashboardSummary = {
    cards_count: number;
    open_installments_count: number;
    total_due_cents: number;
    next_due_date: string | null;
    next_due_cents: number;
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
