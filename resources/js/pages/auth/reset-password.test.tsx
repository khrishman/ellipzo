import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

const { mockPost, mockErrors } = vi.hoisted(() => ({
    mockPost: vi.fn(),
    mockErrors: { current: {} as Record<string, string> },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    useForm: (initialData: Record<string, unknown>) => {
        const [data, setDataState] = useState(initialData);

        return {
            data,
            setData: (key: string, value: unknown) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url: string, options?: { onFinish?: () => void }) => {
                mockPost(url, data);
                options?.onFinish?.();
            },
            processing: false,
            errors: mockErrors.current,
            reset: (...fields: string[]) => {
                setDataState((prev) => {
                    const next = { ...prev };
                    const keys = fields.length ? fields : Object.keys(initialData);
                    keys.forEach((key) => {
                        next[key] = initialData[key];
                    });
                    return next;
                });
            },
        };
    },
}));

import ResetPassword from './reset-password';

beforeEach(() => {
    mockPost.mockClear();
    mockErrors.current = {};
});

describe('ResetPassword page', () => {
    it('pre-fills the token and email from the server and submits the new password to /reset-password', async () => {
        const user = userEvent.setup();
        render(<ResetPassword token="a-real-token" email="ada@example.com" />);

        expect(screen.getByLabelText('Email')).toHaveValue('ada@example.com');

        await user.type(screen.getByLabelText('New password'), 'NewPassword!123');
        await user.type(screen.getByLabelText('Confirm new password'), 'NewPassword!123');
        await user.click(screen.getByRole('button', { name: 'Reset password' }));

        expect(mockPost).toHaveBeenCalledWith('/reset-password', {
            token: 'a-real-token',
            email: 'ada@example.com',
            password: 'NewPassword!123',
            password_confirmation: 'NewPassword!123',
        });
    });

    it('renders an empty email field when no email query parameter was present', () => {
        render(<ResetPassword token="a-real-token" email={null} />);

        expect(screen.getByLabelText('Email')).toHaveValue('');
    });

    it('shows an invalid-or-expired token error on the email field', () => {
        mockErrors.current = { email: 'This password reset link is invalid or has expired.' };
        render(<ResetPassword token="a-stale-token" email="ada@example.com" />);

        expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('This password reset link is invalid or has expired.')).toBeInTheDocument();
    });

    it('clears the password fields after submission finishes', async () => {
        const user = userEvent.setup();
        render(<ResetPassword token="a-real-token" email="ada@example.com" />);

        await user.type(screen.getByLabelText('New password'), 'NewPassword!123');
        await user.type(screen.getByLabelText('Confirm new password'), 'NewPassword!123');
        await user.click(screen.getByRole('button', { name: 'Reset password' }));

        expect(screen.getByLabelText('New password')).toHaveValue('');
        expect(screen.getByLabelText('Confirm new password')).toHaveValue('');
    });
});
