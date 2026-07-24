import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const { mockUrl } = vi.hoisted(() => ({
    mockUrl: vi.fn(() => '/tasks'),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: mockUrl(), props: {}, component: '', version: null }),
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

import { MobileBottomNav } from './mobile-bottom-nav';

describe('MobileBottomNav', () => {
    it('renders all five items with real, distinct destinations', () => {
        render(<MobileBottomNav />);

        const links = screen.getAllByRole('link');
        expect(links).toHaveLength(5);
        expect(screen.getByRole('link', { name: /home/i })).toHaveAttribute('href', '/dashboard');
        expect(screen.getByRole('link', { name: /earn/i })).toHaveAttribute('href', '/tasks');
        expect(screen.getByRole('link', { name: /advertise/i })).toHaveAttribute('href', '/advertise');
        expect(screen.getByRole('link', { name: /activity/i })).toHaveAttribute('href', '/wallet/transactions');
        expect(screen.getByRole('link', { name: /menu/i })).toHaveAttribute('href', '/settings/profile');
    });

    it('marks only the item matching the current URL as the current page', () => {
        render(<MobileBottomNav />);

        expect(screen.getByRole('link', { name: /earn/i })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('link', { name: /home/i })).not.toHaveAttribute('aria-current');
        expect(screen.getByRole('link', { name: /advertise/i })).not.toHaveAttribute('aria-current');
    });

    it('matches sub-paths of a nav item as active, not just the exact path', () => {
        mockUrl.mockReturnValueOnce('/wallet/transactions/123');
        render(<MobileBottomNav />);

        expect(screen.getByRole('link', { name: /activity/i })).toHaveAttribute('aria-current', 'page');
    });
});
