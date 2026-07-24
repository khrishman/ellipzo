import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEventHandler, ReactElement, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

interface VerifyEmailProps {
    status?: string;
}

export default function VerifyEmail({ status }: VerifyEmailProps) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post('/email/verification-notification');
    };

    const verificationLinkSent = status === 'verification-link-sent';

    return (
        <>
            <Head title="Verify email" />

            <p className="text-body-sm text-neutral-600">
                Thanks for signing up. Before getting started, please verify your email address by clicking the link we just emailed to you. If you
                didn&apos;t receive the email, we can send another.
            </p>

            {verificationLinkSent && (
                <p className="text-body-sm mt-4 rounded-md border border-status-success-border bg-status-success-bg p-3 text-status-success-text">
                    A new verification link has been sent to your email address.
                </p>
            )}

            <form onSubmit={submit} className="mt-6 flex flex-col gap-3">
                <Button type="submit" className="w-full" isLoading={processing}>
                    Resend verification email
                </Button>

                <Button asChild variant="ghost" className="w-full">
                    <Link href="/logout" method="post" as="button">
                        Log out
                    </Link>
                </Button>
            </form>
        </>
    );
}

VerifyEmail.layout = (page: ReactElement) => (
    <AuthLayout title="Verify your email" description="One more step before you can continue.">
        {page as ReactNode}
    </AuthLayout>
);
