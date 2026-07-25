import { Link, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { type PropsWithChildren, useState } from 'react';

import { AdminNav } from '@/components/domain/admin-nav';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';

interface AdminLayoutProps extends PropsWithChildren {
    /** Overrides the shared Inertia permission list — mainly for tests. Real pages should omit this and let it come from the session. */
    permissions?: string[];
    pageTitle?: string;
}

export default function AdminLayout({ permissions, pageTitle, children }: AdminLayoutProps) {
    const { props } = usePage();
    const resolvedPermissions = permissions ?? props.auth?.permissions;
    const [isMenuOpen, setIsMenuOpen] = useState(false);

    return (
        <div className="flex min-h-screen bg-neutral-50">
            <a
                href="#main-content"
                className="focus-ring sr-only rounded-md bg-white px-4 py-2 text-sm font-medium text-neutral-900 focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50"
            >
                Skip to main content
            </a>

            <aside className="hidden w-64 shrink-0 flex-col border-r border-neutral-200 bg-white lg:flex">
                <div className="flex h-16 items-center gap-2 border-b border-neutral-200 px-6">
                    <Link href="/admin" className="focus-ring text-h4 rounded-sm font-bold text-neutral-900">
                        Ellipzo
                    </Link>
                    <span className="text-caption rounded-full bg-brand-100 px-2 py-0.5 font-semibold text-brand-700">Admin</span>
                </div>
                <div className="flex-1 overflow-y-auto px-3 py-4">
                    <AdminNav permissions={resolvedPermissions} />
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 items-center gap-3 border-b border-neutral-200 bg-white px-4 lg:hidden">
                    <Sheet open={isMenuOpen} onOpenChange={setIsMenuOpen}>
                        <SheetTrigger asChild>
                            <Button variant="ghost" size="sm" aria-label="Open admin menu">
                                <Menu className="size-5" aria-hidden="true" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="left" className="w-full sm:max-w-xs">
                            <SheetHeader>
                                <SheetTitle>Admin menu</SheetTitle>
                            </SheetHeader>
                            <div className="px-3" onClick={() => setIsMenuOpen(false)}>
                                <AdminNav permissions={resolvedPermissions} />
                            </div>
                        </SheetContent>
                    </Sheet>
                    <Link href="/admin" className="focus-ring text-h4 rounded-sm font-bold text-neutral-900">
                        Ellipzo
                    </Link>
                    {pageTitle && <span className="text-body-sm truncate text-neutral-500">{pageTitle}</span>}
                </header>

                <main id="main-content" className="mx-auto w-full max-w-[1440px] flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    {children}
                </main>
            </div>
        </div>
    );
}
