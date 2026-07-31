<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use RuntimeException;

/**
 * Maps the worker script's {scenario} argv slug to its handler. The single
 * point of truth for scenario slugs - both the worker script and every
 * coordinator-side scenario test reference these same string constants.
 */
final class ScenarioRegistry
{
    public const string WalletProvisioning = 'wallet-provisioning';

    public const string Overspend = 'overspend';

    public const string IdenticalFinancialEvent = 'identical-financial-event';

    public const string ConflictingFinancialEvent = 'conflicting-financial-event';

    public const string AccountOrder = 'account-order';

    public const string ReversalRequest = 'reversal-request';

    public const string ReversalExecution = 'reversal-execution';

    public const string AdjustmentReplay = 'adjustment-replay';

    public const string AdjustmentConflict = 'adjustment-conflict';

    public const string SnapshotVersusPosting = 'snapshot-vs-posting';

    public const string IndependentAccounts = 'independent-accounts';

    public const string WorkerTermination = 'worker-termination';

    public static function resolve(string $slug): ConcurrencyScenario
    {
        return match ($slug) {
            self::WalletProvisioning => new ConcurrentWalletProvisioningScenario,
            self::Overspend => new ConcurrentOverspendScenario,
            self::IdenticalFinancialEvent => new IdenticalFinancialEventRaceScenario,
            self::ConflictingFinancialEvent => new ConflictingFinancialEventRaceScenario,
            self::AccountOrder => new OppositeAccountOrderScenario,
            self::ReversalRequest => new ConcurrentReversalRequestScenario,
            self::ReversalExecution => new ConcurrentReversalExecutionScenario,
            self::AdjustmentReplay => new ConcurrentAdjustmentReplayScenario,
            self::AdjustmentConflict => new ConflictingAdjustmentScenario,
            self::SnapshotVersusPosting => new SnapshotVersusPostingScenario,
            self::IndependentAccounts => new IndependentAccountProgressScenario,
            self::WorkerTermination => new WorkerTerminationScenario,
            default => throw new RuntimeException("Unknown concurrency scenario: {$slug}"),
        };
    }
}
