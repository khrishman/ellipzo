<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Wallet\Data\UserWalletAccounts;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Provisions the four user-scoped wallet accounts for any existing user
 * who is missing them - the explicit, operator-run repair path for users
 * created before wallet provisioning was wired into registration/Google
 * completion. Dry-run by default; --apply is required to write anything.
 *
 * Dry-run does not run a second, simplified inspection of wallet-account
 * rules: it calls the exact same
 * WalletAccountProvisioner::provisionUserAccountsWithinTransaction() path
 * apply mode does, inside a transaction that is always rolled back
 * afterward, so a corrupt/conflicting account is detected identically in
 * both modes and never misclassified as "already complete."
 */
final class BackfillWalletAccountsCommand extends Command
{
    protected $signature = 'wallet:backfill-accounts
        {--apply : Actually provision missing wallet accounts. Without this flag, nothing is written.}';

    protected $description = 'Provision the four user-scoped wallet accounts for existing users who are missing them.';

    public function __construct(
        private readonly WalletAccountProvisioner $walletAccountProvisioner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $inspected = 0;
        $alreadyComplete = 0;
        $provisioned = 0;
        $failed = 0;

        foreach (User::query()->lazyById() as $user) {
            $inspected++;

            [$outcome, $created] = $apply
                ? $this->attemptApply($user)
                : $this->attemptDryRun($user);

            match ($outcome) {
                'failed' => $failed++,
                default => $created ? $provisioned++ : $alreadyComplete++,
            };
        }

        $this->newLine();
        $this->line('Mode: '.($apply ? 'apply' : 'dry-run'));
        $this->line("Inspected: {$inspected}");
        $this->line("Already complete: {$alreadyComplete}");
        $this->line(($apply ? 'Provisioned: ' : 'Would provision: ').$provisioned);
        $this->line("Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: 'ok'|'failed', 1: bool}
     */
    private function attemptApply(User $user): array
    {
        $created = false;

        try {
            DB::transaction(function () use ($user, &$created): void {
                $result = $this->walletAccountProvisioner->provisionUserAccountsWithinTransaction($user);
                $created = $this->anyAccountWasCreated($result);
            });
        } catch (Throwable) {
            // Deliberately not logged/rethrown with details here - safe
            // aggregate-only output is the whole point of this command.
            // No exception message, SQL, or stack trace ever reaches the
            // operator's terminal.
            return ['failed', false];
        }

        return ['ok', $created];
    }

    /**
     * Same canonical provisioning/validation call as attemptApply(), the
     * only difference being that the transaction it runs inside is always
     * rolled back afterward, whether the call succeeded or failed - so
     * dry-run mode never persists anything while still exercising the
     * exact same invariant checks apply mode does.
     *
     * @return array{0: 'ok'|'failed', 1: bool}
     */
    private function attemptDryRun(User $user): array
    {
        $created = false;
        $outcome = 'ok';

        DB::beginTransaction();

        try {
            $result = $this->walletAccountProvisioner->provisionUserAccountsWithinTransaction($user);
            $created = $this->anyAccountWasCreated($result);
        } catch (Throwable) {
            $outcome = 'failed';
        } finally {
            DB::rollBack();
        }

        return [$outcome, $created];
    }

    private function anyAccountWasCreated(UserWalletAccounts $accounts): bool
    {
        return $accounts->earningAvailable->wasRecentlyCreated
            || $accounts->earningHeld->wasRecentlyCreated
            || $accounts->advertisingAvailable->wasRecentlyCreated
            || $accounts->advertisingReserved->wasRecentlyCreated;
    }
}
