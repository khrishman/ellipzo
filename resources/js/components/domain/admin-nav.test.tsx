import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/admin', props: {}, component: '', version: null }),
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

import { AdminNav } from './admin-nav';

describe('AdminNav', () => {
    it('renders every item when no permission list is supplied, without enforcing any authorization itself', () => {
        render(<AdminNav />);

        expect(screen.getByRole('link', { name: /overview/i })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /staff access/i })).toBeInTheDocument();
        // Users/Finance/Audit have no working route yet, so they never
        // render as links even when every permission is present.
        expect(screen.queryByRole('link', { name: /^users$/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /^finance$/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /^audit$/i })).not.toBeInTheDocument();
    });

    it('filters to only the items matching the supplied permissions, but always keeps items with no requirement', () => {
        render(<AdminNav permissions={['admin.overview.view', 'staff.view']} />);

        expect(screen.getByRole('link', { name: /overview/i })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /staff access/i })).toBeInTheDocument();

        // Campaigns requires a permission this list does not include.
        expect(screen.queryByText(/^campaigns$/i)).not.toBeInTheDocument();
    });

    it('renders nothing when the permission list is empty and every item requires a permission', () => {
        render(<AdminNav permissions={[]} />);

        expect(screen.queryByRole('link', { name: /overview/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /staff access/i })).not.toBeInTheDocument();
    });

    it('marks the current route with aria-current="page"', () => {
        render(<AdminNav />);

        expect(screen.getByRole('link', { name: /overview/i })).toHaveAttribute('aria-current', 'page');
    });

    it('renders the Ledger item only when ledger.view is present, linking to /admin/ledger', () => {
        render(<AdminNav permissions={['ledger.view']} />);

        const link = screen.getByRole('link', { name: /ledger/i });
        expect(link).toHaveAttribute('href', '/admin/ledger');
        // A real, working item - never rendered with the "Soon" badge.
        expect(screen.queryByText(/soon/i)).not.toBeInTheDocument();
    });

    it('does not render the Ledger item without ledger.view', () => {
        render(<AdminNav permissions={['admin.overview.view', 'staff.view']} />);

        expect(screen.queryByRole('link', { name: /ledger/i })).not.toBeInTheDocument();
        expect(screen.queryByText(/^ledger$/i)).not.toBeInTheDocument();
    });

    it('renders unimplemented sections as disabled, non-interactive items rather than links', () => {
        render(<AdminNav permissions={['users.view', 'campaigns.moderate', 'audit.view']} />);

        const users = screen.getByText(/^users$/i);
        expect(users.closest('a')).toBeNull();
        expect(users.closest('[aria-disabled="true"]')).not.toBeNull();

        const campaigns = screen.getByText(/^campaigns$/i);
        expect(campaigns.closest('a')).toBeNull();

        // None of the unimplemented items are ever links, regardless of href.
        expect(screen.queryByRole('link', { name: /users/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /campaigns/i })).not.toBeInTheDocument();
    });
});
