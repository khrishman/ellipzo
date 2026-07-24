import { Head, Link } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';

interface WelcomeProps {
    laravelVersion: string;
    phpVersion: string;
}

export default function Welcome({ laravelVersion, phpVersion }: WelcomeProps) {
    return (
        <>
            <Head title="Welcome" />
            <div className="mx-auto flex max-w-[1200px] flex-col items-center gap-6 px-4 py-16 text-center sm:px-6 lg:px-8">
                <h1 className="text-display text-neutral-900">Complete real work. Earn real rewards.</h1>
                <p className="text-body-lg max-w-2xl text-neutral-600">
                    Ellipzo is a micro-earning and advertising platform. Eligible users complete legitimate tasks, surveys, and offerwall activities
                    that pass review — and can fund their own advertising campaigns from the same account.
                </p>
                <p className="text-body-sm text-neutral-500">Ellipzo is not an investment platform. Earnings require completed and approved work.</p>
                <div className="mt-2 flex flex-col gap-3 sm:flex-row">
                    <Button asChild size="lg">
                        <Link href="/register">Get started</Link>
                    </Button>
                    <Button asChild variant="secondary" size="lg">
                        <Link href="/advertise">Advertise</Link>
                    </Button>
                </div>

                <div className="shadow-card mt-8 rounded-xl border border-neutral-200 bg-white p-6">
                    <p className="text-caption text-neutral-500">
                        Foundation status: Laravel {laravelVersion} &middot; PHP {phpVersion} &middot; React + Inertia + Tailwind
                    </p>
                </div>
            </div>
        </>
    );
}

Welcome.layout = (page: ReactElement) => <PublicLayout>{page as ReactNode}</PublicLayout>;
