import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { PublicNav } from '@/components/domain/public-nav';

const footerLinks = [
    { label: 'How It Works', href: '/how-it-works' },
    { label: 'Help', href: '/help' },
];

export default function PublicLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col bg-neutral-50">
            <a
                href="#main-content"
                className="focus-ring sr-only rounded-md bg-white px-4 py-2 text-sm font-medium text-neutral-900 focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50"
            >
                Skip to main content
            </a>

            <PublicNav />

            <main id="main-content" className="flex-1">
                {children}
            </main>

            <footer className="border-t border-neutral-200 bg-white">
                <div className="mx-auto flex max-w-[1200px] flex-col gap-4 px-4 py-8 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <div>
                        <Link href="/" className="focus-ring text-h4 rounded-sm font-bold text-neutral-900">
                            Ellipzo
                        </Link>
                        <p className="text-body-sm mt-1 max-w-md text-neutral-500">
                            Ellipzo is not an investment platform. Earnings require completed and approved work.
                        </p>
                    </div>
                    <nav className="flex flex-wrap gap-x-6 gap-y-2" aria-label="Footer">
                        {footerLinks.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className="focus-ring text-body-sm rounded-sm text-neutral-600 hover:text-neutral-900"
                            >
                                {item.label}
                            </Link>
                        ))}
                    </nav>
                </div>
            </footer>
        </div>
    );
}
