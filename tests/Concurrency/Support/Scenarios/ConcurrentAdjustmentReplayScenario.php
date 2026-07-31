<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\SubmitAdministrativeAdjustmentCommand;
use App\Domain\Wallet\Enums\AdministrativeAdjustmentDirection;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Services\AdministrativeAdjustmentService;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;

/**
 * Scenario H - concurrent identical administrative-adjustment replay. Both
 * workers submit the exact same adjustment (same idempotency key, same
 * amount/direction/target) concurrently. Contests the same
 * business_reference unique-index race as scenarios C/D, but through
 * AdministrativeAdjustmentService's own wrapper - proving the audit-event
 * side stays exactly-once too: the loser's writeAdministrativeAdjustment
 * WithinTransaction() call throws before AdministrativeAdjustmentService::
 * submit() ever reaches its own audit-recording branch, so no audit event
 * is ever attempted for the loser. platform_suspense is lazily provisioned
 * by both workers via WalletAccountProvisioner::resolveAccount()'s own
 * insert-catch-refetch path (the same safe mechanism scenario A proves
 * directly) - never a second point of contention here.
 */
final class ConcurrentAdjustmentReplayScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        $actor = User::query()->findOrFail((int) $payload['actorId']);
        $targetUser = User::query()->findOrFail((int) $payload['targetUserId']);

        $service = new AdministrativeAdjustmentService(new LedgerPostingEngine, new WalletAccountProvisioner);

        $result = $service->submit(new SubmitAdministrativeAdjustmentCommand(
            $actor,
            $targetUser,
            WalletAccountType::from((string) $payload['targetAccountType']),
            AdministrativeAdjustmentDirection::from((string) $payload['direction']),
            Money::fromAtomic((int) $payload['amountAtomic'], Currency::USD),
            (string) $payload['internalReason'],
            null,
            (string) $payload['idempotencyKey'],
            (string) Str::ulid(),
        ));

        return [
            'outcome' => $result->wasReplay ? ConcurrencyOutcomeCategory::Replay : ConcurrencyOutcomeCategory::Created,
            'committedTransactionId' => $result->transaction->id,
            'extra' => ['auditEventId' => $result->auditEvent->id],
        ];
    }
}
