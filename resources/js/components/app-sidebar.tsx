import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CalendarRange,
    ChartPie,
    CreditCard,
    FileUp,
    FolderGit2,
    History as HistoryIcon,
    LayoutGrid,
    Plus,
} from 'lucide-react';
import InvoiceImportController from '@/actions/App/Http/Controllers/InvoiceImportController';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Button } from '@/components/ui/button';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import cards from '@/routes/cards';
import history from '@/routes/history';
import plan from '@/routes/plan';
import purchases from '@/routes/purchases';
import spending from '@/routes/spending';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Meus Cartões',
        href: cards.index(),
        icon: CreditCard,
    },
    {
        title: 'Planejamento',
        href: plan.index(),
        icon: CalendarRange,
    },
    {
        title: 'Histórico',
        href: history.index(),
        icon: HistoryIcon,
    },
    {
        title: 'Gastos',
        href: spending.index(),
        icon: ChartPie,
    },
];

const invoiceImportNavItem: NavItem = {
    title: 'Importar fatura',
    href: InvoiceImportController.create(),
    icon: FileUp,
};

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { invoiceImportAvailable } = usePage().props;

    const navItems = invoiceImportAvailable
        ? [...mainNavItems, invoiceImportNavItem]
        : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />

                <div className="px-2 pt-2">
                    <Button asChild className="w-full">
                        <Link href={purchases.create()}>
                            <Plus /> Novo Lançamento
                        </Link>
                    </Button>
                </div>
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
