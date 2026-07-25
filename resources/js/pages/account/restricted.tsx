import { Head, Link } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';

import AuthLayout from '@/layouts/auth-layout';

export default function AccountRestricted() {
    return (
        <>
            <Head title="Account restricted" />
            <div className="space-y-4 text-center">
                <p className="text-body text-neutral-600">
                    Your account currently has restricted access. If you believe this is a mistake, please contact support.
                </p>
                <Link href="/logout" method="post" as="button" className="focus-ring text-body-sm font-medium text-brand-700 hover:underline">
                    Sign out
                </Link>
            </div>
        </>
    );
}

AccountRestricted.layout = (page: ReactElement) => <AuthLayout title="Account restricted">{page as ReactNode}</AuthLayout>;
