import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, RotateCcw, Undo2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import type { ReactElement, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';

interface Option {
    value: string;
    label: string;
}

interface InvolvedUser {
    id: number;
    username: string | null;
    maskedEmail: string;
}

interface RelatedEntity {
    type: string;
    id: string;
}

interface AdminLedgerListItem {
    id: string;
    type: string;
    typeLabel: string;
    occurredAt: string;
    description: string;
    businessReference: string;
    correlationId: string;
    currency: string;
    entryCount: number;
    isReversal: boolean;
    originalTransactionId: string | null;
    hasBeenReversed: boolean;
    reversalTransactionId: string | null;
    relatedEntity: RelatedEntity | null;
    involvedUsers: InvolvedUser[];
    actor: { id: number; username: string | null } | null;
}

interface AdminLedgerFiltersValue {
    id: string | null;
    type: string | null;
    accountType: string | null;
    userId: string | null;
    username: string | null;
    businessReference: string | null;
    correlationId: string | null;
    dateFrom: string | null;
    dateTo: string | null;
}

interface AdminLedgerIndexProps {
    transactions: {
        data: AdminLedgerListItem[];
        nextCursor: string | null;
        previousCursor: string | null;
    };
    filters: AdminLedgerFiltersValue;
    typeOptions: Option[];
    accountTypeOptions: Option[];
}

const utcFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' });

function formatUtc(iso: string): string {
    return `${utcFormatter.format(new Date(iso))} UTC`;
}

interface FormState {
    id: string;
    type: string;
    accountType: string;
    userId: string;
    username: string;
    businessReference: string;
    correlationId: string;
    dateFrom: string;
    dateTo: string;
}

const EMPTY_FORM: FormState = {
    id: '',
    type: '',
    accountType: '',
    userId: '',
    username: '',
    businessReference: '',
    correlationId: '',
    dateFrom: '',
    dateTo: '',
};

function ReversalBadges({ isReversal, hasBeenReversed }: { isReversal: boolean; hasBeenReversed: boolean }) {
    if (!isReversal && !hasBeenReversed) {
        return null;
    }

    return (
        <div className="mt-1 flex flex-wrap gap-2">
            {isReversal && (
                <span className="text-caption inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2 py-0.5 font-medium text-neutral-600">
                    <Undo2 className="size-3" aria-hidden="true" />
                    Reversal
                </span>
            )}
            {hasBeenReversed && (
                <span className="text-caption inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2 py-0.5 font-medium text-neutral-600">
                    <RotateCcw className="size-3" aria-hidden="true" />
                    Reversed
                </span>
            )}
        </div>
    );
}

function InvolvedUsers({ users }: { users: InvolvedUser[] }) {
    if (users.length === 0) {
        return <span className="text-neutral-400">—</span>;
    }

    return (
        <ul className="space-y-0.5">
            {users.map((user) => (
                <li key={user.id} className="text-body-sm text-neutral-700">
                    {user.username ?? 'No username'} <span className="text-neutral-400">·</span> {user.maskedEmail}
                </li>
            ))}
        </ul>
    );
}

export default function AdminLedgerIndex({ transactions, filters, typeOptions, accountTypeOptions }: AdminLedgerIndexProps) {
    const [isNavigating, setIsNavigating] = useState(false);
    const [form, setForm] = useState<FormState>({
        id: filters.id ?? '',
        type: filters.type ?? '',
        accountType: filters.accountType ?? '',
        userId: filters.userId ?? '',
        username: filters.username ?? '',
        businessReference: filters.businessReference ?? '',
        correlationId: filters.correlationId ?? '',
        dateFrom: filters.dateFrom ?? '',
        dateTo: filters.dateTo ?? '',
    });

    const navigate = (params: Record<string, string>) => {
        router.get('/admin/ledger', params, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setIsNavigating(true),
            onFinish: () => setIsNavigating(false),
        });
    };

    const buildParams = (overrides: Partial<FormState> = {}): Record<string, string> => {
        const merged = { ...form, ...overrides };
        const params: Record<string, string> = {};
        (Object.keys(merged) as Array<keyof FormState>).forEach((key) => {
            if (merged[key]) params[key] = merged[key];
        });

        return params;
    };

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        navigate(buildParams());
    };

    const handleReset = () => {
        setForm(EMPTY_FORM);
        navigate({});
    };

    const handlePageChange = (cursor: string | null) => {
        if (!cursor) return;
        navigate({ ...buildParams(), cursor });
    };

    return (
        <>
            <Head title="Ledger" />

            <div className="space-y-6">
                <div className="space-y-2">
                    <h1 className="text-h1 text-neutral-900">Ledger</h1>
                    <p className="text-body text-neutral-600">
                        A read-only view of every committed ledger transaction. No balance can be changed from this page.
                    </p>
                    <span className="text-caption inline-flex w-fit items-center gap-1 rounded-full bg-neutral-100 px-2.5 py-1 font-medium text-neutral-600">
                        Read only
                    </span>
                </div>

                <form
                    onSubmit={handleSubmit}
                    className="grid grid-cols-1 gap-4 rounded-lg border border-neutral-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div>
                        <label htmlFor="filter-id" className="text-label block text-neutral-700">
                            Transaction ID
                        </label>
                        <input
                            id="filter-id"
                            type="text"
                            value={form.id}
                            disabled={isNavigating}
                            onChange={(e) => setForm({ ...form, id: e.target.value })}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 disabled:opacity-50"
                        />
                    </div>

                    <div>
                        <label htmlFor="filter-type" className="text-label block text-neutral-700">
                            Type
                        </label>
                        <select
                            id="filter-type"
                            value={form.type}
                            disabled={isNavigating}
                            onChange={(e) => setForm({ ...form, type: e.target.value })}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 disabled:opacity-50"
                        >
                            <option value="">All types</option>
                            {typeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label htmlFor="filter-account-type" className="text-label block text-neutral-700">
                            Account type
                        </label>
                        <select
                            id="filter-account-type"
                            value={form.accountType}
                            disabled={isNavigating}
                            onChange={(e) => setForm({ ...form, accountType: e.target.value })}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 disabled:opacity-50"
                        >
                            <option value="">All accounts</option>
                            {accountTypeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label htmlFor="filter-user-id" className="text-label block text-neutral-700">
                            User ID
                        </label>
                        <input
                            id="filter-user-id"
                            type="text"
                            value={form.userId}
                            disabled={isNavigating}
                            placeholder="e.g. 42"
                            onChange={(e) => setForm({ ...form, userId: e.target.value, username: '' })}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 disabled:opacity-50"
                        />
                    </div>

                    <div>
                        <label htmlFor="filter-username" className="text-label block text-neutral-700">
                            Username
                        </label>
                        <p id="filter-username-hint" className="text-caption mt-1 text-neutral-500">
                            Email addresses are not searchable here.
                        </p>
                        <input
                            id="filter-username"
                            type="text"
                            value={form.username}
                            disabled={isNavigating}
                            placeholder="e.g. jane_doe"
                            aria-describedby="filter-username-hint"
                            onChange={(e) => setForm({ ...form, username: e.target.value, userId: '' })}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 disabled:opacity-50"
                        />
                    </div>

                    <div>
                        <label htmlFor="filter-business-reference" className="text-label block text-neutral-700">
                            Business reference
                        </label>
                        <input
                            id="filter-business-reference"
                            type="text"
                            value={form.businessReference}
                            disabled={isNavigating}
                            onChange={(e) => setForm({ ...form, businessReference: e.target.value })}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 disabled:opacity-50"
                        />
                    </div>

                    <div>
                        <label htmlFor="filter-correlation-id" className="text-label block text-neutral-700">
                            Correlation ID
                        </label>
                        <input
                            id="filter-correlation-id"
                            type="text"
                            value={form.correlationId}
                            disabled={isNavigating}
                            onChange={(e) => setForm({ ...form, correlationId: e.target.value })}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 disabled:opacity-50"
                        />
                    </div>

                    <div>
                        <label htmlFor="filter-date-from" className="text-label block text-neutral-700">
                            From date (UTC)
                        </label>
                        <input
                            id="filter-date-from"
                            type="date"
                            value={form.dateFrom}
                            disabled={isNavigating}
                            onChange={(e) => setForm({ ...form, dateFrom: e.target.value })}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 disabled:opacity-50"
                        />
                    </div>

                    <div>
                        <label htmlFor="filter-date-to" className="text-label block text-neutral-700">
                            To date (UTC)
                        </label>
                        <input
                            id="filter-date-to"
                            type="date"
                            value={form.dateTo}
                            disabled={isNavigating}
                            onChange={(e) => setForm({ ...form, dateTo: e.target.value })}
                            className="focus-ring mt-1.5 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 disabled:opacity-50"
                        />
                    </div>

                    <div className="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                        <Button type="submit" size="sm" disabled={isNavigating}>
                            Apply filters
                        </Button>
                        <Button type="button" variant="secondary" size="sm" disabled={isNavigating} onClick={handleReset}>
                            Reset
                        </Button>
                        {isNavigating && (
                            <span className="text-body-sm text-neutral-500" role="status" aria-live="polite">
                                Loading&hellip;
                            </span>
                        )}
                    </div>
                </form>

                <div className="rounded-lg border border-neutral-200 bg-white">
                    {transactions.data.length === 0 ? (
                        <div className="p-8 text-center">
                            <p className="text-body text-neutral-700">No transactions match these filters.</p>
                        </div>
                    ) : (
                        <>
                            <table className="hidden w-full text-left lg:table">
                                <caption className="sr-only">Ledger transactions</caption>
                                <thead>
                                    <tr className="border-b border-neutral-200">
                                        <th scope="col" className="text-caption px-4 py-3 font-medium text-neutral-500">
                                            Type
                                        </th>
                                        <th scope="col" className="text-caption px-4 py-3 font-medium text-neutral-500">
                                            Reference
                                        </th>
                                        <th scope="col" className="text-caption px-4 py-3 font-medium text-neutral-500">
                                            Involved users
                                        </th>
                                        <th scope="col" className="text-caption px-4 py-3 font-medium text-neutral-500">
                                            Occurred (UTC)
                                        </th>
                                        <th scope="col" className="text-caption px-4 py-3 text-right font-medium text-neutral-500">
                                            Entries
                                        </th>
                                        <th scope="col" className="text-caption px-4 py-3 font-medium text-neutral-500">
                                            <span className="sr-only">View</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {transactions.data.map((transaction) => (
                                        <tr key={transaction.id} className="border-b border-neutral-100 last:border-0">
                                            <td className="px-4 py-3 align-top">
                                                <span className="text-body-sm font-medium text-neutral-900">{transaction.typeLabel}</span>
                                                <ReversalBadges isReversal={transaction.isReversal} hasBeenReversed={transaction.hasBeenReversed} />
                                            </td>
                                            <td className="text-caption px-4 py-3 align-top font-mono text-neutral-500">
                                                {transaction.businessReference}
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <InvolvedUsers users={transaction.involvedUsers} />
                                            </td>
                                            <td className="text-body-sm px-4 py-3 align-top whitespace-nowrap text-neutral-600">
                                                {formatUtc(transaction.occurredAt)}
                                            </td>
                                            <td className="text-body-sm px-4 py-3 text-right align-top text-neutral-600">{transaction.entryCount}</td>
                                            <td className="px-4 py-3 text-right align-top">
                                                <Link
                                                    href={`/admin/ledger/${transaction.id}`}
                                                    className="focus-ring text-body-sm font-medium text-brand-700 hover:underline"
                                                >
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            <ul className="divide-y divide-neutral-100 lg:hidden">
                                {transactions.data.map((transaction) => (
                                    <li key={transaction.id} className="p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <span className="text-body-sm font-medium text-neutral-900">{transaction.typeLabel}</span>
                                                <ReversalBadges isReversal={transaction.isReversal} hasBeenReversed={transaction.hasBeenReversed} />
                                            </div>
                                            <span className="text-caption shrink-0 text-neutral-500">{formatUtc(transaction.occurredAt)}</span>
                                        </div>
                                        <p className="text-caption mt-1 font-mono text-neutral-500">{transaction.businessReference}</p>
                                        <div className="mt-2">
                                            <InvolvedUsers users={transaction.involvedUsers} />
                                        </div>
                                        <div className="mt-2 flex items-center justify-between">
                                            <span className="text-caption text-neutral-500">{transaction.entryCount} entries</span>
                                            <Link
                                                href={`/admin/ledger/${transaction.id}`}
                                                className="focus-ring text-body-sm font-medium text-brand-700 hover:underline"
                                            >
                                                View
                                            </Link>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>

                {transactions.data.length > 0 && (
                    <div className="flex items-center justify-between">
                        <Button
                            variant="secondary"
                            size="sm"
                            disabled={!transactions.previousCursor || isNavigating}
                            onClick={() => handlePageChange(transactions.previousCursor)}
                        >
                            <ChevronLeft className="size-4" aria-hidden="true" />
                            Previous
                        </Button>
                        <Button
                            variant="secondary"
                            size="sm"
                            disabled={!transactions.nextCursor || isNavigating}
                            onClick={() => handlePageChange(transactions.nextCursor)}
                        >
                            Next
                            <ChevronRight className="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

AdminLedgerIndex.layout = (page: ReactElement) => <AdminLayout pageTitle="Ledger">{page as ReactNode}</AdminLayout>;
