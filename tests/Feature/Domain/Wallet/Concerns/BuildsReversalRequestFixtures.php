<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\Concerns;

use App\Domain\Wallet\Data\RequestLedgerReversalCommand;
use App\Domain\Wallet\Data\UserWalletAccounts;
use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\ReversalRequest;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\ReversalRequestWriteContext;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Shared fixture builder for ReversalRequestService tests, mirroring
 * BuildsLedgerPostingFixtures' own trait pattern.
 */
trait BuildsReversalRequestFixtures
{
    protected function reversalCommand(
        string $originalTransactionId,
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
        string $reason = 'Test reversal reason',
        ?User $actor = null,
    ): RequestLedgerReversalCommand {
        return new RequestLedgerReversalCommand(
            $originalTransactionId,
            $idempotencyKey ?? 'reversal-request:'.strtolower((string) Str::ulid()),
            $correlationId ?? (string) Str::uuid(),
            $reason,
            $actor,
        );
    }

    /**
     * Builds a legitimately persisted Pending ReversalRequest directly
     * through the model (inside ReversalRequestWriteContext), bypassing
     * ReversalRequestService itself - used by model-layer tests that need
     * a valid starting row without exercising the full service.
     */
    protected function createPendingReversalRequest(?string $originalLedgerTransactionId = null): ReversalRequest
    {
        return ReversalRequestWriteContext::run(function () use ($originalLedgerTransactionId): ReversalRequest {
            $request = new ReversalRequest;
            $request->original_ledger_transaction_id = $originalLedgerTransactionId ?? $this->insertRawLedgerTransaction();
            $request->idempotency_key = 'idem:'.strtolower((string) Str::ulid());
            $request->fingerprint = str_repeat('a', 64);
            $request->status = ReversalRequestStatus::Pending;
            $request->actor_id = null;
            $request->reason = 'Test reason';
            $request->correlation_id = (string) Str::uuid();
            $request->save();

            return $request;
        });
    }

    /**
     * A committed, balanced, two-entry deposit_credit transaction between
     * a provider clearing account and the user's earning_available
     * account - a minimal, always-eligible original for reversal tests.
     */
    protected function makeReversibleOriginal(
        LedgerPostingEngine $engine,
        UserWalletAccounts $accounts,
        WalletAccount $clearing,
        string $businessReference,
        int $amount = 500,
    ): LedgerTransaction {
        return $engine->post($this->postingCommand(businessReference: $businessReference, entries: [
            $this->debitEntry($clearing->id, $amount),
            $this->creditEntry($accounts->earningAvailable->id, $amount),
        ]))->transaction;
    }
}
