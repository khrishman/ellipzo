import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/dashboard',
        props: { auth: { user: { id: 1, name: 'Ada Lovelace', email: 'ada@example.com', emailVerifiedAt: null } } },
        component: '',
        version: null,
    }),
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

import { AppSidebar } from './app-sidebar';

describe('AppSidebar', () => {
    it('links Transactions to the real /transactions route, not the old unbuilt placeholder path', () => {
        render(<AppSidebar />);

        expect(screen.getByRole('link', { name: /transactions/i })).toHaveAttribute('href', '/transactions');
    });
});
