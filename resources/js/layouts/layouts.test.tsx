import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const { mockAuthUser } = vi.hoisted(() => ({
    mockAuthUser: vi.fn<() => { id: number; name: string; email: string; emailVerifiedAt: string | null } | null>(() => null),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/',
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

import AdminLayout from './admin-layout';
import AppLayout from './app-layout';
import AuthLayout from './auth-layout';
import PublicLayout from './public-layout';

describe('layout shells', () => {
    it('PublicLayout renders a main landmark and passes children through', () => {
        render(
            <PublicLayout>
                <p>public content</p>
            </PublicLayout>,
        );

        expect(screen.getByRole('main')).toHaveTextContent('public content');
    });

    it('AuthLayout renders the given title, description, and children without any form logic', () => {
        render(
            <AuthLayout title="Log in" description="Access your account.">
                <p>auth content</p>
            </AuthLayout>,
        );

        expect(screen.getByRole('heading', { name: 'Log in' })).toBeInTheDocument();
        expect(screen.getByText('Access your account.')).toBeInTheDocument();
        expect(screen.getByText('auth content')).toBeInTheDocument();
    });

    it('AppLayout renders a main landmark and does not fabricate a user when auth.user is null', () => {
        render(
            <AppLayout>
                <p>app content</p>
            </AppLayout>,
        );

        expect(screen.getByRole('main')).toHaveTextContent('app content');
        expect(screen.getByText('Account')).toBeInTheDocument();
        expect(screen.queryByText(/@/)).not.toBeInTheDocument();
    });

    it('AppLayout shows the real authenticated user from shared Inertia props', () => {
        mockAuthUser.mockReturnValueOnce({ id: 1, name: 'Ada Lovelace', email: 'ada@example.com', emailVerifiedAt: null });
        render(
            <AppLayout>
                <p>app content</p>
            </AppLayout>,
        );

        expect(screen.getByText('Ada Lovelace')).toBeInTheDocument();
        expect(screen.getByText('ada@example.com')).toBeInTheDocument();
    });

    it('AdminLayout renders a main landmark and the Admin badge', () => {
        render(
            <AdminLayout>
                <p>admin content</p>
            </AdminLayout>,
        );

        expect(screen.getByRole('main')).toHaveTextContent('admin content');
        expect(screen.getByText('Admin')).toBeInTheDocument();
    });
});
