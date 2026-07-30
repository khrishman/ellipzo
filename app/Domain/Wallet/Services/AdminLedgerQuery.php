<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Data\AdminLedgerFilters;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Models\UserProfile;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * The dedicated, strictly read-only staff ledger query path (Task 2.8) -
 * never a write path, never touched by LedgerPostingEngine or any other
 * financial write service, never calls WalletAccountProvisioner (which
 * would attempt an insert even when every account already exists),
 * never reads balance_snapshots (this feature shows transactions and
 * entries only, never a derived "current balance").
 *
 * Unlike UserTransactionHistoryQuery (Task 2.7), nothing here is scoped
 * to a single user's own accounts - every ledger.view holder may browse
 * every transaction, so ownership filtering is deliberately absent; only
 * the explicit, allowlisted staff filters below narrow the result set.
 */
final class AdminLedgerQuery
{
    private const int PER_PAGE = 25;

    public function paginate(AdminLedgerFilters $filters, ?Cursor $cursor): CursorPaginator
    {
        $query = LedgerTransaction::query()
            ->with([
                'entries' => fn ($entries) => $entries->orderBy('created_at')->orderBy('id'),
                'entries.walletAccount.user.profile',
                'actor.profile',
            ])
            ->withCount('entries');

        if ($filters->transactionId !== null) {
            $query->where('id', $filters->transactionId);
        }

        if ($filters->transactionType !== null) {
            $query->where('type', $filters->transactionType->value);
        }

        if ($filters->accountType !== null) {
            $query->whereHas('entries.walletAccount', fn ($accounts) => $accounts->where('account_type', $filters->accountType->value));
        }

        if ($filters->userId !== null) {
            $query->whereHas('entries.walletAccount', fn ($accounts) => $accounts->where('user_id', $filters->userId));
        }

        if ($filters->username !== null) {
            $normalized = UserProfile::normalizeUsername($filters->username);
            $query->whereHas(
                'entries.walletAccount.user.profile',
                fn ($profiles) => $profiles->where('username_normalized', $normalized),
            );
        }

        if ($filters->businessReference !== null) {
            $query->where('business_reference', $filters->businessReference);
        }

        if ($filters->correlationId !== null) {
            $query->where('correlation_id', $filters->correlationId);
        }

        if ($filters->dateFrom !== null) {
            $query->where('created_at', '>=', $filters->dateFrom);
        }

        if ($filters->dateTo !== null) {
            $query->where('created_at', '<=', $filters->dateTo);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $cursor);
    }

    /**
     * One batched query for an entire page of transactions - never one
     * lookup per row. Returns a map of original-transaction-ID =>
     * reversal-transaction-ID for whichever of $transactionIds have
     * actually been reversed; an ID absent from the returned map has not
     * been reversed.
     *
     * @param  list<string>  $transactionIds
     * @return array<string, string>
     */
    public function reversalLinksFor(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        return LedgerTransaction::query()
            ->whereIn('reverses_transaction_id', $transactionIds)
            ->pluck('id', 'reverses_transaction_id')
            ->all();
    }

    /**
     * The reversal transaction's own ID, if $transactionId has been
     * reversed - null otherwise. reverses_transaction_id is uniquely
     * constrained, so at most one row can ever match.
     */
    public function reversalOf(string $transactionId): ?string
    {
        return LedgerTransaction::query()
            ->where('reverses_transaction_id', $transactionId)
            ->value('id');
    }

    /**
     * Loads every relation the detail page needs directly onto the
     * already route-bound, already-authorized model - no second
     * `WHERE id = ?` lookup is ever issued to obtain the same transaction
     * a second time.
     */
    public function loadDetailRelations(LedgerTransaction $transaction): LedgerTransaction
    {
        $transaction->load([
            'entries' => fn ($entries) => $entries->orderBy('created_at')->orderBy('id'),
            'entries.walletAccount.user.profile',
            'actor.profile',
        ]);

        return $transaction;
    }
}
