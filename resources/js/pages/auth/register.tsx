import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEventHandler, ReactElement, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

interface RegisterForm {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
}

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post('/register', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Get started" />
            <form onSubmit={submit} className="space-y-5" noValidate>
                <div>
                    <label htmlFor="name" className="text-label block text-neutral-700">
                        Name
                    </label>
                    <input
                        id="name"
                        name="name"
                        type="text"
                        autoComplete="name"
                        required
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        aria-invalid={errors.name ? 'true' : undefined}
                        aria-describedby={errors.name ? 'name-error' : undefined}
                        className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400"
                    />
                    {errors.name && (
                        <p id="name-error" className="text-body-sm mt-1.5 text-danger-text">
                            {errors.name}
                        </p>
                    )}
                </div>

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
                        Password
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
                        Confirm password
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
                    Create account
                </Button>

                <p className="text-body-sm text-center text-neutral-500">
                    Already have an account?{' '}
                    <Link href="/login" className="focus-ring rounded-sm font-medium text-brand-700 hover:text-brand-800">
                        Log in
                    </Link>
                </p>
            </form>
        </>
    );
}

Register.layout = (page: ReactElement) => (
    <AuthLayout title="Get started" description="Create your Ellipzo account.">
        {page as ReactNode}
    </AuthLayout>
);
