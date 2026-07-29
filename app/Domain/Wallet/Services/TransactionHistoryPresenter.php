<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\TransactionHistoryFilters;
use App\Domain\Wallet\Data\UserWalletAccounts;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\WalletAccount;
use Illuminate\Pagination\CursorPaginator;

/**
 * The sole boundary between wallet-domain models and the transaction
 * history Inertia page - a raw Eloquent model or a raw request/response
 * array is never handed to Inertia::render() directly. Every field here is
 * an explicit allowlist entry; nothing is ever passed through wholesale
 * (no ->toArray(), no ->jsonSerialize() of a model).
 *
 * Description exposure is deliberately narrow: only
 * LedgerTransactionType::AdministrativeAdjustment ever surfaces
 * ledger_transactions.description, because
 * SubmitAdministrativeAdjustmentCommand::$userVisibleDescription is the
 * only description field in this codebase with an explicit, validated,
 * user-safety contract (never derived from the staff-only internal
 * reason). Every other type's description - including a reversal's own
 * description, which LedgerPostingEngine populates directly from
 * ReversalRequest::$reason, a staff-facing field - has no such guarantee
 * and is never exposed.
 */
final class TransactionHistoryPresenter
{
    public function __construct(
        private readonly LedgerBalanceReader $balanceReader,
    ) {}

    /**
     * @param  list<LedgerTransactionType>  $availableTypes
     * @return array<string, mixed>
     */
    public function present(
        UserWalletAccounts $accounts,
        TransactionHistoryFilters $filters,
        CursorPaginator $page,
        array $availableTypes,
    ): array {
        $accountTypeById = $this->accountTypeById($accounts);

        return [
            'balances' => $this->presentBalances($accounts),
            'transactions' => [
                'data' => $page->getCollection()
                    ->map(fn (LedgerTransaction $transaction): array => $this->presentTransaction($transaction, $accountTypeById))
                    ->values()
                    ->all(),
                'nextCursor' => $page->nextCursor()?->encode(),
                'previousCursor' => $page->previousCursor()?->encode(),
            ],
            'filters' => [
                'account' => $filters->accountType?->value,
                'type' => $filters->transactionType?->value,
            ],
            'accountOptions' => $this->accountOptions(),
            'availableTransactionTypes' => array_map(
                fn (LedgerTransactionType $type): array => [
                    'value' => $type->value,
                    'label' => TransactionHistoryLabels::transactionTypeLabel($type),
                ],
                $availableTypes,
            ),
        ];
    }

    /**
     * @return array<string, array{atomic: string, formatted: string, currency: string}>
     */
    private function presentBalances(UserWalletAccounts $accounts): array
    {
        return [
            WalletAccountType::EarningAvailable->value => $this->presentBalance($accounts->earningAvailable),
            WalletAccountType::EarningHeld->value => $this->presentBalance($accounts->earningHeld),
            WalletAccountType::AdvertisingAvailable->value => $this->presentBalance($accounts->advertisingAvailable),
            WalletAccountType::AdvertisingReserved->value => $this->presentBalance($accounts->advertisingReserved),
        ];
    }

    /**
     * @return array{atomic: string, formatted: string, currency: string}
     */
    private function presentBalance(WalletAccount $account): array
    {
        // LedgerBalanceReader derives fresh from ledger_entries - never
        // balance_snapshots, which is non-authoritative and can be stale
        // or absent entirely without affecting this figure at all.
        return $this->presentMoney($this->balanceReader->currentBalance($account)->balance);
    }

    /**
     * @return array{atomic: string, formatted: string, currency: string}
     */
    private function presentMoney(Money $money): array
    {
        return [
            'atomic' => $money->atomicString(),
            'formatted' => $money->toDecimalString(),
            'currency' => $money->currency()->value,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function accountOptions(): array
    {
        return array_map(
            fn (WalletAccountType $type): array => [
                'value' => $type->value,
                'label' => TransactionHistoryLabels::accountLabel($type),
            ],
            TransactionHistoryFilters::allowedAccountTypes(),
        );
    }

    /**
     * @param  array<string, WalletAccountType>  $accountTypeById
     * @return array<string, mixed>
     */
    private function presentTransaction(LedgerTransaction $transaction, array $accountTypeById): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'typeLabel' => TransactionHistoryLabels::transactionTypeLabel($transaction->type),
            'occurredAt' => $transaction->created_at->toIso8601String(),
            'detail' => $this->safeDetail($transaction),
            'movements' => $transaction->entries
                ->map(fn (LedgerEntry $entry): array => $this->presentMovement($entry, $accountTypeById[$entry->wallet_account_id]))
                ->values()
                ->all(),
        ];
    }

    private function safeDetail(LedgerTransaction $transaction): ?string
    {
        return $transaction->type === LedgerTransactionType::AdministrativeAdjustment
            ? $transaction->description
            : null;
    }

    /**
     * @return array{accountType: string, accountLabel: string, direction: string, atomic: string, formatted: string, currency: string}
     */
    private function presentMovement(LedgerEntry $entry, WalletAccountType $accountType): array
    {
        $direction = $entry->entry_type === $accountType->normalEntrySide()
            ? 'increase'
            : 'decrease';

        $money = $this->presentMoney(Money::fromAtomic($entry->amount_atomic, Currency::USD));

        return [
            'accountType' => $accountType->value,
            'accountLabel' => TransactionHistoryLabels::accountLabel($accountType),
            'direction' => $direction,
            ...$money,
        ];
    }

    /**
     * @return array<string, WalletAccountType>
     */
    private function accountTypeById(UserWalletAccounts $accounts): array
    {
        return [
            $accounts->earningAvailable->id => WalletAccountType::EarningAvailable,
            $accounts->earningHeld->id => WalletAccountType::EarningHeld,
            $accounts->advertisingAvailable->id => WalletAccountType::AdvertisingAvailable,
            $accounts->advertisingReserved->id => WalletAccountType::AdvertisingReserved,
        ];
    }
}
