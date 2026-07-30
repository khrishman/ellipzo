import { Head, Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, ChevronLeft } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type JsonValue = string | number | boolean | null | JsonValue[] | { [key: string]: JsonValue };

interface UserIdentity {
    id: number;
    username: string | null;
    maskedEmail: string;
}

interface ActorIdentity {
    id: number;
    username: string | null;
}

interface RelatedEntity {
    type: string;
    id: string;
}

interface AdminLedgerEntry {
    id: string;
    entryType: 'debit' | 'credit';
    atomic: string;
    formatted: string;
    currency: string;
    walletAccountId: string;
    accountType: string;
    accountLabel: string;
    scopeType: 'user' | 'platform' | 'provider';
    scopeLabel: string;
    user: UserIdentity | null;
}

interface AdminLedgerTransactionDetail {
    id: string;
    type: string;
    typeLabel: string;
    description: string;
    businessReference: string;
    correlationId: string;
    currency: string;
    currencyScale: number;
    actor: ActorIdentity | null;
    relatedEntity: RelatedEntity | null;
    isReversal: boolean;
    originalTransactionId: string | null;
    hasBeenReversed: boolean;
    reversalTransactionId: string | null;
    createdAt: string;
}

interface AdminLedgerAudit {
    action: string;
    internalReason: string;
    actor: ActorIdentity;
    before: Record<string, JsonValue>;
    after: Record<string, JsonValue>;
    correlationId: string;
    occurredAt: string;
}

interface AdminLedgerShowProps {
    transaction: AdminLedgerTransactionDetail;
    entries: AdminLedgerEntry[];
    canViewLedgerAudit: boolean;
    ledgerAudit: AdminLedgerAudit | null;
}

const utcFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' });

function formatUtc(iso: string): string {
    return `${utcFormatter.format(new Date(iso))} UTC`;
}

function renderJsonValue(value: JsonValue): string {
    if (value === null) return '—';
    if (typeof value === 'object') return JSON.stringify(value);

    return String(value);
}

/**
 * Debit/credit is always shown as an icon paired with visible text - color
 * is decorative only, never the sole signal.
 */
function EntryTypeBadge({ entryType }: { entryType: 'debit' | 'credit' }) {
    const Icon = entryType === 'credit' ? ArrowUpRight : ArrowDownRight;
    const label = entryType === 'credit' ? 'Credit' : 'Debit';
    const colorClass = entryType === 'credit' ? 'text-success-700' : 'text-danger-text';

    return (
        <span className={`inline-flex shrink-0 items-center gap-1 ${colorClass}`}>
            <Icon className="size-4" aria-hidden="true" />
            <span className="text-caption font-medium">{label}</span>
        </span>
    );
}

function JsonPropertyList({ values }: { values: Record<string, JsonValue> }) {
    const keys = Object.keys(values);

    if (keys.length === 0) {
        return <p className="text-body-sm text-neutral-500">None recorded.</p>;
    }

    return (
        <dl className="grid grid-cols-1 gap-x-4 gap-y-1.5 sm:grid-cols-2">
            {keys.map((key) => (
                <div key={key} className="flex items-baseline justify-between gap-2 border-b border-neutral-100 py-1 sm:justify-start">
                    <dt className="text-caption text-neutral-500">{key}</dt>
                    <dd className="text-body-sm text-neutral-900">{renderJsonValue(values[key])}</dd>
                </div>
            ))}
        </dl>
    );
}

export default function AdminLedgerShow({ transaction, entries, canViewLedgerAudit, ledgerAudit }: AdminLedgerShowProps) {
    return (
        <>
            <Head title={`Ledger transaction ${transaction.id}`} />

            <div className="space-y-6">
                <div>
                    <Link href="/admin/ledger" className="focus-ring text-body-sm inline-flex items-center gap-1 font-medium text-brand-700">
                        <ChevronLeft className="size-4" aria-hidden="true" />
                        Back to ledger
                    </Link>
                </div>

                <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-h1 text-neutral-900">{transaction.typeLabel}</h1>
                        <span className="text-caption inline-flex items-center gap-1 rounded-full bg-neutral-100 px-2.5 py-1 font-medium text-neutral-600">
                            Read only
                        </span>
                    </div>
                    <p className="text-body-sm font-mono text-neutral-500">{transaction.id}</p>
                </div>

                <div className="grid grid-cols-1 gap-4 rounded-lg border border-neutral-200 bg-white p-6 sm:grid-cols-2">
                    <div>
                        <p className="text-caption text-neutral-500">Description</p>
                        <p className="text-body-sm text-neutral-900">{transaction.description}</p>
                    </div>
                    <div>
                        <p className="text-caption text-neutral-500">Occurred (UTC)</p>
                        <p className="text-body-sm text-neutral-900">{formatUtc(transaction.createdAt)}</p>
                    </div>
                    <div>
                        <p className="text-caption text-neutral-500">Business reference</p>
                        <p className="text-body-sm font-mono text-neutral-900">{transaction.businessReference}</p>
                    </div>
                    <div>
                        <p className="text-caption text-neutral-500">Correlation ID</p>
                        <p className="text-body-sm font-mono text-neutral-900">{transaction.correlationId}</p>
                    </div>
                    <div>
                        <p className="text-caption text-neutral-500">Currency</p>
                        <p className="text-body-sm text-neutral-900">
                            {transaction.currency} (scale {transaction.currencyScale})
                        </p>
                    </div>
                    <div>
                        <p className="text-caption text-neutral-500">Staff actor</p>
                        <p className="text-body-sm text-neutral-900">
                            {transaction.actor ? (transaction.actor.username ?? `User #${transaction.actor.id}`) : '—'}
                        </p>
                    </div>
                    {transaction.relatedEntity && (
                        <div>
                            <p className="text-caption text-neutral-500">Related entity</p>
                            <p className="text-body-sm text-neutral-900">
                                {transaction.relatedEntity.type} <span className="font-mono text-neutral-500">{transaction.relatedEntity.id}</span>
                            </p>
                        </div>
                    )}
                    {transaction.isReversal && transaction.originalTransactionId && (
                        <div>
                            <p className="text-caption text-neutral-500">Reverses</p>
                            <Link
                                href={`/admin/ledger/${transaction.originalTransactionId}`}
                                className="focus-ring text-body-sm font-medium text-brand-700 hover:underline"
                            >
                                View original transaction
                            </Link>
                        </div>
                    )}
                    {transaction.hasBeenReversed && transaction.reversalTransactionId && (
                        <div>
                            <p className="text-caption text-neutral-500">Reversed by</p>
                            <Link
                                href={`/admin/ledger/${transaction.reversalTransactionId}`}
                                className="focus-ring text-body-sm font-medium text-brand-700 hover:underline"
                            >
                                View reversal transaction
                            </Link>
                        </div>
                    )}
                </div>

                <div>
                    <h2 className="text-h3 text-neutral-900">Entries</h2>
                    <div className="mt-3 overflow-x-auto rounded-lg border border-neutral-200 bg-white">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">Ledger entries for this transaction</caption>
                            <thead className="border-b border-neutral-200 bg-neutral-50">
                                <tr>
                                    <th scope="col" className="px-4 py-2 font-medium text-neutral-500">
                                        Type
                                    </th>
                                    <th scope="col" className="px-4 py-2 text-right font-medium text-neutral-500">
                                        Amount
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium text-neutral-500">
                                        Account
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium text-neutral-500">
                                        Scope
                                    </th>
                                    <th scope="col" className="px-4 py-2 font-medium text-neutral-500">
                                        Identity
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.map((entry) => (
                                    <tr key={entry.id} className="border-b border-neutral-100 last:border-0">
                                        <td className="px-4 py-2">
                                            <EntryTypeBadge entryType={entry.entryType} />
                                        </td>
                                        <td className="px-4 py-2 text-right font-medium text-neutral-900">
                                            {entry.formatted} <span className="font-normal text-neutral-500">{entry.currency}</span>
                                        </td>
                                        <td className="px-4 py-2 text-neutral-700">{entry.accountLabel}</td>
                                        <td className="px-4 py-2 text-neutral-600">{entry.scopeLabel}</td>
                                        <td className="px-4 py-2 text-neutral-600">
                                            {entry.user ? (
                                                <>
                                                    {entry.user.username ?? 'No username'} <span className="text-neutral-400">·</span>{' '}
                                                    {entry.user.maskedEmail}
                                                </>
                                            ) : (
                                                <span className="text-neutral-400">—</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {canViewLedgerAudit && ledgerAudit && (
                    <div>
                        <h2 className="text-h3 text-neutral-900">Administrative-adjustment audit</h2>
                        <div className="mt-3 space-y-4 rounded-lg border border-neutral-200 bg-white p-6">
                            <div>
                                <p className="text-caption text-neutral-500">Internal reason</p>
                                <p className="text-body-sm text-neutral-900">{ledgerAudit.internalReason}</p>
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <p className="text-caption text-neutral-500">Staff actor</p>
                                    <p className="text-body-sm text-neutral-900">{ledgerAudit.actor.username ?? `User #${ledgerAudit.actor.id}`}</p>
                                </div>
                                <div>
                                    <p className="text-caption text-neutral-500">Audit occurred (UTC)</p>
                                    <p className="text-body-sm text-neutral-900">{formatUtc(ledgerAudit.occurredAt)}</p>
                                </div>
                                <div>
                                    <p className="text-caption text-neutral-500">Audit correlation ID</p>
                                    <p className="text-body-sm font-mono text-neutral-900">{ledgerAudit.correlationId}</p>
                                </div>
                            </div>
                            <div>
                                <p className="text-caption mb-1 text-neutral-500">Recorded details</p>
                                <JsonPropertyList values={ledgerAudit.after} />
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

AdminLedgerShow.layout = (page: ReactElement) => <AdminLayout pageTitle="Ledger transaction">{page as ReactNode}</AdminLayout>;
