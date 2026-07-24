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
        };
    },
}));

import GoogleComplete from './google-complete';

beforeEach(() => {
    mockPost.mockClear();
    mockErrors.current = {};
});

describe('GoogleComplete page', () => {
    it('shows the pending name and email', () => {
        render(<GoogleComplete name="Ada Lovelace" email="ada@example.com" documentsPublished />);

        expect(screen.getByText('Ada Lovelace')).toBeInTheDocument();
        expect(screen.getByText('ada@example.com')).toBeInTheDocument();
    });

    it('renders the terms checkbox unchecked by default', () => {
        render(<GoogleComplete name="Ada Lovelace" email="ada@example.com" documentsPublished />);

        expect(screen.getByRole('checkbox')).not.toBeChecked();
    });

    it('links the terms checkbox label to the Terms of Service and Privacy Policy pages', () => {
        render(<GoogleComplete name="Ada Lovelace" email="ada@example.com" documentsPublished />);

        expect(screen.getByRole('link', { name: 'Terms of Service' })).toHaveAttribute('href', '/legal/terms');
        expect(screen.getByRole('link', { name: 'Privacy Policy' })).toHaveAttribute('href', '/legal/privacy');
    });

    it('submits only the accepted terms flag to /auth/google/complete', async () => {
        const user = userEvent.setup();
        render(<GoogleComplete name="Ada Lovelace" email="ada@example.com" documentsPublished />);

        await user.click(screen.getByRole('checkbox'));
        await user.click(screen.getByRole('button', { name: 'Create account' }));

        expect(mockPost).toHaveBeenCalledWith('/auth/google/complete', { terms: true });
    });

    it('shows a terms validation error returned from the server', () => {
        mockErrors.current = { terms: 'Account creation is currently unavailable. Please try again later.' };
        render(<GoogleComplete name="Ada Lovelace" email="ada@example.com" documentsPublished />);

        expect(screen.getByRole('checkbox')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('Account creation is currently unavailable. Please try again later.')).toBeInTheDocument();
    });

    it('shows an honest unavailable state and no form when required documents are unpublished', () => {
        render(<GoogleComplete name="Ada Lovelace" email="ada@example.com" documentsPublished={false} />);

        expect(screen.getByText('Account creation is currently unavailable. Please try again later.')).toBeInTheDocument();
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Create account' })).not.toBeInTheDocument();
    });
});
