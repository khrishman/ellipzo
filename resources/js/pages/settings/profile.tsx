import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler, ReactElement, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';

interface ProfileForm {
    username: string;
    date_of_birth: string;
    country_code: string;
    locale: string;
    timezone: string;
}

interface ProfileProps {
    profile: {
        username: string | null;
        dateOfBirth: string | null;
        countryCode: string | null;
        locale: string | null;
        timezone: string | null;
    };
    status?: string;
}

export default function Profile({ profile, status }: ProfileProps) {
    const { data, setData, patch, processing, errors } = useForm<ProfileForm>({
        username: profile.username ?? '',
        date_of_birth: profile.dateOfBirth ?? '',
        country_code: profile.countryCode ?? '',
        locale: profile.locale ?? '',
        timezone: profile.timezone ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch('/settings/profile');
    };

    return (
        <>
            <Head title="Profile settings" />

            <div className="max-w-lg space-y-6">
                <div>
                    <h1 className="text-h2 text-neutral-900">Profile</h1>
                    <p className="text-body-sm mt-1 text-neutral-500">Update your profile details.</p>
                </div>

                {status === 'profile-updated' && (
                    <p className="text-body-sm rounded-md border border-status-success-border bg-status-success-bg p-3 text-status-success-text">
                        Your profile has been updated.
                    </p>
                )}

                <form onSubmit={submit} className="space-y-5" noValidate>
                    <div>
                        <label htmlFor="username" className="text-label block text-neutral-700">
                            Username
                        </label>
                        <input
                            id="username"
                            name="username"
                            type="text"
                            autoComplete="username"
                            value={data.username}
                            onChange={(e) => setData('username', e.target.value)}
                            aria-invalid={errors.username ? 'true' : undefined}
                            aria-describedby={errors.username ? 'username-error' : undefined}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400"
                        />
                        {errors.username && (
                            <p id="username-error" className="text-body-sm mt-1.5 text-danger-text">
                                {errors.username}
                            </p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="date_of_birth" className="text-label block text-neutral-700">
                            Date of birth
                        </label>
                        <input
                            id="date_of_birth"
                            name="date_of_birth"
                            type="date"
                            value={data.date_of_birth}
                            onChange={(e) => setData('date_of_birth', e.target.value)}
                            aria-invalid={errors.date_of_birth ? 'true' : undefined}
                            aria-describedby={errors.date_of_birth ? 'date-of-birth-error' : undefined}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900"
                        />
                        {errors.date_of_birth && (
                            <p id="date-of-birth-error" className="text-body-sm mt-1.5 text-danger-text">
                                {errors.date_of_birth}
                            </p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="country_code" className="text-label block text-neutral-700">
                            Country code
                        </label>
                        <p className="text-caption mt-1 text-neutral-500">Two-letter code, for example US or NP.</p>
                        <input
                            id="country_code"
                            name="country_code"
                            type="text"
                            maxLength={2}
                            value={data.country_code}
                            onChange={(e) => setData('country_code', e.target.value)}
                            aria-invalid={errors.country_code ? 'true' : undefined}
                            aria-describedby={errors.country_code ? 'country-code-error' : undefined}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400"
                        />
                        {errors.country_code && (
                            <p id="country-code-error" className="text-body-sm mt-1.5 text-danger-text">
                                {errors.country_code}
                            </p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="locale" className="text-label block text-neutral-700">
                            Locale
                        </label>
                        <p className="text-caption mt-1 text-neutral-500">For example en or en-US.</p>
                        <input
                            id="locale"
                            name="locale"
                            type="text"
                            value={data.locale}
                            onChange={(e) => setData('locale', e.target.value)}
                            aria-invalid={errors.locale ? 'true' : undefined}
                            aria-describedby={errors.locale ? 'locale-error' : undefined}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400"
                        />
                        {errors.locale && (
                            <p id="locale-error" className="text-body-sm mt-1.5 text-danger-text">
                                {errors.locale}
                            </p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="timezone" className="text-label block text-neutral-700">
                            Timezone
                        </label>
                        <p className="text-caption mt-1 text-neutral-500">IANA timezone name, for example Asia/Kathmandu.</p>
                        <input
                            id="timezone"
                            name="timezone"
                            type="text"
                            value={data.timezone}
                            onChange={(e) => setData('timezone', e.target.value)}
                            aria-invalid={errors.timezone ? 'true' : undefined}
                            aria-describedby={errors.timezone ? 'timezone-error' : undefined}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400"
                        />
                        {errors.timezone && (
                            <p id="timezone-error" className="text-body-sm mt-1.5 text-danger-text">
                                {errors.timezone}
                            </p>
                        )}
                    </div>

                    <Button type="submit" isLoading={processing}>
                        Save
                    </Button>
                </form>
            </div>
        </>
    );
}

Profile.layout = (page: ReactElement) => <AppLayout pageTitle="Profile">{page as ReactNode}</AppLayout>;
