import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// This project's tsconfig deliberately omits Node types (browser-only
// frontend) - Vitest itself runs under Node, so `process.env` genuinely
// exists at runtime even though the compiler doesn't know its shape.
declare const process: { env: Record<string, string | undefined> };

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        url: '/admin/ledger/01hzzzzzzzzzzzzzzzzzzzzzzz',
        props: { auth: { user: { id: 1, name: 'Ada Lovelace', email: 'ada@example.com', emailVerifiedAt: null }, permissions: ['ledger.view'] } },
        component: '',
        version: null,
    }),
}));

import AdminLedgerShow from './show';

const BASE_TRANSACTION = {
    id: '01hzzzzzzzzzzzzzzzzzzzzzzz',
    type: 'deposit_credit',
    typeLabel: 'Deposit',
    description: 'Test posting',
    businessReference: 'deposit_credit:sample-1',
    correlationId: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    currency: 'USD',
    currencyScale: 6,
    actor: null,
    relatedEntity: null,
    isReversal: false,
    originalTransactionId: null,
    hasBeenReversed: false,
    reversalTransactionId: null,
    createdAt: '2026-03-05T12:30:00Z',
};

const DEBIT_ENTRY = {
    id: 'entry-1',
    entryType: 'debit' as const,
    atomic: '250000',
    formatted: '0.250000',
    currency: 'USD',
    walletAccountId: 'wa-1',
    accountType: 'provider_settlement_clearing',
    accountLabel: 'Provider settlement clearing',
    scopeType: 'provider' as const,
    scopeLabel: 'Provider',
    user: null,
};

const CREDIT_ENTRY = {
    id: 'entry-2',
    entryType: 'credit' as const,
    atomic: '250000',
    formatted: '0.250000',
    currency: 'USD',
    walletAccountId: 'wa-2',
    accountType: 'earning_available',
    accountLabel: 'Earning available',
    scopeType: 'user' as const,
    scopeLabel: 'User',
    user: { id: 7, username: 'ada', maskedEmail: 'a***@example.com' },
};

const BASE_PROPS = {
    transaction: BASE_TRANSACTION,
    entries: [DEBIT_ENTRY, CREDIT_ENTRY],
    canViewLedgerAudit: false,
    ledgerAudit: null,
};

describe('AdminLedgerShow summary', () => {
    it('renders transaction metadata and a Read only indicator', () => {
        render(<AdminLedgerShow {...BASE_PROPS} />);

        expect(screen.getByText('deposit_credit:sample-1')).toBeInTheDocument();
        expect(screen.getByText('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')).toBeInTheDocument();
        expect(screen.getByText('Read only')).toBeInTheDocument();
    });

    it('links back to the ledger index', () => {
        render(<AdminLedgerShow {...BASE_PROPS} />);

        expect(screen.getByRole('link', { name: /back to ledger/i })).toHaveAttribute('href', '/admin/ledger');
    });

    it('renders reversal linkage when present', () => {
        const reversed = { ...BASE_TRANSACTION, hasBeenReversed: true, reversalTransactionId: 'reversal-id' };
        render(<AdminLedgerShow {...BASE_PROPS} transaction={reversed} />);

        expect(screen.getByRole('link', { name: /view reversal transaction/i })).toHaveAttribute('href', '/admin/ledger/reversal-id');
    });

    it('formats createdAt in UTC regardless of the host system timezone, never silently converting to local time', () => {
        const originalTz = process.env.TZ;
        process.env.TZ = 'America/Los_Angeles';

        try {
            // 2026-01-01T02:00:00Z is still Dec 31 in America/Los_Angeles
            // (UTC-8) - if formatUtc() ever dropped its explicit
            // `timeZone: 'UTC'` option and fell back to the host's local
            // zone, this would render "Dec 31, 2025" instead.
            const midnightCrossing = { ...BASE_TRANSACTION, createdAt: '2026-01-01T02:00:00Z' };
            render(<AdminLedgerShow {...BASE_PROPS} transaction={midnightCrossing} />);

            expect(screen.getByText(/Jan 1, 2026/)).toBeInTheDocument();
            expect(screen.queryByText(/Dec 31, 2025/)).not.toBeInTheDocument();
        } finally {
            process.env.TZ = originalTz;
        }
    });
});

describe('AdminLedgerShow entries', () => {
    it('renders entries in the given order with fixed six-decimal amounts', () => {
        render(<AdminLedgerShow {...BASE_PROPS} />);

        const rows = within(screen.getByRole('table')).getAllByRole('row').slice(1); // skip header row
        expect(rows).toHaveLength(2);
        expect(within(rows[0]).getByText('Debit')).toBeInTheDocument();
        expect(within(rows[1]).getByText('Credit')).toBeInTheDocument();
        expect(screen.getAllByText('0.250000')).toHaveLength(2);
    });

    it('shows masked user identity only for user-scoped entries', () => {
        render(<AdminLedgerShow {...BASE_PROPS} />);

        const table = within(screen.getByRole('table'));
        expect(table.getByText(/ada/)).toBeInTheDocument();
        expect(table.getByText(/a\*\*\*@example\.com/)).toBeInTheDocument();
        // The provider-scoped row shows a controlled label, never a user identity.
        expect(table.getByText('Provider settlement clearing')).toBeInTheDocument();
    });

    it('never renders a full, unmasked email address', () => {
        render(<AdminLedgerShow {...BASE_PROPS} />);

        expect(screen.queryByText('ada@example.com')).not.toBeInTheDocument();
    });
});

describe('AdminLedgerShow audit panel', () => {
    const AUDIT = {
        action: 'ledger.administrative_adjustment',
        internalReason: 'Correcting a support-verified reward calculation error.',
        actor: { id: 3, username: 'finance_staff' },
        before: {},
        after: { target_account_type: 'earning_available', direction: 'increase', amount_atomic: '10000000' },
        correlationId: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
        occurredAt: '2026-03-05T12:31:00Z',
    };

    it('is absent when canViewLedgerAudit is false, even if a ledgerAudit payload were somehow present', () => {
        render(<AdminLedgerShow {...BASE_PROPS} canViewLedgerAudit={false} ledgerAudit={AUDIT} />);

        expect(screen.queryByText(/administrative-adjustment audit/i)).not.toBeInTheDocument();
        expect(screen.queryByText(AUDIT.internalReason)).not.toBeInTheDocument();
    });

    it('is absent when ledgerAudit is null even if canViewLedgerAudit is true', () => {
        render(<AdminLedgerShow {...BASE_PROPS} canViewLedgerAudit={true} ledgerAudit={null} />);

        expect(screen.queryByText(/administrative-adjustment audit/i)).not.toBeInTheDocument();
    });

    it('renders the audit panel only when both canViewLedgerAudit and ledgerAudit are present', () => {
        render(<AdminLedgerShow {...BASE_PROPS} canViewLedgerAudit={true} ledgerAudit={AUDIT} />);

        expect(screen.getByText(/administrative-adjustment audit/i)).toBeInTheDocument();
        expect(screen.getByText(AUDIT.internalReason)).toBeInTheDocument();
        expect(screen.getByText('finance_staff')).toBeInTheDocument();
        expect(screen.getByText('earning_available')).toBeInTheDocument();
    });
});

describe('AdminLedgerShow excludes mutation controls', () => {
    it('never renders an adjustment, reversal, approval, or delete control', () => {
        render(<AdminLedgerShow {...BASE_PROPS} canViewLedgerAudit={true} ledgerAudit={null} />);

        expect(screen.queryByRole('button', { name: /adjust/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /reverse/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /approve/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
