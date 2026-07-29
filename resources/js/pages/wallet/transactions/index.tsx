import { Head, router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';

interface MoneyAmount {
    atomic: string;
    formatted: string;
    currency: string;
}

type UserAccountType = 'earning_available' | 'earning_held' | 'advertising_available' | 'advertising_reserved';

interface Option {
    value: string;
    label: string;
}

interface Movement {
    accountType: UserAccountType;
    accountLabel: string;
    direction: 'increase' | 'decrease';
    atomic: string;
    formatted: string;
    currency: string;
}

interface TransactionItem {
    id: string;
    type: string;
    typeLabel: string;
    occurredAt: string;
    detail: string | null;
    movements: Movement[];
}

interface TransactionHistoryProps {
    balances: Record<UserAccountType, MoneyAmount>;
    transactions: {
        data: TransactionItem[];
        nextCursor: string | null;
        previousCursor: string | null;
    };
    filters: {
        account: string | null;
        type: string | null;
    };
    accountOptions: Option[];
    availableTransactionTypes: Option[];
}

const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

function formatOccurredAt(iso: string): string {
    return dateFormatter.format(new Date(iso));
}

/**
 * Direction is always shown as an icon paired with visible text - color is
 * decorative only, never the sole signal (design.md 17: "Status is not
 * color-only").
 */
function MovementDirection({ direction }: { direction: 'increase' | 'decrease' }) {
    const Icon = direction === 'increase' ? ArrowUpRight : ArrowDownRight;
    const label = direction === 'increase' ? 'Increase' : 'Decrease';
    const colorClass = direction === 'increase' ? 'text-success-700' : 'text-danger-text';

    return (
        <span className={`inline-flex shrink-0 items-center gap-1 ${colorClass}`}>
            <Icon className="size-4" aria-hidden="true" />
            <span className="text-caption font-medium">{label}</span>
        </span>
    );
}

function MovementList({ movements }: { movements: Movement[] }) {
    return (
        <ul className="space-y-1.5">
            {movements.map((movement, index) => (
                <li key={index} className="flex flex-wrap items-center gap-x-2 gap-y-1 text-body-sm">
                    <MovementDirection direction={movement.direction} />
                    <span className="text-neutral-600">{movement.accountLabel}</span>
                    <span className="font-medium text-neutral-900">
                        {movement.formatted} <span className="font-normal text-neutral-500">{movement.currency}</span>
                    </span>
                </li>
            ))}
        </ul>
    );
}

export default function TransactionHistory({ balances, transactions, filters, accountOptions, availableTransactionTypes }: TransactionHistoryProps) {
    const [isNavigating, setIsNavigating] = useState(false);

    const navigate = (params: Record<string, string>) => {
        router.get('/transactions', params, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setIsNavigating(true),
            onFinish: () => setIsNavigating(false),
        });
    };

    const buildFilterParams = (overrides: { account?: string | null; type?: string | null } = {}): Record<string, string> => {
        const account = 'account' in overrides ? overrides.account : filters.account;
        const type = 'type' in overrides ? overrides.type : filters.type;
        const params: Record<string, string> = {};
        if (account) params.account = account;
        if (type) params.type = type;

        return params;
    };

    const handleAccountFilterChange = (value: string) => navigate(buildFilterParams({ account: value || null }));
    const handleTypeFilterChange = (value: string) => navigate(buildFilterParams({ type: value || null }));

    const handlePageChange = (cursor: string | null) => {
        if (!cursor) return;
        navigate({ ...buildFilterParams(), cursor });
    };

    return (
        <>
            <Head title="Transactions" />

            <div className="space-y-6">
                <div className="space-y-2">
                    <h1 className="text-h1 text-neutral-900">Transactions</h1>
                    <p className="text-body text-neutral-600">
                        Your current earning and advertising balances, and a history of every movement on your account.
                    </p>
                </div>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4" role="group" aria-label="Account balances">
                    {accountOptions.map((option) => {
                        const balance = balances[option.value as UserAccountType];

                        return (
                            <div key={option.value} className="rounded-lg border border-neutral-200 bg-white p-4">
                                <p className="text-caption text-neutral-500">{option.label}</p>
                                <p className="text-h4 mt-1 text-neutral-900">
                                    {balance.formatted} <span className="text-body-sm font-normal text-neutral-500">{balance.currency}</span>
                                </p>
                            </div>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-end gap-4">
                    <div>
                        <label htmlFor="account-filter" className="text-label block text-neutral-700">
                            Account
                        </label>
                        <select
                            id="account-filter"
                            value={filters.account ?? ''}
                            disabled={isNavigating}
                            onChange={(e) => handleAccountFilterChange(e.target.value)}
                            className="focus-ring mt-1.5 block rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 disabled:opacity-50"
                        >
                            <option value="">All accounts</option>
                            {accountOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {availableTransactionTypes.length > 0 && (
                        <div>
                            <label htmlFor="type-filter" className="text-label block text-neutral-700">
                                Type
                            </label>
                            <select
                                id="type-filter"
                                value={filters.type ?? ''}
                                disabled={isNavigating}
                                onChange={(e) => handleTypeFilterChange(e.target.value)}
                                className="focus-ring mt-1.5 block rounded-md border border-neutral-300 px-3 py-2 text-sm text-neutral-900 disabled:opacity-50"
                            >
                                <option value="">All types</option>
                                {availableTransactionTypes.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {isNavigating && (
                        <span className="text-body-sm text-neutral-500" role="status" aria-live="polite">
                            Loading&hellip;
                        </span>
                    )}
                </div>

                <div className="rounded-lg border border-neutral-200 bg-white">
                    {transactions.data.length === 0 ? (
                        <div className="p-8 text-center">
                            <p className="text-body text-neutral-700">No transactions yet.</p>
                            <p className="text-body-sm mt-1 text-neutral-500">
                                Your earning and advertising activity will appear here once you have some.
                            </p>
                        </div>
                    ) : (
                        <>
                            <table className="hidden w-full text-left lg:table">
                                <caption className="sr-only">Transaction history</caption>
                                <thead>
                                    <tr className="border-b border-neutral-200">
                                        <th scope="col" className="text-caption px-4 py-3 font-medium text-neutral-500">
                                            Date
                                        </th>
                                        <th scope="col" className="text-caption px-4 py-3 font-medium text-neutral-500">
                                            Type
                                        </th>
                                        <th scope="col" className="text-caption px-4 py-3 font-medium text-neutral-500">
                                            Activity
                                        </th>
                                        <th scope="col" className="text-caption px-4 py-3 text-right font-medium text-neutral-500">
                                            Reference
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {transactions.data.map((transaction) => (
                                        <tr key={transaction.id} className="border-b border-neutral-100 last:border-0">
                                            <td className="text-body-sm px-4 py-3 align-top whitespace-nowrap text-neutral-600">
                                                {formatOccurredAt(transaction.occurredAt)}
                                            </td>
                                            <td className="text-body-sm px-4 py-3 align-top text-neutral-900">
                                                {transaction.typeLabel}
                                                {transaction.detail && <p className="text-caption mt-0.5 text-neutral-500">{transaction.detail}</p>}
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <MovementList movements={transaction.movements} />
                                            </td>
                                            <td className="px-4 py-3 text-right align-top">
                                                <span className="text-caption font-mono text-neutral-400" title={transaction.id}>
                                                    {transaction.id}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            <ul className="divide-y divide-neutral-100 lg:hidden">
                                {transactions.data.map((transaction) => (
                                    <li key={transaction.id} className="p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <span className="text-body-sm font-medium text-neutral-900">{transaction.typeLabel}</span>
                                            <span className="text-caption shrink-0 text-neutral-500">{formatOccurredAt(transaction.occurredAt)}</span>
                                        </div>
                                        {transaction.detail && <p className="text-caption mt-0.5 text-neutral-500">{transaction.detail}</p>}
                                        <div className="mt-2">
                                            <MovementList movements={transaction.movements} />
                                        </div>
                                        <p className="text-caption mt-2 truncate font-mono text-neutral-400" title={transaction.id}>
                                            {transaction.id}
                                        </p>
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

TransactionHistory.layout = (page: ReactElement) => <AppLayout pageTitle="Transactions">{page as ReactNode}</AppLayout>;
