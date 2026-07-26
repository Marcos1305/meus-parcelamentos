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
    paid_this_month_cents: number;
};

export type InstallmentStatus = 'paid' | 'upcoming' | 'pending';

export type HistoryRow = {
    id: number;
    number: number;
    amount_cents: number;
    due_date: string;
    status: InstallmentStatus;
    purchase: {
        id: number;
        description: string;
        merchant: string | null;
        total_amount_cents: number;
        installments_count: number;
    };
    card: {
        name: string;
        last_four_digits: string;
    };
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export type HistoryFilters = {
    month: string;
    status: string;
    card: string;
};

export type FinanceTip = {
    count: number;
    freed_cents: number;
    next_month: string;
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
