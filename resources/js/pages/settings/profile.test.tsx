import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

const { mockPatch, mockErrors } = vi.hoisted(() => ({
    mockPatch: vi.fn(),
    mockErrors: { current: {} as Record<string, string> },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    useForm: (initialData: Record<string, unknown>) => {
        const [data, setDataState] = useState(initialData);

        return {
            data,
            setData: (key: string, value: unknown) => setDataState((prev) => ({ ...prev, [key]: value })),
            patch: (url: string) => mockPatch(url, data),
            processing: false,
            errors: mockErrors.current,
        };
    },
}));

import Profile from './profile';

const emptyProfile = {
    username: null,
    dateOfBirth: null,
    countryCode: null,
    locale: null,
    timezone: null,
};

beforeEach(() => {
    mockPatch.mockClear();
    mockErrors.current = {};
});

describe('Profile settings page', () => {
    it('pre-fills the form from the current profile', () => {
        render(
            <Profile
                profile={{
                    username: 'JohnDoe',
                    dateOfBirth: '1990-05-14',
                    countryCode: 'US',
                    locale: 'en-US',
                    timezone: 'America/New_York',
                }}
            />,
        );

        expect(screen.getByLabelText('Username')).toHaveValue('JohnDoe');
        expect(screen.getByLabelText('Date of birth')).toHaveValue('1990-05-14');
        expect(screen.getByLabelText('Country code')).toHaveValue('US');
        expect(screen.getByLabelText('Locale')).toHaveValue('en-US');
        expect(screen.getByLabelText('Timezone')).toHaveValue('America/New_York');
    });

    it('renders empty fields when no profile has been saved yet', () => {
        render(<Profile profile={emptyProfile} />);

        expect(screen.getByLabelText('Username')).toHaveValue('');
        expect(screen.getByLabelText('Date of birth')).toHaveValue('');
    });

    it('submits the entered fields as a PATCH to /settings/profile', async () => {
        const user = userEvent.setup();
        render(<Profile profile={emptyProfile} />);

        await user.type(screen.getByLabelText('Username'), 'JohnDoe');
        await user.type(screen.getByLabelText('Country code'), 'us');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(mockPatch).toHaveBeenCalledWith('/settings/profile', expect.objectContaining({ username: 'JohnDoe', country_code: 'us' }));
    });

    it('shows a taken-username validation error returned from the server', () => {
        mockErrors.current = { username: 'This username is already taken.' };
        render(<Profile profile={emptyProfile} />);

        expect(screen.getByLabelText('Username')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('This username is already taken.')).toBeInTheDocument();
    });

    it('shows a confirmation message only after a real update happened', () => {
        const { rerender } = render(<Profile profile={emptyProfile} />);
        expect(screen.queryByText('Your profile has been updated.')).not.toBeInTheDocument();

        rerender(<Profile profile={emptyProfile} status="profile-updated" />);
        expect(screen.getByText('Your profile has been updated.')).toBeInTheDocument();
    });
});
