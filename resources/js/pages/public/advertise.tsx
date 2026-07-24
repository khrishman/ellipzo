import { Head } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';

import PublicLayout from '@/layouts/public-layout';

export default function Advertise() {
    return (
        <>
            <Head title="Advertise" />
            <div className="mx-auto max-w-[760px] px-4 py-16 sm:px-6 lg:px-8">
                <h1 className="text-h1 text-neutral-900">Advertise</h1>
                <p className="text-body-lg mt-4 text-neutral-600">This page is being built.</p>
            </div>
        </>
    );
}

Advertise.layout = (page: ReactElement) => <PublicLayout>{page as ReactNode}</PublicLayout>;
