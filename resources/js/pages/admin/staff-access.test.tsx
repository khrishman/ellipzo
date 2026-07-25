import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { mockPost, mockErrors, mockProcessing } = vi.hoisted(() => ({
    mockPost: vi.fn(),
    mockErrors: { current: {} as Record<string, string> },
    mockProcessing: { current: false },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    useForm: (initialData: Record<string, unknown>) => {
        const [data, setDataState] = useState(initialData);

        return {
            data,
            setData: (key: string, value: unknown) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url: string, options?: { onSuccess?: () => void }) => {
                mockPost(url, data);
                options?.onSuccess?.();
            },
            reset: () => setDataState(initialData),
            processing: mockProcessing.current,
            errors: mockErrors.current,
        };
    },
}));

import StaffAccess from './staff-access';

const baseProps = {
    staff: [{ id: 1, name: 'Ada Lovelace', email: 'ada@example.com', role: 'administrator' }],
    roles: ['administrator', 'finance-operator', 'moderator', 'support-agent'],
    canManage: true,
    canViewAudit: true,
    recentAuditEvents: [
        {
            actor: 'Katherine Johnson',
            target: 'Grace Hopper',
            beforeRole: null,
            afterRole: 'moderator',
            reason: 'Promoted for content review.',
            occurredAt: '2026-07-25T00:00:00Z',
        },
    ],
};

beforeEach(() => {
    mockPost.mockClear();
    mockErrors.current = {};
    mockProcessing.current = false;
});

describe('Staff access page', () => {
    it('lists current staff with their role', () => {
        render(<StaffAccess {...baseProps} />);

        const row = screen.getByText('Ada Lovelace').closest('tr') as HTMLElement;
        expect(within(row).getByText('ada@example.com')).toBeInTheDocument();
        expect(within(row).getByText('Administrator')).toBeInTheDocument();
    });

    it('hides the role-change form when the viewer cannot manage staff', () => {
        render(<StaffAccess {...baseProps} canManage={false} />);

        expect(screen.queryByLabelText('User email')).not.toBeInTheDocument();
    });

    it('shows the recent role changes panel only when the viewer can view audit data', () => {
        render(<StaffAccess {...baseProps} canViewAudit={false} recentAuditEvents={[]} />);

        expect(screen.queryByText('Recent role changes')).not.toBeInTheDocument();
    });

    it('requires an email and a reason before the confirmation step can open', async () => {
        const user = userEvent.setup();
        render(<StaffAccess {...baseProps} />);

        await user.click(screen.getByRole('button', { name: 'Save role' }));

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(mockPost).not.toHaveBeenCalled();
    });

    it('opens a confirmation dialog summarizing the change before submitting anything', async () => {
        const user = userEvent.setup();
        render(<StaffAccess {...baseProps} />);

        await user.type(screen.getByLabelText('User email'), 'grace@example.com');
        await user.selectOptions(screen.getByLabelText('Role'), 'moderator');
        await user.type(screen.getByLabelText('Reason'), 'Promoting for content moderation duties.');
        await user.click(screen.getByRole('button', { name: 'Save role' }));

        const dialog = screen.getByRole('dialog');
        expect(dialog).toBeInTheDocument();
        expect(within(dialog).getByText(/grace@example.com/)).toBeInTheDocument();
        expect(within(dialog).getByText(/Moderator/)).toBeInTheDocument();
        expect(mockPost).not.toHaveBeenCalled();
    });

    it('only submits after the confirmation dialog is explicitly confirmed', async () => {
        const user = userEvent.setup();
        render(<StaffAccess {...baseProps} />);

        await user.type(screen.getByLabelText('User email'), 'grace@example.com');
        await user.type(screen.getByLabelText('Reason'), 'Promoting for content moderation duties.');
        await user.click(screen.getByRole('button', { name: 'Save role' }));
        await user.click(screen.getByRole('button', { name: 'Confirm change' }));

        expect(mockPost).toHaveBeenCalledWith(
            '/admin/staff-access',
            expect.objectContaining({ email: 'grace@example.com', reason: 'Promoting for content moderation duties.' }),
        );
    });

    it('does not submit when the confirmation dialog is cancelled', async () => {
        const user = userEvent.setup();
        render(<StaffAccess {...baseProps} />);

        await user.type(screen.getByLabelText('User email'), 'grace@example.com');
        await user.type(screen.getByLabelText('Reason'), 'Promoting for content moderation duties.');
        await user.click(screen.getByRole('button', { name: 'Save role' }));
        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(mockPost).not.toHaveBeenCalled();
    });

    it('shows server-side validation errors for email, role, and reason', () => {
        mockErrors.current = {
            email: 'You cannot change your own staff role.',
            role: 'This is the last Administrator. Assign another Administrator before removing this one.',
            reason: 'The reason field must be at least 10 characters.',
        };
        render(<StaffAccess {...baseProps} />);

        expect(screen.getByText('You cannot change your own staff role.')).toBeInTheDocument();
        expect(screen.getByText(/last Administrator/)).toBeInTheDocument();
        expect(screen.getByText('The reason field must be at least 10 characters.')).toBeInTheDocument();
        expect(screen.getByLabelText('User email')).toHaveAttribute('aria-invalid', 'true');
    });

    it('disables the save button while a submission is processing', () => {
        mockProcessing.current = true;
        render(<StaffAccess {...baseProps} />);

        expect(screen.getByRole('button', { name: 'Save role' })).toBeDisabled();
    });
});
