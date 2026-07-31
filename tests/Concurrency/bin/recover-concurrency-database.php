<?php

declare(strict_types=1);

/**
 * Manual, last-resort recovery tool - NOT part of the automatic flow and
 * NOT invoked by any composer script. The normal successful suite never
 * needs this: per-test afterEach (ScenarioCleanup) handles an ordinary
 * test failure or assertion failure (tearDown always runs, pass or fail),
 * and run-concurrency-suite.php's own outer finally handles the pest
 * child process crashing, timing out, or exiting early (the finally block
 * runs in the *wrapper's own* process, independent of how the child
 * exited). This script exists for the one case neither of those can
 * reach: the wrapper process itself being killed (SIGKILL, machine crash,
 * `kill -9`) before its own finally block can execute - at which point no
 * in-process mechanism anywhere can run, and only a separate, later,
 * manually-invoked pass over the database can find and remove whatever
 * was left behind.
 *
 * Ownership here cannot use ConcurrencyRunNamespace::owns() (that requires
 * one specific already-known run ID) since a hard crash gives no
 * opportunity to recover which run IDs were in flight. Instead, every
 * fixture this test harness ever creates carries a fixed, harness-wide
 * marker baked into ConcurrencyRunNamespace itself: a username is always
 * exactly "cc" + a 26-character lowercase ULID, and every business
 * reference/idempotency key/provider scope key always contains the
 * literal substring "cc-" (from slug(): "cc-{scenario}-{runId}"). Neither
 * pattern can ever match this database's two legitimate non-test rows -
 * the platform_suspense singleton's scope_key is the fixed literal
 * "ellipzo", and RolePermissionSeeder's role/permission names
 * (administrator, moderator, finance-operator, support-agent,
 * ledger.view, ...) never contain "cc-" - so both are correctly preserved
 * without any special-casing.
 *
 * Usage: php tests/Concurrency/bin/recover-concurrency-database.php [--confirm]
 * Without --confirm, prints a dry-run summary only and deletes nothing.
 */
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ConcurrencyDatabaseIdentityGuard;

putenv('RUN_MYSQL_CONCURRENCY_TESTS=1');
$_ENV['RUN_MYSQL_CONCURRENCY_TESTS'] = '1';

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$guardResult = ConcurrencyDatabaseIdentityGuard::verifyRuntimeIdentity($app);

if (! $guardResult->ok) {
    fwrite(STDERR, "Recovery tool: identity guard failed ({$guardResult->reason}) - refusing to touch any database.\n");
    exit(1);
}

$confirm = in_array('--confirm', array_slice($_SERVER['argv'], 1), true);
$connection = DB::connection('mysql_concurrency');

const USERNAME_PATTERN = '/^cc[0-9a-z]{26}$/';
const SLUG_MARKER = 'cc-';

/**
 * @return list<int>
 */
function ownedUserIds(ConnectionInterface $connection): array
{
    return $connection->table('users')
        ->select('id', 'name')
        ->get()
        ->filter(fn ($row): bool => is_string($row->name) && preg_match(USERNAME_PATTERN, $row->name) === 1)
        ->pluck('id')
        ->all();
}

/**
 * @param  list<int>  $ownedUserIds
 * @return list<string>
 */
function ownedWalletAccountIds(ConnectionInterface $connection, array $ownedUserIds): array
{
    return $connection->table('wallet_accounts')
        ->select('id', 'scope_key', 'user_id')
        ->get()
        ->filter(fn ($row): bool => str_contains((string) $row->scope_key, SLUG_MARKER) || in_array($row->user_id, $ownedUserIds, true))
        ->pluck('id')
        ->all();
}

/**
 * Same transitive rule ScenarioCleanup uses: a reversal transaction's own
 * business_reference is always "reversal:{originalId}" and never itself
 * carries the "cc-" marker - only its *original* transaction's reference
 * does. A reversal of an owned transaction is owned too.
 *
 * @return list<string>
 */
function ownedLedgerTransactionIds(ConnectionInterface $connection): array
{
    $rows = $connection->table('ledger_transactions')
        ->select('id', 'business_reference', 'reverses_transaction_id')
        ->get();

    $owned = $rows
        ->filter(fn ($row): bool => str_contains((string) $row->business_reference, SLUG_MARKER))
        ->pluck('id')
        ->all();

    $reversalOfOwned = $rows
        ->filter(fn ($row): bool => $row->reverses_transaction_id !== null && in_array($row->reverses_transaction_id, $owned, true))
        ->pluck('id')
        ->all();

    return array_values(array_unique([...$owned, ...$reversalOfOwned]));
}

$ownedUserIds = ownedUserIds($connection);
$ownedWalletAccountIds = ownedWalletAccountIds($connection, $ownedUserIds);
$ownedLedgerTransactionIds = ownedLedgerTransactionIds($connection);

$plan = [
    'ledger_entries' => $connection->table('ledger_entries')->whereIn('ledger_transaction_id', $ownedLedgerTransactionIds)->count(),
    'audit_events' => $connection->table('audit_events')->where('entity_type', 'ledger_transaction')->whereIn('entity_key', $ownedLedgerTransactionIds)->count(),
    'reversal_requests' => $connection->table('reversal_requests')->where(function ($q) use ($ownedLedgerTransactionIds): void {
        $q->whereIn('original_ledger_transaction_id', $ownedLedgerTransactionIds)->orWhereIn('reversal_transaction_id', $ownedLedgerTransactionIds);
    })->count(),
    'ledger_transactions' => count($ownedLedgerTransactionIds),
    'balance_snapshots' => $connection->table('balance_snapshots')->whereIn('wallet_account_id', $ownedWalletAccountIds)->count(),
    'wallet_accounts' => count($ownedWalletAccountIds),
    'model_has_roles' => $connection->table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $ownedUserIds)->count(),
    'users' => count($ownedUserIds),
];

echo 'Recovery tool: identified owned rows ('.implode(', ', array_map(fn ($t, $n) => "{$t}={$n}", array_keys($plan), $plan)).').'.PHP_EOL;
echo 'Recovery tool: role/permission reference data and the platform_suspense singleton are never touched by this tool - they are legitimate shared infrastructure, not per-run fixtures.'.PHP_EOL;

if (! $confirm) {
    echo 'Recovery tool: dry run only (pass --confirm to actually delete). Nothing was changed.'.PHP_EOL;
    exit(0);
}

if (array_sum($plan) === 0) {
    echo 'Recovery tool: nothing to do - no owned rows found.'.PHP_EOL;
    exit(0);
}

$connection->table('ledger_entries')->whereIn('ledger_transaction_id', $ownedLedgerTransactionIds)->delete();
$connection->table('audit_events')->where('entity_type', 'ledger_transaction')->whereIn('entity_key', $ownedLedgerTransactionIds)->delete();
$connection->table('reversal_requests')->where(function ($q) use ($ownedLedgerTransactionIds): void {
    $q->whereIn('original_ledger_transaction_id', $ownedLedgerTransactionIds)->orWhereIn('reversal_transaction_id', $ownedLedgerTransactionIds);
})->delete();
$connection->table('ledger_transactions')->whereIn('id', $ownedLedgerTransactionIds)->whereNotNull('reverses_transaction_id')->delete();
$connection->table('ledger_transactions')->whereIn('id', $ownedLedgerTransactionIds)->delete();
$connection->table('balance_snapshots')->whereIn('wallet_account_id', $ownedWalletAccountIds)->delete();
$connection->table('wallet_accounts')->whereIn('id', $ownedWalletAccountIds)->delete();
$connection->table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $ownedUserIds)->delete();
$connection->table('users')->whereIn('id', $ownedUserIds)->delete();

$remainingUsers = ownedUserIds($connection);
$remainingAccounts = ownedWalletAccountIds($connection, $remainingUsers);
$remainingTransactions = ownedLedgerTransactionIds($connection);

if ($remainingUsers !== [] || $remainingAccounts !== [] || $remainingTransactions !== []) {
    fwrite(STDERR, 'Recovery tool: rows remained after deletion (FK-blocked or newly discovered) - re-run this tool again, or investigate manually.'.PHP_EOL);
    exit(1);
}

echo 'Recovery tool: all identified owned rows removed. Reference data and platform_suspense left untouched.'.PHP_EOL;
