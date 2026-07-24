import { Link, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types/nav';

const primaryLinks: NavItem[] = [
    { label: 'Earn', href: '/earn' },
    { label: 'Advertise', href: '/advertise' },
    { label: 'How It Works', href: '/how-it-works' },
    { label: 'Help', href: '/help' },
];

export function PublicNav() {
    const {
        url,
        props: {
            auth: { user },
        },
    } = usePage();
    const [isOpen, setIsOpen] = useState(false);

    const isActive = (href: string) => url === href || url.startsWith(`${href}/`);

    return (
        <header className="sticky top-0 z-40 border-b border-neutral-200 bg-white">
            <div className="mx-auto flex h-16 max-w-[1200px] items-center justify-between px-4 sm:px-6 lg:px-8">
                <Link href="/" className="focus-ring text-h4 rounded-sm font-bold text-neutral-900">
                    Ellipzo
                </Link>

                <nav className="hidden items-center gap-1 lg:flex" aria-label="Primary">
                    {primaryLinks.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={cn(
                                'focus-ring rounded-md px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-100',
                                isActive(item.href) && 'bg-brand-50 text-brand-700',
                            )}
                            aria-current={isActive(item.href) ? 'page' : undefined}
                        >
                            {item.label}
                        </Link>
                    ))}
                </nav>

                <div className="hidden items-center gap-2 lg:flex">
                    {user ? (
                        <Button asChild variant="primary" size="sm">
                            <Link href="/dashboard">Dashboard</Link>
                        </Button>
                    ) : (
                        <>
                            <Button asChild variant="ghost" size="sm">
                                <Link href="/login">Log in</Link>
                            </Button>
                            <Button asChild variant="primary" size="sm">
                                <Link href="/register">Get started</Link>
                            </Button>
                        </>
                    )}
                </div>

                <Sheet open={isOpen} onOpenChange={setIsOpen}>
                    <SheetTrigger asChild>
                        <Button variant="ghost" size="sm" className="lg:hidden" aria-label="Open menu">
                            <Menu className="size-5" aria-hidden="true" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" className="w-full sm:max-w-xs">
                        <SheetHeader>
                            <SheetTitle>Menu</SheetTitle>
                        </SheetHeader>
                        <nav className="flex flex-col gap-1 px-4" aria-label="Primary">
                            {primaryLinks.map((item) => (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    onClick={() => setIsOpen(false)}
                                    className={cn(
                                        'focus-ring text-body rounded-md px-3 py-2 font-medium text-neutral-700 hover:bg-neutral-100',
                                        isActive(item.href) && 'bg-brand-50 text-brand-700',
                                    )}
                                    aria-current={isActive(item.href) ? 'page' : undefined}
                                >
                                    {item.label}
                                </Link>
                            ))}
                        </nav>
                        <div className="mt-auto flex flex-col gap-2 border-t border-neutral-200 p-4">
                            {user ? (
                                <Button asChild variant="primary" onClick={() => setIsOpen(false)}>
                                    <Link href="/dashboard">Dashboard</Link>
                                </Button>
                            ) : (
                                <>
                                    <Button asChild variant="secondary" onClick={() => setIsOpen(false)}>
                                        <Link href="/login">Log in</Link>
                                    </Button>
                                    <Button asChild variant="primary" onClick={() => setIsOpen(false)}>
                                        <Link href="/register">Get started</Link>
                                    </Button>
                                </>
                            )}
                        </div>
                    </SheetContent>
                </Sheet>
            </div>
        </header>
    );
}
