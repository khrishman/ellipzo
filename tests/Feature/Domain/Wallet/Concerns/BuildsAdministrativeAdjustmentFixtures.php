<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\Concerns;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\SubmitAdministrativeAdjustmentCommand;
use App\Domain\Wallet\Enums\AdministrativeAdjustmentDirection;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;

/**
 * Shared fixture builders for AdministrativeAdjustmentService tests,
 * mirroring BuildsLedgerPostingFixtures/BuildsReversalRequestFixtures'
 * own trait pattern. Relies on BuildsLedgerPostingFixtures' own
 * postingCommand()/debitEntry()/creditEntry() helpers, combined via the
 * same tests/Pest.php Feature/Domain/Wallet binding.
 */
trait BuildsAdministrativeAdjustmentFixtures
{
    /**
     * A real, seeded staff user holding ledger.adjust via the
     * finance-operator role - never a fake permission grant.
     */
    protected function ledgerAdjustActor(): User
    {
        (new RolePermissionSeeder)->run();

        $actor = User::factory()->create();
        $actor->assignRole('finance-operator');

        return $actor;
    }

    /**
     * A real, seeded staff user with an admin role that deliberately
     * lacks ledger.adjust, for permission-denial tests.
     */
    protected function nonLedgerAdjustActor(): User
    {
        (new RolePermissionSeeder)->run();

        $actor = User::factory()->create();
        $actor->assignRole('moderator');

        return $actor;
    }

    /**
     * Gives an account a real, legitimately posted balance through
     * LedgerPostingEngine - never a direct balance write - funded from a
     * synthetic provider-clearing account, mirroring
     * LedgerPostingEngineTest.php's own established fixture-funding
     * pattern.
     */
    protected function fundAccount(WalletAccount $account, int $amountAtomic): void
    {
        $clearing = (new WalletAccountProvisioner)->providerClearingAccount('fixture-funding-'.strtolower((string) Str::ulid()));

        (new LedgerPostingEngine)->post($this->postingCommand(entries: [
            $this->debitEntry($clearing->id, $amountAtomic),
            $this->creditEntry($account->id, $amountAtomic),
        ]));
    }

    protected function adjustmentCommand(
        User $actor,
        User $targetUser,
        WalletAccountType $targetAccountType = WalletAccountType::EarningAvailable,
        AdministrativeAdjustmentDirection $direction = AdministrativeAdjustmentDirection::Increase,
        int $amountAtomic = 10_000_000,
        ?string $internalReason = null,
        ?string $userVisibleDescription = null,
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
    ): SubmitAdministrativeAdjustmentCommand {
        return new SubmitAdministrativeAdjustmentCommand(
            $actor,
            $targetUser,
            $targetAccountType,
            $direction,
            Money::fromAtomic($amountAtomic, Currency::USD),
            $internalReason ?? 'Correcting a support-verified reward calculation error.',
            $userVisibleDescription,
            $idempotencyKey ?? strtolower((string) Str::ulid()),
            $correlationId ?? (string) Str::uuid(),
        );
    }
}
