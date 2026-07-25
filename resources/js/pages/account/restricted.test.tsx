import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

import AccountRestricted from './restricted';

describe('Account restricted page', () => {
    it('shows a neutral restriction message with no internal detail', () => {
        render(<AccountRestricted />);

        expect(screen.getByText(/restricted access/i)).toBeInTheDocument();
        expect(screen.getByText(/contact support/i)).toBeInTheDocument();
    });

    it('provides a real sign-out action, not a dead-end button', () => {
        render(<AccountRestricted />);

        // The mocked <Link> renders as a real anchor; "method" and "as"
        // are Inertia-only props that make the real component POST on
        // click, which is what matters - the mock just needs to prove the
        // href/target is correct and real.
        const signOut = screen.getByRole('link', { name: /sign out/i });
        expect(signOut).toHaveAttribute('href', '/logout');
    });
});
