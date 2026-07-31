<?php

declare(strict_types=1);

/**
 * Outermost automatic-restoration wrapper around the opt-in MySQL 8
 * concurrency suite - NOT itself part of the suite (never discovered by
 * phpunit.concurrency.xml). Invoked by composer's own "test:mysql-concurrency"
 * script instead of calling `pest` directly, so restoration is automatic on
 * every run, never a manual step between runs.
 *
 * Per-test afterEach cleanup (ScenarioCleanup) already handles the common
 * case - a test that runs to completion, pass or fail, always has its own
 * tearDown executed by PHPUnit/Pest. This wrapper exists for what
 * per-test cleanup structurally cannot reach: two categories of
 * non-namespaced shared state (RolePermissionSeeder's reference data,
 * used by Scenario H's actor; the platform_suspense wallet-account
 * singleton) that ScenarioCleanup deliberately never touches because
 * they are not owned by any single run's namespace, plus the rarer case
 * of a whole-process abort (an uncaught fatal error, an OOM) that skips
 * some later test's own tearDown entirely.
 *
 * Design: capture the exact ordered content of every concurrency-relevant
 * table before running pest as a genuine child process; in an outer
 * finally (which still runs even if the child process crashes, times out,
 * or pest itself reports failures - only runs if *this* wrapper process
 * itself is killed, which is what the separate recovery script under
 * this same directory exists for), delete only the rows whose identity
 * did not exist in the captured baseline, in FK-safe order, then
 * re-capture and assert exact equality against the original baseline.
 * Never a table-wide truncate; never a timestamp-range delete.
 */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Concurrency\Support\ConcurrencyDatabaseIdentityGuard;

// Running this specific script is itself the deliberate opt-in action
// (equivalent to phpunit.concurrency.xml's own <env> block, which only
// takes effect once Pest/PHPUnit itself boots - too late for this
// wrapper's own pre-flight guard check, since it runs before the pest
// child process even starts). Set before the app boots so it is already
// present by the time the guard reads it, and inherited by the pest
// child process below as defense-in-depth alongside its own config file.
putenv('RUN_MYSQL_CONCURRENCY_TESTS=1');
$_ENV['RUN_MYSQL_CONCURRENCY_TESTS'] = '1';

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$guardResult = ConcurrencyDatabaseIdentityGuard::verifyRuntimeIdentity($app);

if (! $guardResult->ok) {
    fwrite(STDERR, "Concurrency suite wrapper: identity guard failed ({$guardResult->reason}) - aborting before running anything.\n");
    exit(1);
}

$connection = DB::connection('mysql_concurrency');

/**
 * Table => the column(s) that uniquely identify a row. A single-element
 * list for tables with a real surrogate id; the full natural composite
 * key for Spatie's own pivot tables, which have no surrogate id at all.
 * Order here is the FK-safe deletion order for "new since baseline" rows -
 * the same order ScenarioCleanup uses per-run, generalized to the whole
 * suite.
 *
 * @var array<string, list<string>>
 */
$tableKeys = [
    'ledger_entries' => ['id'],
    'audit_events' => ['id'],
    'reversal_requests' => ['id'],
    'ledger_transactions' => ['id'],
    'balance_snapshots' => ['id'],
    'wallet_accounts' => ['id'],
    'model_has_roles' => ['role_id', 'model_type', 'model_id'],
    'users' => ['id'],
    'role_has_permissions' => ['permission_id', 'role_id'],
    'roles' => ['id'],
    'permissions' => ['id'],
];

/**
 * @param  array<string, list<string>>  $tableKeys
 * @return array<string, list<array<string, mixed>>>
 */
function captureOrderedSnapshot(ConnectionInterface $connection, array $tableKeys): array
{
    $snapshot = [];

    foreach ($tableKeys as $table => $keyColumns) {
        $query = $connection->table($table);

        foreach ($keyColumns as $column) {
            $query = $query->orderBy($column);
        }

        $snapshot[$table] = $query->get()->map(fn ($row): array => (array) $row)->all();
    }

    return $snapshot;
}

/**
 * @param  list<string>  $keyColumns
 */
function rowIdentity(array $row, array $keyColumns): string
{
    return implode('|', array_map(fn (string $column): string => (string) ($row[$column] ?? ''), $keyColumns));
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @param  list<string>  $keyColumns
 * @return array<string, true>
 */
function identitySet(array $rows, array $keyColumns): array
{
    $set = [];

    foreach ($rows as $row) {
        $set[rowIdentity($row, $keyColumns)] = true;
    }

    return $set;
}

$before = captureOrderedSnapshot($connection, $tableKeys);

echo 'Concurrency suite wrapper: baseline captured ('
    .implode(', ', array_map(fn (string $t) => "{$t}=".count($before[$t]), array_keys($tableKeys)))
    .').'.PHP_EOL;

$phpBinary = (new PhpExecutableFinder)->find();

if ($phpBinary === false) {
    fwrite(STDERR, "Concurrency suite wrapper: unable to locate the PHP executable.\n");
    exit(1);
}

$pestArgs = array_slice($_SERVER['argv'], 1);

// Explicit, not left to phpunit.concurrency.xml's own <env> block: PHPUnit's
// <env> directive only sets a variable that is not *already* present in the
// process environment (force="true" is not set on any of these tags) - and
// this wrapper's own process, unlike a direct `pest` invocation, already has
// a real environment inherited from .env (CACHE_STORE=database in
// particular) before pest ever starts, which would otherwise leak straight
// through to every worker. Confirmed as a genuine bug this way: Spatie's
// permission cache, backed by the real `database` store instead of the
// intended per-process `array` store, is shared across every worker in the
// suite via the `cache` table - a worker that reads it before this run's own
// freshly-seeded role/permission data is cached would see a stale snapshot
// missing that data, exactly the intermittent `UnauthorizedException`
// found during the Task 2.9 final audit's repeated-run verification.
$process = new Process(
    [$phpBinary, 'vendor/bin/pest', '-c', 'phpunit.concurrency.xml', ...$pestArgs],
    __DIR__.'/../../../',
    ['CACHE_STORE' => 'array', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'],
);
$process->setTimeout(null);

$exitCode = 1;

try {
    $process->run(function (string $type, string $buffer): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
    });
    $exitCode = $process->getExitCode() ?? 1;
} finally {
    $restoreOk = restoreToBaseline($connection, $tableKeys, $before);

    if (! $restoreOk) {
        // A restoration failure is reported regardless of pest's own
        // result - the suite is not genuinely "clean" if the database
        // did not return to its exact pre-run baseline, even if every
        // test itself passed.
        $exitCode = max($exitCode, 1);
    }
}

exit($exitCode);

/**
 * @param  array<string, list<string>>  $tableKeys
 * @param  array<string, list<array<string, mixed>>>  $before
 */
function restoreToBaseline(ConnectionInterface $connection, array $tableKeys, array $before): bool
{
    foreach ($tableKeys as $table => $keyColumns) {
        $beforeIds = identitySet($before[$table], $keyColumns);
        $currentRows = $connection->table($table)->get()->map(fn ($row): array => (array) $row)->all();

        $newRows = array_values(array_filter(
            $currentRows,
            fn (array $row): bool => ! isset($beforeIds[rowIdentity($row, $keyColumns)]),
        ));

        if ($newRows === []) {
            continue;
        }

        if ($table === 'ledger_transactions') {
            // Self-referential FK: reversal rows before original rows.
            $reversalRows = array_values(array_filter($newRows, fn (array $r): bool => $r['reverses_transaction_id'] !== null));
            $originalRows = array_values(array_filter($newRows, fn (array $r): bool => $r['reverses_transaction_id'] === null));
            deleteRowsByKey($connection, $table, $keyColumns, $reversalRows);
            deleteRowsByKey($connection, $table, $keyColumns, $originalRows);

            continue;
        }

        if ($table === 'wallet_accounts') {
            foreach ($newRows as $row) {
                if (! walletAccountRowIsSafeToDelete($connection, $row)) {
                    fwrite(STDERR, "Concurrency suite wrapper: refusing to delete an unverified new wallet_accounts row (id={$row['id']}) - leaving it in place for manual investigation.\n");

                    return false;
                }
            }
        }

        deleteRowsByKey($connection, $table, $keyColumns, $newRows);
    }

    $after = captureOrderedSnapshot($connection, $tableKeys);

    if ($after !== $before) {
        fwrite(STDERR, "Concurrency suite wrapper: restoration did not reach exact baseline equality - manual investigation required.\n");

        foreach (array_keys($tableKeys) as $table) {
            if ($after[$table] !== $before[$table]) {
                fwrite(STDERR, "  - {$table}: expected ".count($before[$table]).' rows, found '.count($after[$table]).' rows after restoration.'.PHP_EOL);
            }
        }

        return false;
    }

    echo 'Concurrency suite wrapper: restoration verified - exact baseline equality confirmed across all '.count($tableKeys).' tables.'.PHP_EOL;

    return true;
}

/**
 * Never deletes on trust alone: a newly-created wallet_accounts row is
 * only ever removed here after independently re-confirming it is exactly
 * the canonical, inert platform_suspense singleton - never an uncertain
 * or ambiguous row.
 *
 * @param  array<string, mixed>  $row
 */
function walletAccountRowIsSafeToDelete(ConnectionInterface $connection, array $row): bool
{
    if ($row['scope_type'] !== 'platform' || $row['scope_key'] !== 'ellipzo' || $row['account_type'] !== 'platform_suspense') {
        return false;
    }

    $referencingEntries = $connection->table('ledger_entries')->where('wallet_account_id', $row['id'])->count();

    return $referencingEntries === 0;
}

/**
 * @param  list<string>  $keyColumns
 * @param  list<array<string, mixed>>  $rows
 */
function deleteRowsByKey(ConnectionInterface $connection, string $table, array $keyColumns, array $rows): void
{
    foreach ($rows as $row) {
        $query = $connection->table($table);

        foreach ($keyColumns as $column) {
            $query = $query->where($column, $row[$column]);
        }

        $query->delete();
    }
}
