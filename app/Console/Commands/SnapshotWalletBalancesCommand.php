<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\BalanceSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Captures a new append-only BalanceSnapshot for one or every wallet
 * account. Never mutates ledger_transactions, ledger_entries,
 * wallet_accounts, reversal_requests, audit_events, or users - the only
 * table this command ever writes to is balance_snapshots, and only
 * through BalanceSnapshotService.
 */
final class SnapshotWalletBalancesCommand extends Command
{
    protected $signature = 'wallet:snapshot
        {--account= : Snapshot only this wallet account (ULID). Without this flag, every wallet account is snapshotted.}';

    protected $description = 'Capture a new append-only balance snapshot for one or every wallet account.';

    public function __construct(
        private readonly BalanceSnapshotService $balanceSnapshotService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $accountId = $this->option('account');

        if ($accountId !== null) {
            return $this->handleSingleAccount($accountId);
        }

        return $this->handleAllAccounts();
    }

    private function handleSingleAccount(string $accountId): int
    {
        $account = $this->resolveAccount($accountId);

        if ($account === null) {
            $this->newLine();
            $this->line('Mode: single');
            $this->line('Inspected: 0');
            $this->line('Snapshotted: 0');
            $this->line('Failed: 1');

            return self::FAILURE;
        }

        $failed = 0;
        try {
            $this->balanceSnapshotService->captureForAccount($account);
        } catch (Throwable) {
            // Deliberately not logged/rethrown with details - safe
            // aggregate-only output is the whole point of this command.
            $failed = 1;
        }

        $this->newLine();
        $this->line('Mode: single');
        $this->line('Inspected: 1');
        $this->line('Snapshotted: '.(1 - $failed));
        $this->line("Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function handleAllAccounts(): int
    {
        $inspected = 0;
        $snapshotted = 0;
        $failed = 0;

        foreach (WalletAccount::query()->lazyById() as $account) {
            $inspected++;

            try {
                $this->balanceSnapshotService->captureForAccount($account);
                $snapshotted++;
            } catch (Throwable) {
                $failed++;
            }
        }

        $this->newLine();
        $this->line('Mode: all');
        $this->line("Inspected: {$inspected}");
        $this->line("Snapshotted: {$snapshotted}");
        $this->line("Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A uniform, controlled failure for both a malformed value and a
     * syntactically valid ULID with no matching row - the caller never
     * learns which case it was, matching this codebase's existing
     * ownership-leak-prevention discipline.
     */
    private function resolveAccount(string $accountId): ?WalletAccount
    {
        $trimmed = trim($accountId);

        if (! Str::isUlid($trimmed)) {
            return null;
        }

        return WalletAccount::query()->whereKey(strtolower($trimmed))->first();
    }
}
