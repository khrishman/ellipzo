<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Data\TransactionHistoryFilters;
use App\Domain\Wallet\Data\UserWalletAccounts;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Exceptions\WalletAccountInvariantException;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\WalletAccount;
use App\Models\User;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * The dedicated, read-only query path for the authenticated user's own
 * transaction history (Task 2.7) - never a write path, never touched by
 * LedgerPostingEngine or any other financial write service.
 *
 * Ownership is enforced structurally, not by a filter the caller could
 * omit: every method here takes an already-resolved UserWalletAccounts
 * (this class's own resolveUserAccounts(), never WalletAccountProvisioner -
 * that class attempts an insert on every call, even when every account
 * already exists, which would make this "read-only" page issue a write
 * statement) and every query is scoped to exactly those four account IDs.
 * A counterparty's entry (a platform or provider account's own leg of the
 * same transaction) is never fetched into PHP at all - the eager-loaded
 * `entries` relation is itself constrained to the same four IDs, not
 * merely filtered after the fact.
 */
final class UserTransactionHistoryQuery
{
    private const int PER_PAGE = 20;

    public function resolveUserAccounts(User $user): UserWalletAccounts
    {
        $accounts = WalletAccount::query()
            ->where('user_id', $user->id)
            ->where('scope_type', WalletAccountScopeType::User->value)
            ->get()
            ->keyBy(fn (WalletAccount $account): string => $account->account_type->value);

        foreach (TransactionHistoryFilters::allowedAccountTypes() as $type) {
            if (! $accounts->has($type->value)) {
                throw new WalletAccountInvariantException(
                    "The authenticated user is missing the required {$type->value} wallet account.",
                );
            }
        }

        return new UserWalletAccounts(
            earningAvailable: $accounts[WalletAccountType::EarningAvailable->value],
            earningHeld: $accounts[WalletAccountType::EarningHeld->value],
            advertisingAvailable: $accounts[WalletAccountType::AdvertisingAvailable->value],
            advertisingReserved: $accounts[WalletAccountType::AdvertisingReserved->value],
            userId: $user->id,
        );
    }

    /**
     * Stable created_at DESC, id DESC cursor pagination over transactions
     * touching at least one of the user's own accounts. The account
     * filter narrows which transactions qualify but never narrows the
     * eager-loaded entries themselves - every one of the user's own
     * movements within a matching transaction is still returned, exactly
     * as instructed.
     *
     * $cursor is a pre-decoded Cursor, resolved by the controller (an HTTP
     * concern) - a malformed cursor string is never accepted here as a
     * bare string, so this method cannot silently treat "unparseable" the
     * same as "no cursor". An UnexpectedValueException from a
     * structurally-valid-but-wrong-shaped cursor is allowed to propagate;
     * translating that into a redirect is the controller's job, not this
     * read path's.
     */
    public function forUser(UserWalletAccounts $accounts, TransactionHistoryFilters $filters, ?Cursor $cursor): CursorPaginator
    {
        $accountIds = $this->accountIds($accounts);

        $query = LedgerTransaction::query()
            ->whereHas('entries', fn ($entries) => $entries->whereIn('wallet_account_id', $accountIds))
            ->with(['entries' => fn ($entries) => $entries
                ->whereIn('wallet_account_id', $accountIds)
                ->orderBy('created_at')
                ->orderBy('id'),
            ]);

        if ($filters->transactionType !== null) {
            $query->where('type', $filters->transactionType->value);
        }

        if ($filters->accountType !== null) {
            $targetAccountId = $this->accountIdForType($accounts, $filters->accountType);
            $query->whereHas('entries', fn ($entries) => $entries->where('wallet_account_id', $targetAccountId));
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $cursor);
    }

    /**
     * The distinct transaction types that actually appear in this user's
     * own history, ownership-scoped identically to forUser() and
     * deliberately never affected by the currently active filters - the
     * frontend's type-filter options must stay stable as the user filters,
     * not shrink to only what the current filter itself would show. SQL
     * DISTINCT keeps this a single query returning at most nine rows,
     * never the full matching transaction set.
     *
     * @return list<LedgerTransactionType>
     */
    public function availableTransactionTypes(UserWalletAccounts $accounts): array
    {
        $accountIds = $this->accountIds($accounts);

        // Eloquent's own pluck() applies the model's cast (verified
        // empirically, not assumed) - these are already LedgerTransactionType
        // instances, never raw strings.
        return LedgerTransaction::query()
            ->whereHas('entries', fn ($entries) => $entries->whereIn('wallet_account_id', $accountIds))
            ->select('type')
            ->distinct()
            ->pluck('type')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function accountIds(UserWalletAccounts $accounts): array
    {
        return [
            $accounts->earningAvailable->id,
            $accounts->earningHeld->id,
            $accounts->advertisingAvailable->id,
            $accounts->advertisingReserved->id,
        ];
    }

    private function accountIdForType(UserWalletAccounts $accounts, WalletAccountType $type): string
    {
        return match ($type) {
            WalletAccountType::EarningAvailable => $accounts->earningAvailable->id,
            WalletAccountType::EarningHeld => $accounts->earningHeld->id,
            WalletAccountType::AdvertisingAvailable => $accounts->advertisingAvailable->id,
            WalletAccountType::AdvertisingReserved => $accounts->advertisingReserved->id,
            default => throw new LedgerInvariantViolationException('Unsupported transaction-history account filter type.'),
        };
    }
}
