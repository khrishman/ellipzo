<?php

declare(strict_types=1);

namespace App\Http\Controllers\Wallet;

use App\Domain\Wallet\Data\TransactionHistoryFilters;
use App\Domain\Wallet\Services\TransactionHistoryPresenter;
use App\Domain\Wallet\Services\UserTransactionHistoryQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\TransactionHistoryFilterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\Cursor;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

/**
 * The authenticated user's own balance-and-history page. Deliberately
 * reads only $request->user() - there is no user identifier in the route
 * or in any query parameter, so there is nothing here for ownership
 * filtering to get wrong even by mistake. See
 * UserTransactionHistoryQuery/TransactionHistoryPresenter for the actual
 * ownership and privacy enforcement; this controller only wires the HTTP
 * request (cursor decoding, canonical malformed-cursor redirect) to those.
 */
final class TransactionHistoryController extends Controller
{
    public function __construct(
        private readonly UserTransactionHistoryQuery $historyQuery,
        private readonly TransactionHistoryPresenter $presenter,
    ) {}

    public function show(TransactionHistoryFilterRequest $request): Response|RedirectResponse
    {
        $filters = new TransactionHistoryFilters(
            accountType: $request->accountTypeFilter(),
            transactionType: $request->transactionTypeFilter(),
        );

        $cursor = $this->resolveCursor($request->query('cursor'));

        if ($cursor === false) {
            return $this->redirectToCanonicalUrl($filters);
        }

        $accounts = $this->historyQuery->resolveUserAccounts($request->user());

        try {
            $page = $this->historyQuery->forUser($accounts, $filters, $cursor);
        } catch (UnexpectedValueException) {
            // A structurally-valid cursor (decodes fine, has the expected
            // envelope) whose parameters don't match this query's own
            // created_at/id ordering - e.g. tampered, or reused from a
            // different filtered query. Cursor::parameter() throws for
            // this; caught narrowly here, nowhere else in this method, so
            // a genuine programming error elsewhere is never silently
            // swallowed by this same catch.
            return $this->redirectToCanonicalUrl($filters);
        }

        $availableTypes = $this->historyQuery->availableTransactionTypes($accounts);

        return Inertia::render(
            'wallet/transactions/index',
            $this->presenter->present($accounts, $filters, $page, $availableTypes),
        );
    }

    /**
     * Resolves the raw `cursor` query parameter into one of three states:
     * null (no cursor supplied - a normal first-page request), a real
     * Cursor (successfully decoded), or false (a non-empty value was
     * supplied but Cursor::fromEncoded() could not decode it - malformed
     * base64/JSON, or missing its required envelope key). Deciding this
     * explicitly, before ever calling cursorPaginate(), is what stops a
     * malformed cursor from silently and indistinguishably falling back to
     * "no cursor" the way Laravel's own default resolution would.
     */
    private function resolveCursor(mixed $raw): Cursor|false|null
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return Cursor::fromEncoded($raw) ?? false;
    }

    private function redirectToCanonicalUrl(TransactionHistoryFilters $filters): RedirectResponse
    {
        $preserved = array_filter([
            'account' => $filters->accountType?->value,
            'type' => $filters->transactionType?->value,
        ], fn (?string $value): bool => $value !== null);

        // Never carries a cursor parameter, so this redirect target cannot
        // itself be malformed - no redirect loop is reachable from here.
        return redirect()->route('transactions.index', $preserved);
    }
}
