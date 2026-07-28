<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Data\UserWalletAccounts;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\InvalidWalletAccountScopeException;
use App\Domain\Wallet\Exceptions\WalletAccountInvariantException;
use App\Domain\Wallet\Models\WalletAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only path by which a WalletAccount row is ever created.
 *
 * WalletAccount is fully guarded (#[Fillable([])]); every account here is
 * built via explicit property assignment followed by save() - never
 * create(), forceCreate(), or Model::unguarded(). Direct property
 * assignment bypasses fillable/guarded entirely (it never calls fill()),
 * so this genuinely never unguards anything, even temporarily. This is a
 * deliberate departure from UserConsent::recordAcceptance()'s
 * forceCreate() pattern elsewhere in this codebase - forceCreate() calls
 * Model::unguarded() internally, which this class must not do.
 *
 * Honest limitation: this stops accidental mass assignment. It cannot stop
 * a developer who deliberately assigns WalletAccount properties directly
 * somewhere outside this class - that remains a procedural discipline
 * (code review, app/Domain/Wallet/ being the only expected caller), not a
 * language-enforced guarantee.
 */
final class WalletAccountProvisioner
{
    private const string PLATFORM_SCOPE_KEY = 'ellipzo';

    /**
     * @var list<WalletAccountType>
     */
    private const array USER_ACCOUNT_TYPES = [
        WalletAccountType::EarningAvailable,
        WalletAccountType::EarningHeld,
        WalletAccountType::AdvertisingAvailable,
        WalletAccountType::AdvertisingReserved,
    ];

    /**
     * Provision (or retrieve) the four accounts a user needs to
     * participate in earning and advertising, atomically, in its own
     * self-transacting call. The only entry point a caller without an
     * already-open transaction should use.
     */
    public function provisionUserAccounts(User $user): UserWalletAccounts
    {
        return DB::transaction(function () use ($user): UserWalletAccounts {
            return $this->provisionUserAccountsCore($user);
        });
    }

    /**
     * The transaction-aware counterpart to provisionUserAccounts(),
     * reserved for callers that already own an ambient transaction (e.g.
     * user registration, Google account completion) and must not open a
     * second, independently-committing one nested inside it. Opens no
     * transaction of its own - asserts one is already active and shares
     * every other behavior and invariant with provisionUserAccounts()
     * through the same core method below, so the two entry points cannot
     * drift apart.
     */
    public function provisionUserAccountsWithinTransaction(User $user): UserWalletAccounts
    {
        if (DB::transactionLevel() < 1) {
            throw new InvalidWalletAccountScopeException('provisionUserAccountsWithinTransaction requires an active database transaction.');
        }

        return $this->provisionUserAccountsCore($user);
    }

    /**
     * Before writing anything, every wallet account already tied to this
     * user - by user_id or by the canonical user scope/key, in either
     * direction of mismatch - is resolved and validated. A malformed
     * pre-existing row is reported via WalletAccountInvariantException
     * and nothing is created. Only genuinely missing accounts are then
     * inserted, in a fixed deterministic order: a failure partway through
     * rolls back every row newly inserted by this call (as part of
     * whichever transaction the caller is running) while leaving valid
     * pre-existing rows - which were never part of this call's
     * write-set - untouched.
     */
    private function provisionUserAccountsCore(User $user): UserWalletAccounts
    {
        if (! $user->exists || $user->getKey() === null) {
            throw new InvalidWalletAccountScopeException('Wallet accounts can only be provisioned for a persisted user.');
        }

        $userId = $user->getKey();
        $scopeKey = (string) $userId;

        $candidates = WalletAccount::query()
            ->where('user_id', $userId)
            ->orWhere(function ($query) use ($scopeKey): void {
                $query->where('scope_type', WalletAccountScopeType::User->value)
                    ->where('scope_key', $scopeKey);
            })
            ->get();

        $existingByType = [];

        foreach ($candidates as $candidate) {
            $this->assertUserScopedCandidate($candidate, $userId, $scopeKey);

            $typeValue = $candidate->account_type->value;

            if (isset($existingByType[$typeValue])) {
                throw new WalletAccountInvariantException('A duplicate wallet account exists for this user and account type.');
            }

            $existingByType[$typeValue] = $candidate;
        }

        $accounts = [];
        foreach (self::USER_ACCOUNT_TYPES as $type) {
            $accounts[$type->value] = $existingByType[$type->value]
                ?? $this->resolveAccount(WalletAccountScopeType::User, $scopeKey, $type, $userId);
        }

        return new UserWalletAccounts(
            earningAvailable: $accounts[WalletAccountType::EarningAvailable->value],
            earningHeld: $accounts[WalletAccountType::EarningHeld->value],
            advertisingAvailable: $accounts[WalletAccountType::AdvertisingAvailable->value],
            advertisingReserved: $accounts[WalletAccountType::AdvertisingReserved->value],
            userId: $userId,
        );
    }

    public function platformFeeAccount(): WalletAccount
    {
        return $this->resolveAccount(
            WalletAccountScopeType::Platform,
            self::PLATFORM_SCOPE_KEY,
            WalletAccountType::PlatformFee,
            null,
        );
    }

    /**
     * @param  mixed  $providerIdentifier  Accepted as mixed, not string, so
     *                                     a non-strict caller can never have an int/float/bool silently
     *                                     coerced into a string before is_string() runs.
     */
    public function providerClearingAccount(mixed $providerIdentifier): WalletAccount
    {
        if (! is_string($providerIdentifier)) {
            throw new InvalidWalletAccountScopeException('Provider identifier must be a string.');
        }

        $normalized = strtolower(trim($providerIdentifier));
        $length = strlen($normalized);

        if ($length < 1 || $length > 100) {
            throw new InvalidWalletAccountScopeException('Provider identifier length is out of bounds.');
        }

        if (preg_match('/^[a-z0-9_-]+$/', $normalized) !== 1) {
            throw new InvalidWalletAccountScopeException('Provider identifier contains invalid characters.');
        }

        return $this->resolveAccount(
            WalletAccountScopeType::Provider,
            $normalized,
            WalletAccountType::ProviderSettlementClearing,
            null,
        );
    }

    /**
     * Insert-first, catch-conflict, re-fetch, validate. Never repairs a
     * conflicting existing row - a mismatch is always reported, never
     * silently overwritten.
     */
    private function resolveAccount(
        WalletAccountScopeType $scope,
        string $scopeKey,
        WalletAccountType $type,
        ?int $userId,
    ): WalletAccount {
        try {
            $account = new WalletAccount;
            $account->scope_type = $scope;
            $account->scope_key = $scopeKey;
            $account->user_id = $userId;
            $account->account_type = $type;
            $account->currency_code = Currency::USD;
            $account->currency_scale = Currency::USD->scale();
            $account->save();

            return $account;
        } catch (UniqueConstraintViolationException $exception) {
            $existing = WalletAccount::query()
                ->where('scope_type', $scope->value)
                ->where('scope_key', $scopeKey)
                ->where('account_type', $type->value)
                ->where('currency_code', Currency::USD->value)
                ->first();

            if ($existing === null) {
                // The conflict was real but the canonical identity row
                // doesn't exist - an unexplained anomaly, never
                // reinterpreted as a known invariant violation.
                throw $exception;
            }

            $this->assertStoredInvariants($existing, $scope, $scopeKey, $type, $userId);

            return $existing;
        }
    }

    private function assertUserScopedCandidate(WalletAccount $candidate, int $userId, string $scopeKey): void
    {
        if ($candidate->scope_type !== WalletAccountScopeType::User) {
            throw new WalletAccountInvariantException('An existing wallet account has an unexpected scope for this user.');
        }

        if ($candidate->scope_key !== $scopeKey) {
            throw new WalletAccountInvariantException('An existing wallet account has a mismatched scope key for this user.');
        }

        if ($candidate->user_id !== $userId) {
            throw new WalletAccountInvariantException('An existing wallet account has a mismatched user for this scope.');
        }

        if ($candidate->currency_code !== Currency::USD || $candidate->currency_scale !== Currency::USD->scale()) {
            throw new WalletAccountInvariantException('An existing wallet account has an unexpected currency or scale.');
        }

        if (! in_array($candidate->account_type, self::USER_ACCOUNT_TYPES, true)) {
            throw new WalletAccountInvariantException('An existing wallet account has an account type not valid for user scope.');
        }
    }

    private function assertStoredInvariants(
        WalletAccount $account,
        WalletAccountScopeType $scope,
        string $scopeKey,
        WalletAccountType $type,
        ?int $userId,
    ): void {
        if ($account->scope_type !== $scope
            || $account->scope_key !== $scopeKey
            || $account->account_type !== $type
            || $account->user_id !== $userId
            || $account->currency_code !== Currency::USD
            || $account->currency_scale !== Currency::USD->scale()
        ) {
            throw new WalletAccountInvariantException('An existing wallet account does not match the expected identity.');
        }
    }
}
