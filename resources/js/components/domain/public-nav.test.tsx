import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

const { mockAuthUser } = vi.hoisted(() => ({
    mockAuthUser: vi.fn<() => { id: number; name: string; email: string; emailVerifiedAt: string | null } | null>(() => null),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/earn',
        props: { auth: { user: mockAuthUser() } },
        component: '',
        version: null,
    }),
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

import { PublicNav } from './public-nav';

describe('PublicNav', () => {
    it('renders the logo, real destination links, and no notification bell for a guest', () => {
        render(<PublicNav />);

        expect(screen.getByRole('link', { name: 'Ellipzo' })).toHaveAttribute('href', '/');
        expect(screen.getAllByRole('link', { name: 'Earn' })[0]).toHaveAttribute('href', '/earn');
        expect(screen.getAllByRole('link', { name: 'Advertise' })[0]).toHaveAttribute('href', '/advertise');
        expect(screen.getAllByRole('link', { name: 'Get started' })[0]).toHaveAttribute('href', '/register');
        expect(screen.getAllByRole('link', { name: 'Log in' })[0]).toHaveAttribute('href', '/login');

        // design.md: public pages never show an authenticated notification bell.
        expect(screen.queryByRole('button', { name: /notification/i })).not.toBeInTheDocument();
    });

    it('marks the active section with aria-current="page"', () => {
        render(<PublicNav />);

        const earnLinks = screen.getAllByRole('link', { name: 'Earn' });
        expect(earnLinks[0]).toHaveAttribute('aria-current', 'page');
    });

    it('opens the mobile menu sheet from a closed state when the menu button is activated', async () => {
        const user = userEvent.setup();
        render(<PublicNav />);

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /open menu/i }));

        expect(await screen.findByRole('dialog')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Menu' })).toBeInTheDocument();
    });

    it('shows a Dashboard link instead of Log in / Get started when the user is authenticated', () => {
        mockAuthUser.mockReturnValueOnce({ id: 1, name: 'Ada', email: 'ada@example.com', emailVerifiedAt: null });
        render(<PublicNav />);

        expect(screen.getAllByRole('link', { name: 'Dashboard' })[0]).toHaveAttribute('href', '/dashboard');
        expect(screen.queryByRole('link', { name: 'Log in' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Get started' })).not.toBeInTheDocument();
    });
});
