<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\Concerns;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Data\UserWalletAccounts;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\RelatedEntityType;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Shared fixture builders for LedgerPostingEngine tests, mirroring the
 * shared-trait pattern InsertsRawLedgerRowsForTesting already established
 * for Task 2.3's own schema tests.
 */
trait BuildsLedgerPostingFixtures
{
    protected function provisionTestAccounts(): UserWalletAccounts
    {
        $user = User::factory()->create();

        return (new WalletAccountProvisioner)->provisionUserAccounts($user);
    }

    protected function debitEntry(string $walletAccountId, int $amount = 1_000_000): PostLedgerEntryCommand
    {
        return new PostLedgerEntryCommand($walletAccountId, LedgerEntryType::Debit, Money::fromAtomic($amount, Currency::USD));
    }

    protected function creditEntry(string $walletAccountId, int $amount = 1_000_000): PostLedgerEntryCommand
    {
        return new PostLedgerEntryCommand($walletAccountId, LedgerEntryType::Credit, Money::fromAtomic($amount, Currency::USD));
    }

    /**
     * @param  list<PostLedgerEntryCommand>|null  $entries
     */
    protected function postingCommand(
        LedgerTransactionType $type = LedgerTransactionType::DepositCredit,
        ?string $businessReference = null,
        ?string $correlationId = null,
        string $description = 'Test posting',
        ?User $actor = null,
        ?RelatedEntityType $relatedEntityType = null,
        ?string $relatedEntityId = null,
        ?array $entries = null,
    ): PostLedgerTransactionCommand {
        return new PostLedgerTransactionCommand(
            $type,
            $businessReference ?? $type->value.':'.strtolower((string) Str::ulid()),
            $correlationId ?? (string) Str::uuid(),
            $description,
            $actor,
            $relatedEntityType,
            $relatedEntityId,
            $entries ?? [],
        );
    }
}
