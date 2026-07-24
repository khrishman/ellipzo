import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    CheckSquare,
    ChevronsUpDown,
    ClipboardList,
    FileQuestion,
    FileText,
    Gift,
    LayoutDashboard,
    LifeBuoy,
    ListChecks,
    LogOut,
    Megaphone,
    PlusCircle,
    Receipt,
    ShieldCheck,
    User,
    Users,
    Wallet,
} from 'lucide-react';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { NavGroup } from '@/types/nav';

const sidebarGroups: NavGroup[] = [
    { items: [{ label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard }] },
    {
        label: 'Earn',
        items: [
            { label: 'Tasks', href: '/tasks', icon: ClipboardList },
            { label: 'Surveys', href: '/surveys', icon: FileQuestion },
            { label: 'Offerwalls', href: '/offerwalls', icon: Gift },
            { label: 'Submissions', href: '/submissions', icon: CheckSquare },
        ],
    },
    {
        label: 'Advertise',
        items: [
            { label: 'Overview', href: '/advertise', icon: BarChart3 },
            { label: 'Funds', href: '/advertise/funds', icon: Wallet },
            { label: 'My Campaigns', href: '/advertise/campaigns', icon: Megaphone },
            { label: 'Create Campaign', href: '/advertise/campaigns/create', icon: PlusCircle },
            { label: 'Review Submissions', href: '/advertise/reviews', icon: ListChecks },
        ],
    },
    {
        items: [
            { label: 'Transactions', href: '/wallet/transactions', icon: Receipt },
            { label: 'Referrals', href: '/referrals', icon: Users },
            { label: 'Support', href: '/support', icon: LifeBuoy },
        ],
    },
];

export function AppSidebar() {
    const {
        url,
        props: {
            auth: { user },
        },
    } = usePage();
    const isActive = (href: string) => url === href || url.startsWith(`${href}/`);

    return (
        <aside className="hidden w-64 shrink-0 flex-col border-r border-neutral-200 bg-white lg:flex">
            <div className="flex h-16 items-center border-b border-neutral-200 px-6">
                <Link href="/dashboard" className="focus-ring text-h4 rounded-sm font-bold text-neutral-900">
                    Ellipzo
                </Link>
            </div>

            <nav className="flex-1 space-y-6 overflow-y-auto px-3 py-4" aria-label="Main">
                {sidebarGroups.map((group, index) => (
                    <div key={group.label ?? `group-${index}`}>
                        {group.label && (
                            <p className="text-caption px-3 pb-1 font-semibold tracking-wide text-neutral-500 uppercase">{group.label}</p>
                        )}
                        <div className="space-y-0.5">
                            {group.items.map((item) => {
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
                        </div>
                    </div>
                ))}
            </nav>

            <div className="border-t border-neutral-200 p-3">
                <DropdownMenu>
                    <DropdownMenuTrigger
                        className="focus-ring flex w-full items-center gap-3 rounded-md px-3 py-2 text-left hover:bg-neutral-100"
                        aria-label="Account menu"
                    >
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
                            <User className="size-4" aria-hidden="true" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="text-body-sm block truncate font-medium text-neutral-900">{user?.name ?? 'Account'}</span>
                            {user?.email && <span className="text-caption block truncate text-neutral-500">{user.email}</span>}
                        </span>
                        <ChevronsUpDown className="size-4 shrink-0 text-neutral-400" aria-hidden="true" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-56">
                        <DropdownMenuLabel>My account</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link href="/settings/profile" className="flex items-center gap-2">
                                <User className="size-4" aria-hidden="true" />
                                Profile
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href="/settings/security" className="flex items-center gap-2">
                                <ShieldCheck className="size-4" aria-hidden="true" />
                                Security
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href="/settings/notifications" className="flex items-center gap-2">
                                <Bell className="size-4" aria-hidden="true" />
                                Notification preferences
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href="/legal/terms" className="flex items-center gap-2">
                                <FileText className="size-4" aria-hidden="true" />
                                Legal and privacy
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild destructive>
                            <Link href="/logout" method="post" as="button" className="flex w-full items-center gap-2">
                                <LogOut className="size-4" aria-hidden="true" />
                                Sign out
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </aside>
    );
}
