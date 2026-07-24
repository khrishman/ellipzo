import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { AppSidebar } from '@/components/domain/app-sidebar';
import { MobileBottomNav } from '@/components/domain/mobile-bottom-nav';

interface AppLayoutProps extends PropsWithChildren {
    pageTitle?: string;
}

export default function AppLayout({ pageTitle, children }: AppLayoutProps) {
    return (
        <div className="flex min-h-screen bg-neutral-50">
            <a
                href="#main-content"
                className="focus-ring sr-only rounded-md bg-white px-4 py-2 text-sm font-medium text-neutral-900 focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50"
            >
                Skip to main content
            </a>

            <AppSidebar />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 items-center border-b border-neutral-200 bg-white px-4 lg:hidden">
                    <Link href="/dashboard" className="focus-ring text-h4 rounded-sm font-bold text-neutral-900">
                        Ellipzo
                    </Link>
                    {pageTitle && <span className="text-body-sm ml-4 truncate text-neutral-500">{pageTitle}</span>}
                </header>

                <main id="main-content" className="mx-auto w-full max-w-[1440px] flex-1 px-4 py-6 pb-20 sm:px-6 lg:px-8 lg:pb-6">
                    {children}
                </main>
            </div>

            <MobileBottomNav />
        </div>
    );
}
