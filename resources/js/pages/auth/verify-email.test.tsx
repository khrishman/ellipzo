import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';

const { mockPost } = vi.hoisted(() => ({
    mockPost: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    useForm: () => ({
        post: (url: string) => mockPost(url),
        processing: false,
    }),
}));

import VerifyEmail from './verify-email';

beforeEach(() => {
    mockPost.mockClear();
});

describe('VerifyEmail page', () => {
    it('explains that an email was sent and offers to resend it', () => {
        render(<VerifyEmail />);

        expect(screen.getByText(/verify your email address/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Resend verification email' })).toBeInTheDocument();
    });

    it('posts to /email/verification-notification when resend is clicked', async () => {
        const user = userEvent.setup();
        render(<VerifyEmail />);

        await user.click(screen.getByRole('button', { name: 'Resend verification email' }));

        expect(mockPost).toHaveBeenCalledWith('/email/verification-notification');
    });

    it('shows a confirmation message only after a link has actually been sent', () => {
        const { rerender } = render(<VerifyEmail />);
        expect(screen.queryByText(/new verification link has been sent/i)).not.toBeInTheDocument();

        rerender(<VerifyEmail status="verification-link-sent" />);
        expect(screen.getByText(/new verification link has been sent/i)).toBeInTheDocument();
    });

    it('offers a logout link for a user stuck on the verification screen', () => {
        render(<VerifyEmail />);

        expect(screen.getByRole('link', { name: 'Log out' })).toHaveAttribute('href', '/logout');
    });
});
