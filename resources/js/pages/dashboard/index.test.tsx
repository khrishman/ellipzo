import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        url: '/dashboard',
        props: { auth: { user: { id: 1, name: 'Ada Lovelace', email: 'ada@example.com', emailVerifiedAt: null } } },
        component: '',
        version: null,
    }),
}));

import Dashboard from './index';

describe('Dashboard eligibility section', () => {
    it('renders the eligible state with an explicit disclaimer that feature-specific access is checked separately', () => {
        render(<Dashboard eligibility={{ status: 'eligible', missingRequirements: [] }} />);

        expect(screen.getByText(/Account eligibility: Eligible/)).toBeInTheDocument();
        const disclaimer = screen.getByText(/general account requirements/i);
        expect(disclaimer.textContent).toMatch(/earning, advertising, deposits, and withdrawals/i);
        expect(disclaimer.textContent).toMatch(/checked independently/i);
    });

    it('renders pending status with controlled messages and real action links', () => {
        render(
            <Dashboard
                eligibility={{
                    status: 'pending',
                    missingRequirements: [{ code: 'email_not_verified' }, { code: 'profile_country_missing' }],
                }}
            />,
        );

        expect(screen.getByText(/Account eligibility: Pending/)).toBeInTheDocument();
        expect(screen.getByText('Verify your email address.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Verify email' })).toHaveAttribute('href', '/verify-email');
        expect(screen.getByText('Add your country.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Update profile' })).toHaveAttribute('href', '/settings/profile');
    });

    it('renders legal_consent_required with a link per missing document slug, using only safe context data', () => {
        render(
            <Dashboard
                eligibility={{
                    status: 'pending',
                    missingRequirements: [{ code: 'legal_consent_required', context: { documents: ['terms', 'privacy'] } }],
                }}
            />,
        );

        expect(screen.getByText('Review our updated legal documents.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Review terms' })).toHaveAttribute('href', '/legal/terms');
        expect(screen.getByRole('link', { name: 'Review privacy' })).toHaveAttribute('href', '/legal/privacy');
    });

    it('renders ineligible requirements with neutral, non-actionable text and no dead-end link', () => {
        render(
            <Dashboard
                eligibility={{
                    status: 'ineligible',
                    missingRequirements: [{ code: 'country_below_minimum_age' }, { code: 'country_not_enabled' }],
                }}
            />,
        );

        expect(screen.getByText(/Account eligibility: Not eligible/)).toBeInTheDocument();
        expect(screen.getByText(/do not currently meet the minimum age requirement/)).toBeInTheDocument();
        expect(screen.getByText(/not yet available in your country/)).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /verify|update profile|review/i })).not.toBeInTheDocument();
    });

    it('does not render the eligible disclaimer when the user is not eligible', () => {
        render(<Dashboard eligibility={{ status: 'pending', missingRequirements: [{ code: 'email_not_verified' }] }} />);

        expect(screen.queryByText(/general account requirements/i)).not.toBeInTheDocument();
    });

    it('falls back to a safe generic message for an unrecognized requirement code rather than rendering raw data', () => {
        render(<Dashboard eligibility={{ status: 'pending', missingRequirements: [{ code: 'some_future_code' }] }} />);

        expect(screen.getByText('Additional requirements apply.')).toBeInTheDocument();
    });
});
