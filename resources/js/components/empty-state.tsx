import type { ReactNode } from 'react';

type EmptyStateProps = {
    title: string;
    description: string;
    action?: ReactNode;
};

export function EmptyState({ title, description, action }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border">
            <h2 className="text-lg font-semibold">{title}</h2>
            <p className="max-w-sm text-sm text-muted-foreground">
                {description}
            </p>
            {action}
        </div>
    );
}
