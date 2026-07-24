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

import Register from './register';

beforeEach(() => {
    mockPost.mockClear();
    mockErrors.current = {};
});

describe('Register page', () => {
    it('submits name, email, password, and an accepted terms flag to /register', async () => {
        const user = userEvent.setup();
        render(<Register />);

        await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
        await user.type(screen.getByLabelText('Email'), 'ada@example.com');
        await user.type(screen.getByLabelText('Password'), 'Password!123');
        await user.type(screen.getByLabelText('Confirm password'), 'Password!123');
        await user.click(screen.getByRole('checkbox'));
        await user.click(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledWith('/register', {
            name: 'Ada Lovelace',
            email: 'ada@example.com',
            password: 'Password!123',
            password_confirmation: 'Password!123',
            terms: true,
        });
    });

    it('renders the terms checkbox unchecked by default', () => {
        render(<Register />);

        expect(screen.getByRole('checkbox')).not.toBeChecked();
    });

    it('links the terms checkbox label to the Terms of Service and Privacy Policy pages', () => {
        render(<Register />);

        expect(screen.getByRole('link', { name: 'Terms of Service' })).toHaveAttribute('href', '/legal/terms');
        expect(screen.getByRole('link', { name: 'Privacy Policy' })).toHaveAttribute('href', '/legal/privacy');
    });

    it('shows a terms validation error returned from the server', () => {
        mockErrors.current = { terms: 'Registration is currently unavailable. Please try again later.' };
        render(<Register />);

        expect(screen.getByRole('checkbox')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('Registration is currently unavailable. Please try again later.')).toBeInTheDocument();
    });

    it('clears both password fields but keeps name and email after submission finishes', async () => {
        const user = userEvent.setup();
        render(<Register />);

        await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
        await user.type(screen.getByLabelText('Email'), 'ada@example.com');
        await user.type(screen.getByLabelText('Password'), 'Password!123');
        await user.type(screen.getByLabelText('Confirm password'), 'Password!123');
        await user.click(screen.getByRole('button', { name: 'Create account' }));

        expect(screen.getByLabelText('Password')).toHaveValue('');
        expect(screen.getByLabelText('Confirm password')).toHaveValue('');
        expect(screen.getByLabelText('Name')).toHaveValue('Ada Lovelace');
        expect(screen.getByLabelText('Email')).toHaveValue('ada@example.com');
    });

    it('shows a duplicate-email validation error returned from the server', () => {
        mockErrors.current = { email: 'The email has already been taken.' };
        render(<Register />);

        expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('The email has already been taken.')).toBeInTheDocument();
    });

    it('shows a weak-password validation error returned from the server', () => {
        mockErrors.current = { password: 'The password is too common.' };
        render(<Register />);

        expect(screen.getByLabelText('Password')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('The password is too common.')).toBeInTheDocument();
    });

    it('links to the login screen', () => {
        render(<Register />);

        expect(screen.getByRole('link', { name: 'Log in' })).toHaveAttribute('href', '/login');
    });
});
