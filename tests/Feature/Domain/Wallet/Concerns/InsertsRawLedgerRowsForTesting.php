<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Raw, test-only row insertion for ledger_transactions/ledger_entries.
 * Nothing in the application constructs these models yet - Task 2.4's
 * posting engine is the only intended future write path - so schema and
 * model-cast tests reach the database directly here rather than through
 * any production code, keeping that boundary honest.
 */
trait InsertsRawLedgerRowsForTesting
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function insertRawWalletAccount(array $overrides = []): string
    {
        $id = $overrides['id'] ?? (string) Str::ulid();

        DB::table('wallet_accounts')->insert(array_merge([
            'id' => $id,
            'scope_type' => 'platform',
            'scope_key' => 'ellipzo',
            'user_id' => null,
            'account_type' => 'platform_fee',
            'currency_code' => 'USD',
            'currency_scale' => 6,
            'created_at' => now(),
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function insertRawLedgerTransaction(array $overrides = []): string
    {
        $id = $overrides['id'] ?? (string) Str::ulid();

        DB::table('ledger_transactions')->insert(array_merge([
            'id' => $id,
            'business_reference' => 'test-ref-'.Str::random(16),
            'type' => 'deposit_credit',
            'currency_code' => 'USD',
            'currency_scale' => 6,
            'description' => 'Test transaction',
            'actor_id' => null,
            'related_entity_type' => null,
            'related_entity_id' => null,
            'correlation_id' => (string) Str::uuid(),
            'reverses_transaction_id' => null,
            'created_at' => now(),
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function insertRawLedgerEntry(array $overrides = []): string
    {
        $id = $overrides['id'] ?? (string) Str::ulid();
        $ledgerTransactionId = $overrides['ledger_transaction_id'] ?? $this->insertRawLedgerTransaction();
        $walletAccountId = $overrides['wallet_account_id'] ?? $this->insertRawWalletAccount();

        DB::table('ledger_entries')->insert(array_merge([
            'id' => $id,
            'ledger_transaction_id' => $ledgerTransactionId,
            'wallet_account_id' => $walletAccountId,
            'entry_type' => 'credit',
            'amount_atomic' => 1000,
            'created_at' => now(),
        ], $overrides));

        return $id;
    }
}
