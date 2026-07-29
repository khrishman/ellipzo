import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { mockGet } = vi.hoisted(() => ({
    mockGet: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { get: mockGet },
    usePage: () => ({
        url: '/transactions',
        props: { auth: { user: { id: 1, name: 'Ada Lovelace', email: 'ada@example.com', emailVerifiedAt: null } } },
        component: '',
        version: null,
    }),
}));

import TransactionHistory from './index';

const ACCOUNT_OPTIONS = [
    { value: 'earning_available', label: 'Earning available' },
    { value: 'earning_held', label: 'Earning held' },
    { value: 'advertising_available', label: 'Advertising available' },
    { value: 'advertising_reserved', label: 'Advertising reserved' },
];

const ZERO_BALANCE = { atomic: '0', formatted: '0.000000', currency: 'USD' };

const BASE_PROPS = {
    balances: {
        earning_available: { atomic: '700000', formatted: '0.700000', currency: 'USD' },
        earning_held: ZERO_BALANCE,
        advertising_available: ZERO_BALANCE,
        advertising_reserved: ZERO_BALANCE,
    },
    transactions: { data: [], nextCursor: null, previousCursor: null },
    filters: { account: null, type: null },
    accountOptions: ACCOUNT_OPTIONS,
    availableTransactionTypes: [],
};

beforeEach(() => {
    mockGet.mockClear();
});

describe('TransactionHistory balances', () => {
    it('renders exactly four balance cards with fixed six-decimal amounts and currency', () => {
        render(<TransactionHistory {...BASE_PROPS} />);

        const balances = within(screen.getByRole('group', { name: 'Account balances' }));

        expect(balances.getByText('Earning available')).toBeInTheDocument();
        expect(balances.getByText('Earning held')).toBeInTheDocument();
        expect(balances.getByText('Advertising available')).toBeInTheDocument();
        expect(balances.getByText('Advertising reserved')).toBeInTheDocument();
        expect(balances.getByText('0.700000')).toBeInTheDocument();
        expect(balances.getAllByText('0.000000')).toHaveLength(3);
        expect(balances.getAllByText('USD')).toHaveLength(4);
    });
});

describe('TransactionHistory empty state', () => {
    it('renders an honest empty state with no fabricated activity', () => {
        render(<TransactionHistory {...BASE_PROPS} />);

        expect(screen.getByText('No transactions yet.')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
});

describe('TransactionHistory movements', () => {
    const transaction = {
        id: '01hzzzzzzzzzzzzzzzzzzzzzzz',
        type: 'fund_reservation',
        typeLabel: 'Funds reserved',
        occurredAt: '2026-07-29T12:00:00Z',
        detail: null,
        movements: [
            {
                accountType: 'earning_available' as const,
                accountLabel: 'Earning available',
                direction: 'decrease' as const,
                atomic: '50000',
                formatted: '0.050000',
                currency: 'USD',
            },
            {
                accountType: 'earning_held' as const,
                accountLabel: 'Earning held',
                direction: 'increase' as const,
                atomic: '50000',
                formatted: '0.050000',
                currency: 'USD',
            },
        ],
    };

    it('renders every movement belonging to a multi-movement transaction', () => {
        render(<TransactionHistory {...BASE_PROPS} transactions={{ data: [transaction], nextCursor: null, previousCursor: null }} />);

        // Scoped to the desktop table - the same data also renders in the
        // mobile card list (CSS-only responsive toggling, both present in
        // jsdom at once), so an unscoped query would double-count.
        const table = within(screen.getByRole('table'));

        expect(table.getByText('Earning available')).toBeInTheDocument();
        expect(table.getByText('Earning held')).toBeInTheDocument();
        expect(table.getAllByText('0.050000')).toHaveLength(2);
    });

    it('shows direction with both an icon and visible text, never color alone', () => {
        render(<TransactionHistory {...BASE_PROPS} transactions={{ data: [transaction], nextCursor: null, previousCursor: null }} />);

        const table = within(screen.getByRole('table'));

        expect(table.getByText('Increase')).toBeInTheDocument();
        expect(table.getByText('Decrease')).toBeInTheDocument();
    });

    it('renders whatever safe typeLabel the backend supplies, including a future/unknown type, without a second frontend label map', () => {
        const futureTransaction = { ...transaction, type: 'some_future_type', typeLabel: 'Transaction' };
        render(<TransactionHistory {...BASE_PROPS} transactions={{ data: [futureTransaction], nextCursor: null, previousCursor: null }} />);

        expect(within(screen.getByRole('table')).getByText('Transaction')).toBeInTheDocument();
    });

    it('never renders internal metadata even if it were mistakenly present on a transaction object', () => {
        // Excess properties on a spread (non-literal) object aren't
        // type-checked by TypeScript, so this compiles without a cast -
        // deliberately simulating a backend mistake for this test only.
        const contaminated = {
            ...transaction,
            correlationId: 'should-never-render-12345',
            internalReason: 'staff-only-reason-should-never-render',
        };
        render(<TransactionHistory {...BASE_PROPS} transactions={{ data: [contaminated], nextCursor: null, previousCursor: null }} />);

        expect(screen.queryByText(/should-never-render/)).not.toBeInTheDocument();
    });
});

describe('TransactionHistory filters', () => {
    it('always renders the account filter with the four fixed options', () => {
        render(<TransactionHistory {...BASE_PROPS} />);

        const select = screen.getByLabelText('Account') as HTMLSelectElement;
        expect(select.options).toHaveLength(5); // "All accounts" + 4
    });

    it('hides the type filter entirely when the user has no real history', () => {
        render(<TransactionHistory {...BASE_PROPS} availableTransactionTypes={[]} />);

        expect(screen.queryByLabelText('Type')).not.toBeInTheDocument();
    });

    it('shows the type filter only when real options exist, scoped to the user’s own history', () => {
        render(<TransactionHistory {...BASE_PROPS} availableTransactionTypes={[{ value: 'deposit_credit', label: 'Deposit' }]} />);

        const select = screen.getByLabelText('Type') as HTMLSelectElement;
        expect(select.options).toHaveLength(2); // "All types" + 1
    });

    it('requests the account filter via router.get without a stale cursor', async () => {
        const user = userEvent.setup();
        render(<TransactionHistory {...BASE_PROPS} />);

        await user.selectOptions(screen.getByLabelText('Account'), 'earning_available');

        expect(mockGet).toHaveBeenCalledWith('/transactions', { account: 'earning_available' }, expect.any(Object));
    });

    it('preserves the active account filter when changing the type filter', async () => {
        const user = userEvent.setup();
        render(
            <TransactionHistory
                {...BASE_PROPS}
                filters={{ account: 'earning_available', type: null }}
                availableTransactionTypes={[{ value: 'deposit_credit', label: 'Deposit' }]}
            />,
        );

        await user.selectOptions(screen.getByLabelText('Type'), 'deposit_credit');

        expect(mockGet).toHaveBeenCalledWith('/transactions', { account: 'earning_available', type: 'deposit_credit' }, expect.any(Object));
    });
});

describe('TransactionHistory pagination', () => {
    it('disables previous/next when no corresponding cursor exists', () => {
        render(<TransactionHistory {...BASE_PROPS} transactions={{ data: [], nextCursor: null, previousCursor: null }} />);

        expect(screen.queryByRole('button', { name: /previous/i })).not.toBeInTheDocument();
    });

    it('enables next and requests it with the preserved filters and the cursor', async () => {
        const user = userEvent.setup();
        const transaction = {
            id: '01hzzzzzzzzzzzzzzzzzzzzzzz',
            type: 'deposit_credit',
            typeLabel: 'Deposit',
            occurredAt: '2026-07-29T12:00:00Z',
            detail: null,
            movements: [
                {
                    accountType: 'earning_available' as const,
                    accountLabel: 'Earning available',
                    direction: 'increase' as const,
                    atomic: '10000',
                    formatted: '0.010000',
                    currency: 'USD',
                },
            ],
        };
        render(
            <TransactionHistory
                {...BASE_PROPS}
                filters={{ account: 'earning_available', type: null }}
                transactions={{ data: [transaction], nextCursor: 'opaque-next-cursor', previousCursor: null }}
            />,
        );

        const nextButton = screen.getByRole('button', { name: /next/i });
        expect(nextButton).not.toBeDisabled();
        expect(screen.getByRole('button', { name: /previous/i })).toBeDisabled();

        await user.click(nextButton);

        expect(mockGet).toHaveBeenCalledWith('/transactions', { account: 'earning_available', cursor: 'opaque-next-cursor' }, expect.any(Object));
    });
});

describe('TransactionHistory excludes unfinished/unbuilt functionality', () => {
    it('never renders a deposit, withdrawal, or crypto/network selection control', () => {
        render(<TransactionHistory {...BASE_PROPS} />);

        expect(screen.queryByRole('button', { name: /deposit/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /withdraw/i })).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/network/i)).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/asset/i)).not.toBeInTheDocument();
    });
});
