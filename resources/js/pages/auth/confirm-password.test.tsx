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

import ConfirmPassword from './confirm-password';

beforeEach(() => {
    mockPost.mockClear();
    mockErrors.current = {};
});

describe('ConfirmPassword page', () => {
    it('submits the entered password to /confirm-password', async () => {
        const user = userEvent.setup();
        render(<ConfirmPassword />);

        await user.type(screen.getByLabelText('Password'), 'Password!123');
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(mockPost).toHaveBeenCalledWith('/confirm-password', { password: 'Password!123' });
    });

    it('clears the password field after submission finishes', async () => {
        const user = userEvent.setup();
        render(<ConfirmPassword />);

        await user.type(screen.getByLabelText('Password'), 'Password!123');
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(screen.getByLabelText('Password')).toHaveValue('');
    });

    it('shows an incorrect-password validation error returned from the server', () => {
        mockErrors.current = { password: 'The password is incorrect.' };
        render(<ConfirmPassword />);

        expect(screen.getByLabelText('Password')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('The password is incorrect.')).toBeInTheDocument();
    });
});
