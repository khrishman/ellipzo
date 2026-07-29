<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Data\AdministrativeAdjustmentResult;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\SubmitAdministrativeAdjustmentCommand;
use App\Domain\Wallet\Enums\AdministrativeAdjustmentDirection;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\ConflictingAdministrativeAdjustmentAuditEventException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Exceptions\MissingAdministrativeAdjustmentAuditEventException;
use App\Domain\Wallet\Exceptions\SelfAdjustmentNotPermittedException;
use App\Domain\Wallet\Exceptions\UnknownWalletAccountException;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\WalletAccount;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\UnauthorizedException;

/**
 * The only path by which a Task 2.5.1 administrative adjustment is ever
 * submitted. Constructs both posting entries itself - the caller never
 * supplies an entry list, so it is structurally impossible to submit
 * arbitrary entries or move value directly between two different users.
 *
 * Posts through LedgerPostingEngine::writeAdministrativeAdjustmentWithinTransaction(),
 * never post()/postCore(): PostLedgerTransactionCommand's own constructor
 * rejects LedgerTransactionType::AdministrativeAdjustment outright, so
 * there is no generic path capable of constructing one - a caller with
 * bare LedgerPostingEngine access cannot post an administrative
 * adjustment while skipping the permission, actor-status,
 * self-adjustment, target-account, and audit checks below. This service
 * is also the only intended activator of AdministrativeAdjustmentWriteContext,
 * the third precondition that specialized method requires alongside an
 * active database transaction and an active LedgerWriteContext.
 *
 * No new durable request table or fingerprint scheme: unlike reversals
 * (Task 2.5), an administrative adjustment posts directly and immediately,
 * so the existing LedgerTransaction-level replay machinery
 * (business_reference uniqueness + matchesSemanticPayload(), inside
 * LedgerPostingEngine) is already sufficient and reused with zero
 * duplication. The ledger posting and the audit event are written inside
 * one outer transaction this service owns - a failure anywhere rolls
 * back the suspense-account creation, ledger transaction, entries, and
 * audit event together.
 */
final class AdministrativeAdjustmentService
{
    private const string ACTION = 'ledger.administrative_adjustment';

    private const string ENTITY_TYPE = 'ledger_transaction';

    public function __construct(
        private readonly LedgerPostingEngine $ledgerPostingEngine,
        private readonly WalletAccountProvisioner $walletAccountProvisioner,
    ) {}

    public function submit(SubmitAdministrativeAdjustmentCommand $command): AdministrativeAdjustmentResult
    {
        // Cheap, no-DB-write checks first - a denied caller never opens a
        // transaction at all.
        $this->assertAuthorized($command->actor);
        $this->assertNotSelfAdjustment($command->actor, $command->targetUser);

        return DB::transaction(function () use ($command): AdministrativeAdjustmentResult {
            return LedgerWriteContext::run(function () use ($command): AdministrativeAdjustmentResult {
                return AdministrativeAdjustmentWriteContext::run(function () use ($command): AdministrativeAdjustmentResult {
                    $targetAccount = $this->resolveTargetAccount($command->targetUser, $command->targetAccountType);

                    // Lazily provisioned, exactly like platformFeeAccount() -
                    // its own save() participates in this same transaction, so
                    // a first-ever suspense-account creation rolls back with
                    // everything else on any later failure.
                    $suspenseAccount = $this->walletAccountProvisioner->platformSuspenseAccount();

                    $posted = $this->ledgerPostingEngine->writeAdministrativeAdjustmentWithinTransaction(
                        LedgerTransactionType::AdministrativeAdjustment,
                        $command->businessReference(),
                        $command->userVisibleDescription,
                        $command->actor,
                        $command->correlationId,
                        $this->buildEntries($command, $targetAccount, $suspenseAccount),
                    );

                    $auditEvent = $posted->wasReplay
                        ? $this->reconcileReplayedAudit($command, $posted->transaction, $targetAccount)
                        : $this->recordNewAudit($command, $posted->transaction, $targetAccount);

                    return new AdministrativeAdjustmentResult($posted->transaction, $auditEvent, $posted->wasReplay);
                });
            });
        });
    }

    private function assertAuthorized(User $actor): void
    {
        // Defense-in-depth, mirroring LedgerPostingEngine::postCore()'s own
        // actor re-verification: the DTO already checked this at
        // construction time, but a deleted-between-construction-and-
        // submission actor must still be rejected here, not silently
        // proceed.
        if (! $actor->exists) {
            throw new LedgerInvariantViolationException('The actor must be a persisted user.');
        }

        if (! $actor->can('ledger.adjust')) {
            throw UnauthorizedException::forPermissions(['ledger.adjust']);
        }

        // Mirrors EnsureAccountCanAccessProtectedRoutes: this service has
        // no HTTP middleware of its own to rely on, so the same rule is
        // re-verified here. The target user's own account status is
        // deliberately never checked - a corrective adjustment must remain
        // possible for a limited, suspended, or closed user.
        if ($actor->account_status->blocksProtectedRoutes()) {
            throw UnauthorizedException::forPermissions(['ledger.adjust']);
        }
    }

    private function assertNotSelfAdjustment(User $actor, User $targetUser): void
    {
        if ($targetUser->is($actor)) {
            throw new SelfAdjustmentNotPermittedException('Staff cannot submit an administrative adjustment against their own account.');
        }
    }

    /**
     * Unlocked on purpose: LedgerPostingEngine::postCore()'s own
     * lockAccountsInOrder() is the sole authoritative lock point, taken in
     * canonical ascending-ULID order across both accounts this posting
     * touches. Locking here first would be redundant and would not
     * preserve that single, deterministic ordering guarantee.
     */
    private function resolveTargetAccount(User $targetUser, WalletAccountType $targetAccountType): WalletAccount
    {
        $account = WalletAccount::query()
            ->where('user_id', $targetUser->id)
            ->where('account_type', $targetAccountType->value)
            ->first();

        if ($account === null) {
            // Deliberately not auto-provisioned: a missing account here
            // means the target user's provisioning is genuinely incomplete
            // (a pre-Task-2.3.1-backfill edge case) - that should surface
            // as a real, investigable error, not be silently repaired as
            // a side effect of an unrelated administrative correction.
            throw new UnknownWalletAccountException('The target wallet account does not exist.');
        }

        return $account;
    }

    /**
     * @return list<PostLedgerEntryCommand>
     */
    private function buildEntries(
        SubmitAdministrativeAdjustmentCommand $command,
        WalletAccount $targetAccount,
        WalletAccount $suspenseAccount,
    ): array {
        [$targetEntryType, $suspenseEntryType] = $command->direction === AdministrativeAdjustmentDirection::Increase
            ? [LedgerEntryType::Credit, LedgerEntryType::Debit]
            : [LedgerEntryType::Debit, LedgerEntryType::Credit];

        return [
            new PostLedgerEntryCommand($targetAccount->id, $targetEntryType, $command->amount),
            new PostLedgerEntryCommand($suspenseAccount->id, $suspenseEntryType, $command->amount),
        ];
    }

    /**
     * wasReplay === false means the ledger transaction's own business
     * reference was genuinely new - its entity key (the transaction's own
     * freshly generated ULID) cannot have been known by any prior call, so
     * no audit event could legitimately pre-exist for it under any
     * circumstance. A unique-constraint collision here is therefore always
     * an unexplained anomaly, never accepted as success even if its
     * payload happens to match.
     */
    private function recordNewAudit(
        SubmitAdministrativeAdjustmentCommand $command,
        LedgerTransaction $transaction,
        WalletAccount $targetAccount,
    ): AuditEvent {
        try {
            return AuditEvent::record(
                actor: $command->actor,
                action: self::ACTION,
                entityType: self::ENTITY_TYPE,
                entityId: null,
                before: [],
                after: $this->safeAfterState($command, $transaction, $targetAccount),
                reason: $command->internalReason,
                correlationId: $transaction->correlation_id,
                entityKey: $transaction->id,
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictingAdministrativeAdjustmentAuditEventException(
                'An audit event already exists for a newly posted administrative adjustment.',
                previous: $exception,
            );
        }
    }

    /**
     * wasReplay === true means LedgerPostingEngine itself already
     * confirmed this is the identical financial event as an earlier,
     * fully committed call. The matching audit event must already exist;
     * a missing or mismatched row here is a genuine invariant failure,
     * never silently repaired or reinserted.
     */
    private function reconcileReplayedAudit(
        SubmitAdministrativeAdjustmentCommand $command,
        LedgerTransaction $transaction,
        WalletAccount $targetAccount,
    ): AuditEvent {
        $existing = AuditEvent::query()
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_key', $transaction->id)
            ->where('action', self::ACTION)
            ->first();

        if ($existing === null) {
            throw new MissingAdministrativeAdjustmentAuditEventException(
                'A replayed administrative adjustment has no corresponding audit event.',
            );
        }

        $expectedAfter = $this->safeAfterState($command, $transaction, $targetAccount);

        // Correlation is compared against the original committed ledger
        // transaction's own correlation_id, never the retry's fresh one -
        // $transaction here is already the original row, since
        // LedgerPostingEngine's own replay reconciliation returns it, not
        // a new one.
        if ($existing->actor_id !== $command->actor->id
            || $existing->reason !== $command->internalReason
            || $existing->correlation_id !== $transaction->correlation_id
            || ($existing->after_state['target_wallet_account_id'] ?? null) !== $expectedAfter['target_wallet_account_id']
            || ($existing->after_state['target_account_type'] ?? null) !== $expectedAfter['target_account_type']
            || ($existing->after_state['direction'] ?? null) !== $expectedAfter['direction']
            || ($existing->after_state['amount_atomic'] ?? null) !== $expectedAfter['amount_atomic']
            || ($existing->after_state['currency'] ?? null) !== $expectedAfter['currency']
            || ($existing->after_state['business_reference'] ?? null) !== $expectedAfter['business_reference']
        ) {
            throw new ConflictingAdministrativeAdjustmentAuditEventException(
                'An existing audit event does not match the replayed administrative adjustment.',
            );
        }

        return $existing;
    }

    /**
     * Every field here is an allowlisted, safe scalar - never a raw
     * request, raw model, email address, credential, or unrelated user
     * data.
     *
     * @return array<string, string>
     */
    private function safeAfterState(
        SubmitAdministrativeAdjustmentCommand $command,
        LedgerTransaction $transaction,
        WalletAccount $targetAccount,
    ): array {
        return [
            'target_wallet_account_id' => $targetAccount->id,
            'target_account_type' => $targetAccount->account_type->value,
            'direction' => $command->direction->value,
            'amount_atomic' => $command->amount->atomicString(),
            'currency' => $command->amount->currency()->value,
            'business_reference' => $transaction->business_reference,
        ];
    }
}
