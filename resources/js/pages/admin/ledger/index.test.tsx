import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// This project's tsconfig deliberately omits Node types (browser-only
// frontend) - Vitest itself runs under Node, so `process.env` genuinely
// exists at runtime even though the compiler doesn't know its shape.
declare const process: { env: Record<string, string | undefined> };

const { mockGet } = vi.hoisted(() => ({
    mockGet: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { get: mockGet },
    usePage: () => ({
        url: '/admin/ledger',
        props: { auth: { user: { id: 1, name: 'Ada Lovelace', email: 'ada@example.com', emailVerifiedAt: null }, permissions: ['ledger.view'] } },
        component: '',
        version: null,
    }),
}));

import AdminLedgerIndex from './index';

const TYPE_OPTIONS = [
    { value: 'deposit_credit', label: 'Deposit' },
    { value: 'fund_reservation', label: 'Funds reserved' },
];
const ACCOUNT_TYPE_OPTIONS = [
    { value: 'earning_available', label: 'Earning available' },
    { value: 'provider_settlement_clearing', label: 'Provider settlement clearing' },
];

const EMPTY_FILTERS = {
    id: null,
    type: null,
    accountType: null,
    userId: null,
    username: null,
    businessReference: null,
    correlationId: null,
    dateFrom: null,
    dateTo: null,
};

const BASE_PROPS = {
    transactions: { data: [], nextCursor: null, previousCursor: null },
    filters: EMPTY_FILTERS,
    typeOptions: TYPE_OPTIONS,
    accountTypeOptions: ACCOUNT_TYPE_OPTIONS,
};

const SAMPLE_TRANSACTION = {
    id: '01hzzzzzzzzzzzzzzzzzzzzzzz',
    type: 'deposit_credit',
    typeLabel: 'Deposit',
    occurredAt: '2026-03-05T12:30:00Z',
    description: 'Test posting',
    businessReference: 'deposit_credit:sample-1',
    correlationId: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    currency: 'USD',
    entryCount: 2,
    isReversal: false,
    originalTransactionId: null,
    hasBeenReversed: false,
    reversalTransactionId: null,
    relatedEntity: null,
    involvedUsers: [{ id: 1, username: 'ada', maskedEmail: 'a***@example.com' }],
    actor: null,
};

beforeEach(() => {
    mockGet.mockClear();
});

describe('AdminLedgerIndex empty state', () => {
    it('renders an honest empty state and no table', () => {
        render(<AdminLedgerIndex {...BASE_PROPS} />);

        expect(screen.getByText('No transactions match these filters.')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('shows a Read only indicator', () => {
        render(<AdminLedgerIndex {...BASE_PROPS} />);

        expect(screen.getByText('Read only')).toBeInTheDocument();
    });
});

describe('AdminLedgerIndex transaction rendering', () => {
    it('renders the transaction in both the desktop table and the mobile list, scoped queries only', () => {
        render(<AdminLedgerIndex {...BASE_PROPS} transactions={{ data: [SAMPLE_TRANSACTION], nextCursor: null, previousCursor: null }} />);

        const table = within(screen.getByRole('table'));
        expect(table.getByText('Deposit')).toBeInTheDocument();
        expect(table.getByText('deposit_credit:sample-1')).toBeInTheDocument();
        expect(table.getByText(/ada/)).toBeInTheDocument();
        expect(table.getByText(/a\*\*\*@example\.com/)).toBeInTheDocument();
    });

    it('shows reversal badges for isReversal and hasBeenReversed', () => {
        const reversal = { ...SAMPLE_TRANSACTION, isReversal: true };
        const reversed = { ...SAMPLE_TRANSACTION, id: 'other-id', hasBeenReversed: true };
        render(<AdminLedgerIndex {...BASE_PROPS} transactions={{ data: [reversal, reversed], nextCursor: null, previousCursor: null }} />);

        const table = within(screen.getByRole('table'));
        expect(table.getAllByText('Reversal')).toHaveLength(1);
        expect(table.getAllByText('Reversed')).toHaveLength(1);
    });

    it('shows an honest placeholder when a transaction has no involved users', () => {
        const noUsers = { ...SAMPLE_TRANSACTION, involvedUsers: [] };
        render(<AdminLedgerIndex {...BASE_PROPS} transactions={{ data: [noUsers], nextCursor: null, previousCursor: null }} />);

        expect(within(screen.getByRole('table')).getByText('—')).toBeInTheDocument();
    });

    it('formats occurredAt in UTC regardless of the host system timezone, never silently converting to local time', () => {
        const originalTz = process.env.TZ;
        process.env.TZ = 'America/Los_Angeles';

        try {
            // 2026-01-01T02:00:00Z is still Dec 31 in America/Los_Angeles
            // (UTC-8) - if formatUtc() ever dropped its explicit
            // `timeZone: 'UTC'` option and fell back to the host's local
            // zone, this would render "Dec 31, 2025" instead.
            const midnightCrossing = { ...SAMPLE_TRANSACTION, occurredAt: '2026-01-01T02:00:00Z' };
            render(<AdminLedgerIndex {...BASE_PROPS} transactions={{ data: [midnightCrossing], nextCursor: null, previousCursor: null }} />);

            const table = within(screen.getByRole('table'));
            expect(table.getByText(/Jan 1, 2026/)).toBeInTheDocument();
            expect(table.queryByText(/Dec 31, 2025/)).not.toBeInTheDocument();
        } finally {
            process.env.TZ = originalTz;
        }
    });
});

describe('AdminLedgerIndex filters', () => {
    it('renders every filter control with accessible labels', () => {
        render(<AdminLedgerIndex {...BASE_PROPS} />);

        expect(screen.getByLabelText('Transaction ID')).toBeInTheDocument();
        expect(screen.getByLabelText('Type')).toBeInTheDocument();
        expect(screen.getByLabelText('Account type')).toBeInTheDocument();
        expect(screen.getByLabelText('User ID')).toBeInTheDocument();
        expect(screen.getByLabelText('Username')).toBeInTheDocument();
        expect(screen.getByLabelText('Business reference')).toBeInTheDocument();
        expect(screen.getByLabelText('Correlation ID')).toBeInTheDocument();
        expect(screen.getByLabelText('From date (UTC)')).toBeInTheDocument();
        expect(screen.getByLabelText('To date (UTC)')).toBeInTheDocument();
    });

    it('clearly states the user filter does not support email search', () => {
        render(<AdminLedgerIndex {...BASE_PROPS} />);

        expect(screen.getByText('Email addresses are not searchable here.')).toBeInTheDocument();
        expect(screen.queryByText(/search by email/i)).not.toBeInTheDocument();
    });

    it('submits the entered filters via router.get', async () => {
        const user = userEvent.setup();
        render(<AdminLedgerIndex {...BASE_PROPS} />);

        await user.type(screen.getByLabelText('User ID'), '42');
        await user.click(screen.getByRole('button', { name: 'Apply filters' }));

        expect(mockGet).toHaveBeenCalledWith('/admin/ledger', { userId: '42' }, expect.any(Object));
    });

    it('typing a username clears any entered user ID, and vice versa', async () => {
        const user = userEvent.setup();
        render(<AdminLedgerIndex {...BASE_PROPS} />);

        await user.type(screen.getByLabelText('User ID'), '42');
        await user.type(screen.getByLabelText('Username'), 'jane_doe');

        expect(screen.getByLabelText('User ID')).toHaveValue('');
        expect(screen.getByLabelText('Username')).toHaveValue('jane_doe');

        await user.click(screen.getByRole('button', { name: 'Apply filters' }));

        expect(mockGet).toHaveBeenCalledWith('/admin/ledger', { username: 'jane_doe' }, expect.any(Object));
    });

    it('reset clears the form and navigates with no filters', async () => {
        const user = userEvent.setup();
        render(<AdminLedgerIndex {...BASE_PROPS} filters={{ ...EMPTY_FILTERS, type: 'deposit_credit' }} />);

        await user.click(screen.getByRole('button', { name: 'Reset' }));

        expect(mockGet).toHaveBeenCalledWith('/admin/ledger', {}, expect.any(Object));
        expect(screen.getByLabelText('Type')).toHaveValue('');
    });
});

describe('AdminLedgerIndex pagination', () => {
    it('disables both controls when no cursor exists in either direction', () => {
        render(<AdminLedgerIndex {...BASE_PROPS} transactions={{ data: [SAMPLE_TRANSACTION], nextCursor: null, previousCursor: null }} />);

        expect(screen.getByRole('button', { name: /previous/i })).toBeDisabled();
        expect(screen.getByRole('button', { name: /next/i })).toBeDisabled();
    });

    it('requests the next page with the cursor and preserves active filters', async () => {
        const user = userEvent.setup();
        render(
            <AdminLedgerIndex
                {...BASE_PROPS}
                filters={{ ...EMPTY_FILTERS, type: 'deposit_credit' }}
                transactions={{ data: [SAMPLE_TRANSACTION], nextCursor: 'opaque-cursor', previousCursor: null }}
            />,
        );

        await user.click(screen.getByRole('button', { name: /next/i }));

        expect(mockGet).toHaveBeenCalledWith('/admin/ledger', { type: 'deposit_credit', cursor: 'opaque-cursor' }, expect.any(Object));
    });
});

describe('AdminLedgerIndex excludes mutation controls', () => {
    it('never renders an adjustment, reversal, approval, or delete control', () => {
        render(<AdminLedgerIndex {...BASE_PROPS} transactions={{ data: [SAMPLE_TRANSACTION], nextCursor: null, previousCursor: null }} />);

        expect(screen.queryByRole('button', { name: /adjust/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /reverse/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /approve/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
    });
});
