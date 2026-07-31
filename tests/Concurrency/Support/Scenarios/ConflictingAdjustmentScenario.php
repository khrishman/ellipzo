<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\SubmitAdministrativeAdjustmentCommand;
use App\Domain\Wallet\Enums\AdministrativeAdjustmentDirection;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\DuplicateFinancialEventException;
use App\Domain\Wallet\Services\AdministrativeAdjustmentService;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;

/**
 * Scenario H, conflicting-payload sub-case - NOT the dropped Scenario I.
 * These are two genuinely different conflicts and must not be conflated:
 *
 * 1. (This class.) Two commands share one stable, caller-controlled
 *    identity - SubmitAdministrativeAdjustmentCommand::businessReference()
 *    ("administrative_adjustment:" + the caller-supplied idempotency key) -
 *    but carry a genuinely different semantic payload (different amount;
 *    not direction, since a "decrease" against an unfunded account fails
 *    balance validation for an unrelated reason before the contested
 *    business_reference is ever reached, confounding the proof). This is
 *    exactly the same business_reference unique-index race scenarios C/D
 *    and ConcurrentAdjustmentReplayScenario (the identical-payload
 *    sub-case) already contest, reached through
 *    AdministrativeAdjustmentService's own wrapper instead. Fully
 *    constructible and genuinely raced by two independent workers - both
 *    can predict and race for the same idempotency key, since the caller
 *    supplies it. The loser's writeAdministrativeAdjustmentWithinTransaction()
 *    call throws DuplicateFinancialEventException before
 *    AdministrativeAdjustmentService::submit() ever reaches audit-event
 *    code - no audit event, no orphan transaction/entries, is ever
 *    created for it. The winner is not predetermined.
 *
 * 2. (Not implemented, correctly dropped - see the plan's own §9.) The
 *    originally-envisioned "Scenario I" required one worker to pre-insert a
 *    raw audit_events row that collides with a *second*, independently
 *    generated LedgerTransaction ULID it could not yet know - the only
 *    existing test that reaches ConflictingAdministrativeAdjustmentAuditEventException
 *    (AdministrativeAdjustmentServiceTest.php) does so by forcibly rigging
 *    that ULID via a model event, which no independent concurrent process
 *    could ever do to another process's about-to-be-generated ID. That
 *    artificial ULID-prediction race is genuinely impossible to construct
 *    honestly and remains dropped; nothing in this file resurrects it. This
 *    class's own meaningful financial conflict (same identity, different
 *    payload, under real concurrency) is what Scenario I's *intent* was
 *    actually trying to protect, and it is fully covered here as part of
 *    Scenario H instead.
 */
final class ConflictingAdjustmentScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        $actor = User::query()->findOrFail((int) $payload['actorId']);
        $targetUser = User::query()->findOrFail((int) $payload['targetUserId']);

        $service = new AdministrativeAdjustmentService(new LedgerPostingEngine, new WalletAccountProvisioner);

        try {
            $result = $service->submit(new SubmitAdministrativeAdjustmentCommand(
                $actor,
                $targetUser,
                WalletAccountType::from((string) $payload['targetAccountType']),
                AdministrativeAdjustmentDirection::Increase,
                Money::fromAtomic((int) $payload["amountAtomic_{$role}"], Currency::USD),
                (string) $payload["internalReason_{$role}"],
                null,
                (string) $payload['idempotencyKey'],
                (string) Str::ulid(),
            ));

            return [
                'outcome' => ConcurrencyOutcomeCategory::Created,
                'committedTransactionId' => $result->transaction->id,
                'extra' => ['auditEventId' => $result->auditEvent->id],
            ];
        } catch (DuplicateFinancialEventException) {
            return [
                'outcome' => ConcurrencyOutcomeCategory::DuplicateEvent,
                'committedTransactionId' => null,
            ];
        }
    }
}
