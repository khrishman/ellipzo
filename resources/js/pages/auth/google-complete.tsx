import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEventHandler, ReactElement, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

interface GoogleCompleteForm {
    terms: boolean;
}

interface GoogleCompleteProps {
    name: string;
    email: string;
    documentsPublished: boolean;
}

export default function GoogleComplete({ name, email, documentsPublished }: GoogleCompleteProps) {
    const { data, setData, post, processing, errors } = useForm<GoogleCompleteForm>({
        terms: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post('/auth/google/complete');
    };

    if (!documentsPublished) {
        return (
            <>
                <Head title="Finish signing up" />
                <p className="text-body-sm rounded-md border border-status-neutral-border bg-status-neutral-bg p-4 text-status-neutral-text">
                    Account creation is currently unavailable. Please try again later.
                </p>
            </>
        );
    }

    return (
        <>
            <Head title="Finish signing up" />

            <div className="mb-5 rounded-md border border-neutral-200 bg-neutral-50 p-3">
                <p className="text-body-sm text-neutral-700">{name}</p>
                <p className="text-body-sm text-neutral-500">{email}</p>
            </div>

            <form onSubmit={submit} className="space-y-5" noValidate>
                <div>
                    <label className="flex items-start gap-2">
                        <input
                            id="terms"
                            name="terms"
                            type="checkbox"
                            checked={data.terms}
                            onChange={(e) => setData('terms', e.target.checked)}
                            aria-invalid={errors.terms ? 'true' : undefined}
                            aria-describedby={errors.terms ? 'terms-error' : undefined}
                            className="focus-ring mt-0.5 size-4 rounded border-neutral-300 text-brand-600"
                        />
                        <span className="text-body-sm text-neutral-700">
                            I agree to the{' '}
                            <Link
                                href="/legal/terms"
                                target="_blank"
                                className="focus-ring rounded-sm font-medium text-brand-700 hover:text-brand-800"
                            >
                                Terms of Service
                            </Link>{' '}
                            and{' '}
                            <Link
                                href="/legal/privacy"
                                target="_blank"
                                className="focus-ring rounded-sm font-medium text-brand-700 hover:text-brand-800"
                            >
                                Privacy Policy
                            </Link>
                            .
                        </span>
                    </label>
                    {errors.terms && (
                        <p id="terms-error" className="text-body-sm mt-1.5 text-danger-text">
                            {errors.terms}
                        </p>
                    )}
                </div>

                <Button type="submit" className="w-full" isLoading={processing}>
                    Create account
                </Button>
            </form>
        </>
    );
}

GoogleComplete.layout = (page: ReactElement) => (
    <AuthLayout title="Finish signing up" description="One more step to finish creating your Ellipzo account.">
        {page as ReactNode}
    </AuthLayout>
);
