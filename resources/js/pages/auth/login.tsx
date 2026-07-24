import { Head } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';

import AuthLayout from '@/layouts/auth-layout';

export default function Login() {
    return (
        <>
            <Head title="Log in" />
            <p className="text-body text-center text-neutral-600">Sign-in is not available yet. This page is being built.</p>
        </>
    );
}

Login.layout = (page: ReactElement) => (
    <AuthLayout title="Log in" description="Access your Ellipzo account.">
        {page as ReactNode}
    </AuthLayout>
);
