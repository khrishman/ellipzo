<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * FK-safe, run-namespace-scoped teardown against `ellipzo_concurrency_test`
 * only. Every FK in this schema is restrictOnDelete() (confirmed by reading
 * every migration - none are cascadeOnDelete()), so deletion order matters:
 *
 *   ledger_entries
 *   -> reversal_requests                                     (references ledger_transactions twice)
 *   -> ledger_transactions WHERE reverses_transaction_id IS NOT NULL   (self-referential FK: reversal rows first)
 *   -> ledger_transactions WHERE reverses_transaction_id IS NULL       (original rows)
 *   -> balance_snapshots, audit_events                        (either order - both depend only on wallet_accounts/users)
 *   -> wallet_accounts
 *   -> users
 *
 * Never a timestamp-range or table-wide delete - every row is individually
 * verified to belong to the current run's namespace (via
 * ConcurrencyRunNamespace::owns()) before it is deleted. If ownership
 * cannot be proven for some row that otherwise looks related, cleanup stops
 * and reports the remaining rows rather than guessing.
 */
final class ScenarioCleanup
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ConcurrencyRunNamespace $namespace,
    ) {}

    /**
     * @return list<string> human-readable descriptions of any row left
     *                      behind because ownership could not be proven -
     *                      empty means cleanup fully succeeded.
     */
    public function run(): array
    {
        // Captured once, up front - audit_events and reversal_requests are
        // both traced back to their owning ledger_transactions row by ID,
        // which must be known before that row (and therefore this trail)
        // is deleted.
        $ledgerTransactionIds = $this->ownedLedgerTransactionIds();
        $userIds = $this->ownedUserIds();
        $walletAccountIds = $this->ownedWalletAccountIds($userIds);

        if ($ledgerTransactionIds !== []) {
            $this->connection->table('ledger_entries')
                ->whereIn('ledger_transaction_id', $ledgerTransactionIds)
                ->delete();

            $this->connection->table('audit_events')
                ->where('entity_type', 'ledger_transaction')
                ->whereIn('entity_key', $ledgerTransactionIds)
                ->delete();

            $this->connection->table('reversal_requests')
                ->where(function ($query) use ($ledgerTransactionIds): void {
                    $query->whereIn('original_ledger_transaction_id', $ledgerTransactionIds)
                        ->orWhereIn('reversal_transaction_id', $ledgerTransactionIds);
                })
                ->delete();

            // Reversal rows (self-referential FK target) before original rows.
            $this->connection->table('ledger_transactions')
                ->whereIn('id', $ledgerTransactionIds)
                ->whereNotNull('reverses_transaction_id')
                ->delete();

            $this->connection->table('ledger_transactions')
                ->whereIn('id', $ledgerTransactionIds)
                ->delete();
        }

        $this->deleteOwnedByColumn('balance_snapshots', 'wallet_account_id', $walletAccountIds);

        if ($walletAccountIds !== []) {
            $this->connection->table('wallet_accounts')->whereIn('id', $walletAccountIds)->delete();
        }

        if ($userIds !== []) {
            // Spatie's model_has_roles is polymorphic (model_type +
            // model_id) and therefore has no real foreign key into users -
            // deleting a user here would otherwise leave an orphaned role
            // assignment behind silently, with nothing to ever complain
            // about it.
            $this->connection->table('model_has_roles')
                ->where('model_type', User::class)
                ->whereIn('model_id', $userIds)
                ->delete();

            $this->connection->table('users')->whereIn('id', $userIds)->delete();
        }

        return $this->findRemainingOwnedRows();
    }

    /**
     * A reversal transaction's own business_reference is always
     * "reversal:{originalId}" (LedgerPostingEngine's own fixed shape) -
     * it never carries this run's namespace string itself, only its
     * *original* transaction's ID does. Ownership therefore has to expand
     * transitively: any transaction whose reverses_transaction_id points
     * at an already-owned transaction is owned too, or a reversal of an
     * owned transaction would be left behind (and its self-referential FK
     * to that original would then block deleting the original at all).
     *
     * @return list<string>
     */
    private function ownedLedgerTransactionIds(): array
    {
        $rows = $this->connection->table('ledger_transactions')
            ->select('id', 'business_reference', 'reverses_transaction_id')
            ->get();

        $owned = $rows
            ->filter(fn ($row) => $this->namespace->owns($row->business_reference))
            ->pluck('id')
            ->all();

        $reversalOfOwned = $rows
            ->filter(fn ($row) => $row->reverses_transaction_id !== null && in_array($row->reverses_transaction_id, $owned, true))
            ->pluck('id')
            ->all();

        return array_values(array_unique([...$owned, ...$reversalOfOwned]));
    }

    /**
     * @param  list<int>  $ownedUserIds
     * @return list<string>
     */
    private function ownedWalletAccountIds(array $ownedUserIds): array
    {
        return $this->connection->table('wallet_accounts')
            ->select('id', 'scope_key', 'user_id')
            ->get()
            ->filter(fn ($row) => $this->namespace->owns($row->scope_key) || in_array($row->user_id, $ownedUserIds, true))
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<int>
     */
    private function ownedUserIds(): array
    {
        return $this->connection->table('users')
            ->select('id', 'name')
            ->get()
            ->filter(fn ($row) => $this->namespace->owns($row->name))
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<string>  $ownerIds
     */
    private function deleteOwnedByColumn(string $table, string $ownerColumn, array $ownerIds): void
    {
        if ($ownerIds === []) {
            return;
        }

        $this->connection->table($table)->whereIn($ownerColumn, $ownerIds)->delete();
    }

    /**
     * @return list<string>
     */
    private function findRemainingOwnedRows(): array
    {
        $found = [];

        foreach (['wallet_accounts' => 'scope_key', 'users' => 'name'] as $table => $column) {
            $rows = $this->connection->table($table)->pluck($column, 'id');

            foreach ($rows as $id => $value) {
                if ($this->namespace->owns((string) $value)) {
                    $found[] = "{$table}#{$id}";
                }
            }
        }

        // ledger_transactions checked via the same transitive rule as
        // ownedLedgerTransactionIds() - a leftover reversal row's own
        // business_reference never carries this run's namespace, only the
        // original transaction it points at does.
        foreach ($this->ownedLedgerTransactionIds() as $id) {
            $found[] = "ledger_transactions#{$id}";
        }

        return $found;
    }

    public static function assertClean(ConnectionInterface $connection, ConcurrencyRunNamespace $namespace): void
    {
        $cleanup = new self($connection, $namespace);
        $remaining = $cleanup->findRemainingOwnedRows();

        if ($remaining !== []) {
            throw new RuntimeException('Scenario rows remained after cleanup: '.implode(', ', $remaining));
        }
    }
}
