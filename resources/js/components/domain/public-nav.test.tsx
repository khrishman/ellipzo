import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/earn', props: {}, component: '', version: null }),
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

import { PublicNav } from './public-nav';

describe('PublicNav', () => {
    it('renders the logo, real destination links, and no notification bell', () => {
        render(<PublicNav />);

        expect(screen.getByRole('link', { name: 'Ellipzo' })).toHaveAttribute('href', '/');
        expect(screen.getAllByRole('link', { name: 'Earn' })[0]).toHaveAttribute('href', '/earn');
        expect(screen.getAllByRole('link', { name: 'Advertise' })[0]).toHaveAttribute('href', '/advertise');
        expect(screen.getAllByRole('link', { name: 'Get started' })[0]).toHaveAttribute('href', '/register');

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
});
