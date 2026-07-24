import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEventHandler, ReactElement, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

interface LoginForm {
    email: string;
    password: string;
    remember: boolean;
}

interface LoginProps {
    status?: string;
}

export default function Login({ status }: LoginProps) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post('/login', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <>
            <Head title="Log in" />

            {status && (
                <p className="text-body-sm mb-4 rounded-md border border-status-success-border bg-status-success-bg p-3 text-status-success-text">
                    {status}
                </p>
            )}

            <Button asChild variant="secondary" className="w-full">
                <a href="/auth/google/redirect">Continue with Google</a>
            </Button>

            <div className="my-5 flex items-center gap-3">
                <div className="h-px flex-1 bg-neutral-200" />
                <span className="text-caption text-neutral-500">or</span>
                <div className="h-px flex-1 bg-neutral-200" />
            </div>

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
                    <div className="flex items-center justify-between">
                        <label htmlFor="password" className="text-label block text-neutral-700">
                            Password
                        </label>
                        <Link href="/forgot-password" className="focus-ring text-body-sm rounded-sm text-brand-700 hover:text-brand-800">
                            Forgot password?
                        </Link>
                    </div>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autoComplete="current-password"
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

                <label className="flex items-center gap-2">
                    <input
                        id="remember"
                        name="remember"
                        type="checkbox"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                        className="focus-ring size-4 rounded border-neutral-300 text-brand-600"
                    />
                    <span className="text-body-sm text-neutral-700">Remember me</span>
                </label>

                <Button type="submit" className="w-full" isLoading={processing}>
                    Log in
                </Button>

                <p className="text-body-sm text-center text-neutral-500">
                    Don&apos;t have an account?{' '}
                    <Link href="/register" className="focus-ring rounded-sm font-medium text-brand-700 hover:text-brand-800">
                        Get started
                    </Link>
                </p>
            </form>
        </>
    );
}

Login.layout = (page: ReactElement) => (
    <AuthLayout title="Log in" description="Access your Ellipzo account.">
        {page as ReactNode}
    </AuthLayout>
);
