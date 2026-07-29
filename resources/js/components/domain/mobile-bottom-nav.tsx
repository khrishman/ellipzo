import { Link, usePage } from '@inertiajs/react';
import { Activity, Home, Megaphone, Menu, Wallet } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { NavItem } from '@/types/nav';

const bottomNavItems: NavItem[] = [
    { label: 'Home', href: '/dashboard', icon: Home },
    { label: 'Earn', href: '/tasks', icon: Wallet },
    { label: 'Advertise', href: '/advertise', icon: Megaphone },
    { label: 'Activity', href: '/transactions', icon: Activity },
    { label: 'Menu', href: '/settings/profile', icon: Menu },
];

export function MobileBottomNav() {
    const { url } = usePage();
    const isActive = (href: string) => url === href || url.startsWith(`${href}/`);

    return (
        <nav
            className="fixed inset-x-0 bottom-0 z-40 flex border-t border-neutral-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden"
            aria-label="Main"
        >
            {bottomNavItems.map((item) => {
                const Icon = item.icon;
                const active = isActive(item.href);
                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            'focus-ring flex min-h-11 flex-1 flex-col items-center justify-center gap-1 py-2 text-neutral-500',
                            active && 'text-brand-700',
                        )}
                    >
                        {Icon && <Icon className="size-5" aria-hidden="true" />}
                        <span className="text-caption">{item.label}</span>
                    </Link>
                );
            })}
        </nav>
    );
}
