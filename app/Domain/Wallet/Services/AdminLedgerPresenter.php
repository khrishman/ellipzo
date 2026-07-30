<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;

/**
 * The sole boundary between wallet-domain models and the admin ledger
 * explorer's Inertia props (Task 2.8) - a raw Eloquent model or an
 * unrestricted ->toArray() call is never handed to Inertia::render().
 * Every field is an explicit allowlist entry.
 *
 * Email is never exposed in full anywhere this class touches - only
 * maskEmail()'s output ever reaches a prop, a redirect URL, or a query
 * string, since the filters themselves reject email-shaped input before
 * this class is ever invoked (see AdminLedgerFilterRequest).
 *
 * Ledger-audit exposure is gated entirely by the caller: presentLedgerAudit()
 * exists as a separate, explicitly-invoked method precisely so a caller
 * lacking ledger.audit.view can simply never call it - no audit query is
 * ever issued as a side effect of presenting a transaction or entry list.
 */
final class AdminLedgerPresenter
{
    private const string LEDGER_AUDIT_ACTION = 'ledger.administrative_adjustment';

    private const string LEDGER_AUDIT_ENTITY_TYPE = 'ledger_transaction';

    /**
     * The complete, exact set of keys AdministrativeAdjustmentService::
     * safeAfterState() is known to write - every one a scalar leaf, never
     * a nested structure. This is enforced here, not merely assumed from
     * that method's own docblock: filterByAllowlist() below drops any key
     * not named here, and drops even a *named* key's value if it is ever
     * unexpectedly an array/object, so a future bug or a hand-crafted
     * database row can never make an arbitrary audit_events field public
     * to a ledger.audit.view holder just by matching an approved key name.
     */
    private const array LEDGER_AUDIT_ALLOWED_AFTER_KEYS = [
        'target_wallet_account_id' => 'scalar',
        'target_account_type' => 'scalar',
        'direction' => 'scalar',
        'amount_atomic' => 'scalar',
        'currency' => 'scalar',
        'business_reference' => 'scalar',
    ];

    /**
     * AdministrativeAdjustmentService::recordNewAudit() always records
     * before: [] - a brand-new adjustment has no prior audited state.
     * No key is approved; filterByAllowlist() with an empty spec always
     * yields [], regardless of what a corrupted row might contain.
     */
    private const array LEDGER_AUDIT_ALLOWED_BEFORE_KEYS = [];

    /**
     * @return array<string, mixed>
     */
    public function presentList(CursorPaginator $page, array $reversalLinks): array
    {
        return [
            'data' => $page->getCollection()
                ->map(fn (LedgerTransaction $transaction): array => $this->presentListItem($transaction, $reversalLinks))
                ->values()
                ->all(),
            'nextCursor' => $page->nextCursor()?->encode(),
            'previousCursor' => $page->previousCursor()?->encode(),
        ];
    }

    /**
     * @param  array<string, string>  $reversalLinks
     * @return array<string, mixed>
     */
    private function presentListItem(LedgerTransaction $transaction, array $reversalLinks): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'typeLabel' => AdminLedgerLabels::transactionTypeLabel($transaction->type),
            'occurredAt' => $transaction->created_at->toIso8601String(),
            'description' => $transaction->description,
            'businessReference' => $transaction->business_reference,
            'correlationId' => $transaction->correlation_id,
            'currency' => $transaction->currency_code->value,
            'currencyScale' => $transaction->currency_scale,
            'entryCount' => $transaction->entries_count,
            'isReversal' => $transaction->reverses_transaction_id !== null,
            'originalTransactionId' => $transaction->reverses_transaction_id,
            'hasBeenReversed' => array_key_exists($transaction->id, $reversalLinks),
            'reversalTransactionId' => $reversalLinks[$transaction->id] ?? null,
            'relatedEntity' => $this->presentRelatedEntity($transaction),
            'involvedUsers' => $this->involvedUsers($transaction),
            'actor' => $this->presentActor($transaction->actor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDetail(LedgerTransaction $transaction, ?string $reversalTransactionId): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'typeLabel' => AdminLedgerLabels::transactionTypeLabel($transaction->type),
            'description' => $transaction->description,
            'businessReference' => $transaction->business_reference,
            'correlationId' => $transaction->correlation_id,
            'currency' => $transaction->currency_code->value,
            'currencyScale' => $transaction->currency_scale,
            'actor' => $this->presentActor($transaction->actor),
            'relatedEntity' => $this->presentRelatedEntity($transaction),
            'isReversal' => $transaction->reverses_transaction_id !== null,
            'originalTransactionId' => $transaction->reverses_transaction_id,
            'hasBeenReversed' => $reversalTransactionId !== null,
            'reversalTransactionId' => $reversalTransactionId,
            'createdAt' => $transaction->created_at->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presentEntries(LedgerTransaction $transaction): array
    {
        return $transaction->entries
            ->map(fn (LedgerEntry $entry): array => $this->presentEntry($entry))
            ->values()
            ->all();
    }

    /**
     * Only ever called by the controller when the authenticated staff user
     * holds ledger.audit.view - never invoked otherwise, which is what
     * keeps the underlying AuditEvent query from ever running for a
     * viewer who lacks the permission. Scoped to exactly the one ledger-
     * related audit action AdministrativeAdjustmentService itself writes;
     * no other AuditEvent action (e.g. staff.role_changed,
     * account.status_changed) can ever be returned by this method.
     *
     * before_state/after_state are never passed through wholesale - this
     * class does not trust that whatever wrote the row already shaped it
     * safely (AdministrativeAdjustmentService::safeAfterState()'s own
     * docblock guarantee is a property of *that* class, not something
     * this one is entitled to assume). filterByAllowlist() re-enforces the
     * boundary at read time: only the named keys survive, and only when
     * their value is genuinely a scalar - a database row containing extra
     * fields (a future bug, a hand-edited row, a different future ledger-
     * audit action reusing this same entity_type/action pair) can never
     * become automatically public just by existing in the row.
     */
    public function presentLedgerAudit(LedgerTransaction $transaction): ?array
    {
        $event = AuditEvent::query()
            ->where('entity_type', self::LEDGER_AUDIT_ENTITY_TYPE)
            ->where('entity_key', $transaction->id)
            ->where('action', self::LEDGER_AUDIT_ACTION)
            ->with('actor.profile')
            ->first();

        if ($event === null) {
            return null;
        }

        return [
            'action' => $event->action,
            'internalReason' => $event->reason,
            'actor' => $this->presentActor($event->actor),
            'before' => self::filterByAllowlist($event->before_state ?? [], self::LEDGER_AUDIT_ALLOWED_BEFORE_KEYS),
            'after' => self::filterByAllowlist($event->after_state ?? [], self::LEDGER_AUDIT_ALLOWED_AFTER_KEYS),
            'correlationId' => $event->correlation_id,
            'occurredAt' => $event->created_at->toIso8601String(),
        ];
    }

    /**
     * A generic, genuinely recursive JSON-object allowlist filter - not
     * merely a top-level key check. $spec maps each approved key to one of:
     * 'scalar' (the value must be a string/int/float/bool/null leaf - an
     * array/object under this key is treated as anomalous and dropped,
     * never passed through or recursed into blindly), 'list-of-scalars'
     * (the value must be a sequential list; any non-scalar element is
     * dropped from the returned list), or a nested array (itself a spec of
     * this same shape - the value must be a non-list associative array,
     * filtered recursively against that nested spec). A key absent from
     * $spec is always dropped, regardless of its value's shape - this is
     * what stops an unapproved key from smuggling a canary value through
     * as a nested object or as an array element.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, 'scalar'|'list-of-scalars'|array<string, mixed>>  $spec
     * @return array<string, mixed>
     */
    private static function filterByAllowlist(array $data, array $spec): array
    {
        $filtered = [];

        foreach ($spec as $key => $rule) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if ($rule === 'scalar') {
                if (! is_array($value)) {
                    $filtered[$key] = $value;
                }

                continue;
            }

            if ($rule === 'list-of-scalars') {
                if (is_array($value) && array_is_list($value)) {
                    $filtered[$key] = array_values(array_filter($value, fn (mixed $item): bool => ! is_array($item)));
                }

                continue;
            }

            if (is_array($rule) && is_array($value) && ! array_is_list($value)) {
                $filtered[$key] = self::filterByAllowlist($value, $rule);
            }
        }

        return $filtered;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function transactionTypeOptions(): array
    {
        return array_map(
            fn (LedgerTransactionType $type): array => ['value' => $type->value, 'label' => AdminLedgerLabels::transactionTypeLabel($type)],
            LedgerTransactionType::cases(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function accountTypeOptions(): array
    {
        return array_map(
            fn (WalletAccountType $type): array => ['value' => $type->value, 'label' => AdminLedgerLabels::accountTypeLabel($type)],
            WalletAccountType::cases(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEntry(LedgerEntry $entry): array
    {
        $account = $entry->walletAccount;
        $money = Money::fromAtomic($entry->amount_atomic, Currency::USD);

        return [
            'id' => $entry->id,
            'entryType' => $entry->entry_type->value,
            'atomic' => $money->atomicString(),
            'formatted' => $money->toDecimalString(),
            'currency' => $money->currency()->value,
            'walletAccountId' => $account->id,
            'accountType' => $account->account_type->value,
            'accountLabel' => AdminLedgerLabels::accountTypeLabel($account->account_type),
            'scopeType' => $account->scope_type->value,
            'scopeLabel' => AdminLedgerLabels::scopeTypeLabel($account->scope_type),
            'user' => $account->scope_type === WalletAccountScopeType::User ? $this->presentUserIdentity($account->user) : null,
        ];
    }

    /**
     * @return list<array{id: int, username: string|null, maskedEmail: string}>
     */
    private function involvedUsers(LedgerTransaction $transaction): array
    {
        $seenUserIds = [];
        $involved = [];

        foreach ($transaction->entries as $entry) {
            $account = $entry->walletAccount;

            if ($account->scope_type !== WalletAccountScopeType::User || $account->user === null) {
                continue;
            }

            $user = $account->user;

            if (array_key_exists($user->id, $seenUserIds)) {
                continue;
            }

            $seenUserIds[$user->id] = true;
            $involved[] = $this->presentUserIdentity($user);
        }

        return $involved;
    }

    /**
     * @return array{id: int, username: string|null, maskedEmail: string}|null
     */
    private function presentUserIdentity(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'username' => $user->profile?->username,
            'maskedEmail' => self::maskEmail($user->email),
        ];
    }

    /**
     * @return array{id: int, username: string|null}|null
     */
    private function presentActor(?User $actor): ?array
    {
        if ($actor === null) {
            return null;
        }

        return [
            'id' => $actor->id,
            'username' => $actor->profile?->username,
        ];
    }

    /**
     * @return array{type: string, id: string}|null
     */
    private function presentRelatedEntity(LedgerTransaction $transaction): ?array
    {
        if ($transaction->related_entity_type === null) {
            return null;
        }

        return [
            'type' => $transaction->related_entity_type->value,
            'id' => $transaction->related_entity_id,
        ];
    }

    /**
     * Preserves the first local-part character, replaces the rest of the
     * local part with a fixed "***", and preserves the domain unchanged -
     *
     * "person@example.com" becomes "p***@example.com". A missing or
     * malformed address (no "@", or "@" as the very first/last character)
     * falls back to a neutral placeholder rather than guessing - this
     * branch is defensive only, since users.email is a required, unique
     * column and every real value reaching here is already a genuine
     * email address.
     */
    private static function maskEmail(?string $email): string
    {
        if ($email === null) {
            return 'unavailable';
        }

        $trimmed = trim($email);
        $atPosition = strpos($trimmed, '@');

        if ($atPosition === false || $atPosition === 0 || $atPosition === strlen($trimmed) - 1) {
            return 'unavailable';
        }

        $localPart = substr($trimmed, 0, $atPosition);
        $domain = substr($trimmed, $atPosition + 1);

        return substr($localPart, 0, 1).'***@'.$domain;
    }
}
