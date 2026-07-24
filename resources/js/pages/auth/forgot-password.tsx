import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler, ReactElement, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

interface ForgotPasswordForm {
    email: string;
}

interface ForgotPasswordProps {
    status?: string;
}

export default function ForgotPassword({ status }: ForgotPasswordProps) {
    const { data, setData, post, processing, errors } = useForm<ForgotPasswordForm>({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post('/forgot-password');
    };

    return (
        <>
            <Head title="Forgot password" />

            <p className="text-body-sm mb-5 text-neutral-600">
                Enter your email address and, if it matches an account, we&apos;ll send a link to reset your password.
            </p>

            {status && (
                <p className="text-body-sm mb-4 rounded-md border border-status-success-border bg-status-success-bg p-3 text-status-success-text">
                    {status}
                </p>
            )}

            <form onSubmit={submit} className="space-y-5" noValidate>
                <div>
                    <label htmlFor="email" className="text-label block text-neutral-700">
                        Email
                    </label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        autoComplete="username"
                        required
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        aria-invalid={errors.email ? 'true' : undefined}
                        aria-describedby={errors.email ? 'email-error' : undefined}
                        className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400"
                    />
                    {errors.email && (
                        <p id="email-error" className="text-body-sm mt-1.5 text-danger-text">
                            {errors.email}
                        </p>
                    )}
                </div>

                <Button type="submit" className="w-full" isLoading={processing}>
                    Email password reset link
                </Button>
            </form>
        </>
    );
}

ForgotPassword.layout = (page: ReactElement) => (
    <AuthLayout title="Forgot your password?" description="We'll help you get back in.">
        {page as ReactNode}
    </AuthLayout>
);
