import { Link, usePage } from '@inertiajs/react';
import {
    FileBarChart,
    Gavel,
    LayoutDashboard,
    LifeBuoy,
    Megaphone,
    Plug,
    ScrollText,
    Settings,
    ShieldAlert,
    SquareCheck,
    Users,
    Wallet,
} from 'lucide-react';

import { cn } from '@/lib/utils';
import type { NavItem } from '@/types/nav';

const adminNavItems: NavItem[] = [
    { label: 'Overview', href: '/admin', icon: LayoutDashboard },
    { label: 'Users', href: '/admin/users', icon: Users, requiredPermission: 'users.view' },
    { label: 'Campaigns', href: '/admin/campaigns', icon: Megaphone, requiredPermission: 'campaigns.moderate' },
    { label: 'Submissions', href: '/admin/submissions', icon: SquareCheck, requiredPermission: 'submissions.moderate' },
    { label: 'Disputes', href: '/admin/disputes', icon: Gavel, requiredPermission: 'disputes.resolve' },
    { label: 'Finance', href: '/admin/finance', icon: Wallet, requiredPermission: 'deposits.review' },
    { label: 'Risk', href: '/admin/risk', icon: ShieldAlert, requiredPermission: 'risk.review' },
    { label: 'Support', href: '/admin/support', icon: LifeBuoy, requiredPermission: 'support.view' },
    { label: 'Providers', href: '/admin/providers', icon: Plug, requiredPermission: 'settings.manage' },
    { label: 'Reports', href: '/admin/reports', icon: FileBarChart, requiredPermission: 'audit.view' },
    { label: 'Settings', href: '/admin/settings', icon: Settings, requiredPermission: 'settings.manage' },
    { label: 'Audit', href: '/admin/audit', icon: ScrollText, requiredPermission: 'audit.view' },
];

interface AdminNavProps {
    /**
     * No staff permission system exists yet. When omitted, every item
     * renders — this is a display-only convenience for the shell, never an
     * authorization boundary. The server must independently authorize every
     * admin route regardless of what this component renders.
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
