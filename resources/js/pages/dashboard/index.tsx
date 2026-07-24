import { Head, usePage } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';

import AppLayout from '@/layouts/app-layout';

export default function Dashboard() {
    const {
        props: {
            auth: { user },
        },
    } = usePage();

    return (
        <>
            <Head title="Dashboard" />
            <div className="space-y-2">
                <h1 className="text-h1 text-neutral-900">Welcome{user ? `, ${user.name}` : ''}</h1>
                <p className="text-body text-neutral-600">Your account is set up and verified. Task and campaign features are still being built.</p>
            </div>
        </>
    );
}

Dashboard.layout = (page: ReactElement) => <AppLayout pageTitle="Dashboard">{page as ReactNode}</AppLayout>;
