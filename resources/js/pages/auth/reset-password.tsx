import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler, ReactElement, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

interface ResetPasswordForm {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
}

interface ResetPasswordProps {
    token: string;
    email: string | null;
}

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ResetPasswordForm>({
        token,
        email: email ?? '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post('/reset-password', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Reset password" />
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

                <div>
                    <label htmlFor="password" className="text-label block text-neutral-700">
                        New password
                    </label>
                    <p className="text-caption mt-1 text-neutral-500">
                        At least 8 characters, with upper and lower case letters, a number, and a symbol.
                    </p>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autoComplete="new-password"
                        required
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        aria-invalid={errors.password ? 'true' : undefined}
                        aria-describedby={errors.password ? 'password-error' : undefined}
                        className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400"
                    />
                    {errors.password && (
                        <p id="password-error" className="text-body-sm mt-1.5 text-danger-text">
                            {errors.password}
                        </p>
                    )}
                </div>

                <div>
                    <label htmlFor="password_confirmation" className="text-label block text-neutral-700">
                        Confirm new password
                    </label>
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        required
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        aria-invalid={errors.password_confirmation ? 'true' : undefined}
                        aria-describedby={errors.password_confirmation ? 'password-confirmation-error' : undefined}
                        className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400"
                    />
                    {errors.password_confirmation && (
                        <p id="password-confirmation-error" className="text-body-sm mt-1.5 text-danger-text">
                            {errors.password_confirmation}
                        </p>
                    )}
                </div>

                <Button type="submit" className="w-full" isLoading={processing}>
                    Reset password
                </Button>
            </form>
        </>
    );
}

ResetPassword.layout = (page: ReactElement) => (
    <AuthLayout title="Reset your password" description="Choose a new password for your account.">
        {page as ReactNode}
    </AuthLayout>
);
