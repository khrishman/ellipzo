import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler, ReactElement, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

interface ConfirmPasswordForm {
    password: string;
}

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm<ConfirmPasswordForm>({
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post('/confirm-password', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <>
            <Head title="Confirm password" />

            <p className="text-body-sm mb-5 text-neutral-600">This is a sensitive area. Please confirm your password before continuing.</p>

            <form onSubmit={submit} className="space-y-5" noValidate>
                <div>
                    <label htmlFor="password" className="text-label block text-neutral-700">
                        Password
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autoComplete="current-password"
                        autoFocus
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

                <Button type="submit" className="w-full" isLoading={processing}>
                    Confirm
                </Button>
            </form>
        </>
    );
}

ConfirmPassword.layout = (page: ReactElement) => (
    <AuthLayout title="Confirm password" description="For your security, please confirm your password to continue.">
        {page as ReactNode}
    </AuthLayout>
);
