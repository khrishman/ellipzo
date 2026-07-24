import { Head } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';

import AuthLayout from '@/layouts/auth-layout';

export default function Register() {
    return (
        <>
            <Head title="Get started" />
            <p className="text-body text-center text-neutral-600">Registration is not available yet. This page is being built.</p>
        </>
    );
}

Register.layout = (page: ReactElement) => (
    <AuthLayout title="Get started" description="Create your Ellipzo account.">
        {page as ReactNode}
    </AuthLayout>
);
