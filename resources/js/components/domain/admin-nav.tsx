import { Link, usePage } from '@inertiajs/react';
import {
    FileBarChart,
    Gavel,
    LayoutDashboard,
    LifeBuoy,
    Megaphone,
    Plug,
    Receipt,
    ScrollText,
    Settings,
    ShieldAlert,
    ShieldCheck,
    SquareCheck,
    Users,
    Wallet,
} from 'lucide-react';

import { cn } from '@/lib/utils';
import type { NavItem } from '@/types/nav';

const adminNavItems: NavItem[] = [
    { label: 'Overview', href: '/admin', icon: LayoutDashboard, requiredPermission: 'admin.overview.view' },
    { label: 'Staff Access', href: '/admin/staff-access', icon: ShieldCheck, requiredPermission: 'staff.view' },
    { label: 'Ledger', href: '/admin/ledger', icon: Receipt, requiredPermission: 'ledger.view' },
    { label: 'Users', href: '/admin/users', icon: Users, requiredPermission: 'users.view', implemented: false },
    { label: 'Campaigns', href: '/admin/campaigns', icon: Megaphone, requiredPermission: 'campaigns.moderate', implemented: false },
    { label: 'Submissions', href: '/admin/submissions', icon: SquareCheck, requiredPermission: 'submissions.moderate', implemented: false },
    { label: 'Disputes', href: '/admin/disputes', icon: Gavel, requiredPermission: 'disputes.resolve', implemented: false },
    { label: 'Finance', href: '/admin/finance', icon: Wallet, requiredPermission: 'deposits.review', implemented: false },
    { label: 'Risk', href: '/admin/risk', icon: ShieldAlert, requiredPermission: 'risk.review', implemented: false },
    { label: 'Support', href: '/admin/support', icon: LifeBuoy, requiredPermission: 'support.view', implemented: false },
    { label: 'Providers', href: '/admin/providers', icon: Plug, requiredPermission: 'settings.manage', implemented: false },
    { label: 'Reports', href: '/admin/reports', icon: FileBarChart, requiredPermission: 'audit.view', implemented: false },
    { label: 'Settings', href: '/admin/settings', icon: Settings, requiredPermission: 'settings.manage', implemented: false },
    { label: 'Audit', href: '/admin/audit', icon: ScrollText, requiredPermission: 'audit.view', implemented: false },
];

interface AdminNavProps {
    /**
     * Display-only convenience for the shell, never an authorization
     * boundary. The server independently authorizes every admin route
     * regardless of what this component renders. When omitted, every
     * permission-gated item renders (used only where no real permission
     * data exists, e.g. isolated component previews).
     */
    permissions?: string[];
    className?: string;
}

export function AdminNav({ permissions, className }: AdminNavProps) {
    const { url } = usePage();
    const isActive = (href: string) => url === href || url.startsWith(`${href}/`);

    const visibleItems = adminNavItems.filter((item) => !item.requiredPermission || !permissions || permissions.includes(item.requiredPermission));

    return (
        <nav className={cn('space-y-0.5', className)} aria-label="Admin">
            {visibleItems.map((item) => {
                const Icon = item.icon;

                if (item.implemented === false) {
                    return (
                        <span
                            key={item.href}
                            aria-disabled="true"
                            title="Not built yet"
                            className="flex cursor-not-allowed items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-neutral-400"
                        >
                            {Icon && <Icon className="size-4 shrink-0" aria-hidden="true" />}
                            {item.label}
                            <span className="text-caption ml-auto rounded-full bg-neutral-100 px-2 py-0.5 text-neutral-500">Soon</span>
                        </span>
                    );
                }

                const active = isActive(item.href);
                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            'focus-ring flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-100',
                            active && 'bg-brand-50 text-brand-700',
                        )}
                    >
                        {Icon && <Icon className="size-4 shrink-0" aria-hidden="true" />}
                        {item.label}
                    </Link>
                );
            })}
        </nav>
    );
}
