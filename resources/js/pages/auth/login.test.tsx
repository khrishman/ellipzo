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

import Login from './login';

beforeEach(() => {
    mockPost.mockClear();
    mockErrors.current = {};
});

describe('Login page', () => {
    it('submits the entered email, password, and remember flag to /login', async () => {
        const user = userEvent.setup();
        render(<Login />);

        await user.type(screen.getByLabelText('Email'), 'ada@example.com');
        await user.type(screen.getByLabelText('Password'), 'Password!123');
        await user.click(screen.getByLabelText('Remember me'));
        await user.click(screen.getByRole('button', { name: 'Log in' }));

        expect(mockPost).toHaveBeenCalledWith('/login', {
            email: 'ada@example.com',
            password: 'Password!123',
            remember: true,
        });
    });

    it('clears only the password field after a submission finishes', async () => {
        const user = userEvent.setup();
        render(<Login />);

        await user.type(screen.getByLabelText('Email'), 'ada@example.com');
        await user.type(screen.getByLabelText('Password'), 'Password!123');
        await user.click(screen.getByRole('button', { name: 'Log in' }));

        expect(screen.getByLabelText('Password')).toHaveValue('');
        expect(screen.getByLabelText('Email')).toHaveValue('ada@example.com');
    });

    it('shows the generic credentials error message returned from the server', () => {
        mockErrors.current = { email: 'These credentials do not match our records.' };
        render(<Login />);

        const emailInput = screen.getByLabelText('Email');
        expect(emailInput).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('These credentials do not match our records.')).toBeInTheDocument();
    });

    it('renders a status message when one is provided', () => {
        render(<Login status="Your password has been reset." />);

        expect(screen.getByText('Your password has been reset.')).toBeInTheDocument();
    });

    it('links to the forgot-password and registration screens', () => {
        render(<Login />);

        expect(screen.getByRole('link', { name: 'Forgot password?' })).toHaveAttribute('href', '/forgot-password');
        expect(screen.getByRole('link', { name: 'Get started' })).toHaveAttribute('href', '/register');
    });
});
