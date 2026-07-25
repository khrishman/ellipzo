import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';

import AppLayout from '@/layouts/app-layout';

interface MissingRequirement {
    code: string;
    context?: { documents?: string[] };
}

interface EligibilityProps {
    status: 'pending' | 'eligible' | 'ineligible';
    missingRequirements: MissingRequirement[];
}

interface DashboardProps {
    eligibility: EligibilityProps;
}

const STATUS_LABEL: Record<EligibilityProps['status'], string> = {
    pending: 'Pending',
    eligible: 'Eligible',
    ineligible: 'Not eligible',
};

/**
 * A fixed, controlled mapping from machine-readable requirement codes to
 * user-facing copy - never arbitrary config/database text. Actions link
 * only to routes that already exist and do something real.
 */
function requirementMessage(requirement: MissingRequirement): { text: string; action?: { label: string; href: string } } {
    switch (requirement.code) {
        case 'email_not_verified':
            return { text: 'Verify your email address.', action: { label: 'Verify email', href: '/verify-email' } };
        case 'profile_date_of_birth_missing':
            return { text: 'Add your date of birth.', action: { label: 'Update profile', href: '/settings/profile' } };
        case 'profile_country_missing':
            return { text: 'Add your country.', action: { label: 'Update profile', href: '/settings/profile' } };
        case 'legal_consent_required':
            return { text: 'Review our updated legal documents.' };
        case 'country_below_minimum_age':
            return { text: 'You do not currently meet the minimum age requirement for your country.' };
        case 'country_not_enabled':
            return { text: 'This platform is not yet available in your country.' };
        case 'account_not_active':
            return { text: 'Your account currently has limited access. Contact support for details.' };
        default:
            return { text: 'Additional requirements apply.' };
    }
}

export default function Dashboard({ eligibility }: DashboardProps) {
    const {
        props: {
            auth: { user },
        },
    } = usePage();

    const documentsFor = (requirement: MissingRequirement): string[] =>
        requirement.code === 'legal_consent_required' ? (requirement.context?.documents ?? []) : [];

    return (
        <>
            <Head title="Dashboard" />
            <div className="space-y-6">
                <div className="space-y-2">
                    <h1 className="text-h1 text-neutral-900">Welcome{user ? `, ${user.name}` : ''}</h1>
                    <p className="text-body text-neutral-600">
                        Your account is set up and verified. Task and campaign features are still being built.
                    </p>
                </div>

                <div className="rounded-lg border border-neutral-200 bg-white p-6">
                    <h2 className="text-h4 text-neutral-900">Account eligibility: {STATUS_LABEL[eligibility.status]}</h2>

                    {eligibility.status === 'eligible' && (
                        <p className="text-body-sm mt-2 text-neutral-600">
                            You meet the platform&rsquo;s general account requirements. Earning, advertising, deposits, and withdrawals each have
                            their own separate requirements and are checked independently once available.
                        </p>
                    )}

                    {eligibility.missingRequirements.length > 0 && (
                        <ul className="mt-3 space-y-2">
                            {eligibility.missingRequirements.map((requirement) => {
                                const { text, action } = requirementMessage(requirement);
                                const documents = documentsFor(requirement);

                                return (
                                    <li key={requirement.code} className="text-body-sm text-neutral-600">
                                        <span>{text}</span>
                                        {action && (
                                            <>
                                                {' '}
                                                <Link href={action.href} className="focus-ring font-medium text-brand-700 hover:underline">
                                                    {action.label}
                                                </Link>
                                            </>
                                        )}
                                        {documents.length > 0 && (
                                            <span className="ml-1 space-x-2">
                                                {documents.map((slug) => (
                                                    <Link
                                                        key={slug}
                                                        href={`/legal/${slug}`}
                                                        className="focus-ring font-medium text-brand-700 hover:underline"
                                                    >
                                                        Review {slug}
                                                    </Link>
                                                ))}
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = (page: ReactElement) => <AppLayout pageTitle="Dashboard">{page as ReactNode}</AppLayout>;
