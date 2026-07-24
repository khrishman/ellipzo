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
            reset: vi.fn(),
        };
    },
}));

import ForgotPassword from './forgot-password';

beforeEach(() => {
    mockPost.mockClear();
    mockErrors.current = {};
});

describe('ForgotPassword page', () => {
    it('submits the entered email to /forgot-password', async () => {
        const user = userEvent.setup();
        render(<ForgotPassword />);

        await user.type(screen.getByLabelText('Email'), 'ada@example.com');
        await user.click(screen.getByRole('button', { name: 'Email password reset link' }));

        expect(mockPost).toHaveBeenCalledWith('/forgot-password', { email: 'ada@example.com' });
    });

    it('renders the generic status message identically regardless of whether the account exists', () => {
        render(<ForgotPassword status="If an account matches that email, we've sent a password reset link." />);

        expect(screen.getByText("If an account matches that email, we've sent a password reset link.")).toBeInTheDocument();
    });

    it('shows a validation error for an invalid email', () => {
        mockErrors.current = { email: 'Please enter a valid email address.' };
        render(<ForgotPassword />);

        expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('Please enter a valid email address.')).toBeInTheDocument();
    });
});
