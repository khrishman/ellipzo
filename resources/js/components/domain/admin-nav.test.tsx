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
        expect(screen.getByRole('link', { name: /users/i })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /finance/i })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /audit/i })).toBeInTheDocument();
    });

    it('filters to only the items matching the supplied permissions, but always keeps items with no requirement', () => {
        render(<AdminNav permissions={['users.view']} />);

        // Overview has no requiredPermission, so it always renders.
        expect(screen.getByRole('link', { name: /overview/i })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /users/i })).toBeInTheDocument();

        // Everything requiring a permission the user does not hold is hidden.
        expect(screen.queryByRole('link', { name: /campaigns/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /finance/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /audit/i })).not.toBeInTheDocument();
    });

    it('renders nothing beyond permission-free items when the permission list is empty', () => {
        render(<AdminNav permissions={[]} />);

        expect(screen.getByRole('link', { name: /overview/i })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /users/i })).not.toBeInTheDocument();
    });

    it('marks the current route with aria-current="page"', () => {
        render(<AdminNav />);

        expect(screen.getByRole('link', { name: /overview/i })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('link', { name: /users/i })).not.toHaveAttribute('aria-current');
    });
});
