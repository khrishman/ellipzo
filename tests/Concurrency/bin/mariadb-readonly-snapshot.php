<?php

declare(strict_types=1);

/**
 * Task 2.9 support tooling - NOT part of the concurrency test suite itself.
 *
 * Deliberately never uses `php artisan tinker`: an earlier session in this
 * project accidentally wrote fixture data into the real `ellipzo_app`
 * MariaDB database via a bare tinker call made for an unrelated diagnostic
 * purpose (tinker boots the app against the real, active .env - not any
 * test-scoped database). This script exists specifically to make that
 * mistake structurally impossible to repeat: it issues only `SELECT
 * COUNT(*)`-shaped read queries against the `mysql` connection (the real
 * MariaDB `ellipzo_app` database) and nothing else - no `->save(`,
 * `->create(`, `->insert(`, `->update(`, `->delete(`, `DB::statement(`, or
 * any other write call appears anywhere in this file. It is run manually,
 * before and after the concurrency suite, never as part of the automated
 * Pest run itself.
 *
 * Usage: php tests/Concurrency/bin/mariadb-readonly-snapshot.php
 * Output: one JSON object on stdout, no other output.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
// The Kernel import above is deliberately kept before this line, not
// after the require statements - see the identical, fuller comment in
// concurrency-worker.php for why (PHP resolves an unqualified class name
// against whichever `use` imports are textually in effect at that exact
// point in the file; imports are never file-wide hoisted).
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection('mysql');

$databaseName = $connection->getDatabaseName();
$expectedDatabaseName = config('database.connections.mysql.database');

if ($databaseName !== $expectedDatabaseName) {
    fwrite(STDERR, "abort: resolved database name does not match configured connection database\n");
    exit(1);
}

$migrationCount = (int) $connection->table('migrations')->count();

$userCount = (int) $connection->table('users')->count();

$walletAccountCount = (int) $connection->table('wallet_accounts')->count();

$perUserAccountCounts = $connection->table('wallet_accounts')
    ->whereNotNull('user_id')
    ->selectRaw('user_id, count(*) as account_count')
    ->groupBy('user_id')
    ->orderBy('user_id')
    ->get()
    ->mapWithKeys(fn ($row) => [(string) $row->user_id => (int) $row->account_count])
    ->all();

$platformSuspenseAccountCount = (int) $connection->table('wallet_accounts')
    ->where('scope_type', 'platform')
    ->where('account_type', 'platform_suspense')
    ->count();

$snapshot = [
    'database' => $databaseName,
    'migrationCount' => $migrationCount,
    'userCount' => $userCount,
    'walletAccountCount' => $walletAccountCount,
    'perUserAccountCounts' => $perUserAccountCounts,
    'platformSuspenseAccountCount' => $platformSuspenseAccountCount,
    'ledgerTransactionCount' => (int) $connection->table('ledger_transactions')->count(),
    'ledgerEntryCount' => (int) $connection->table('ledger_entries')->count(),
    'reversalRequestCount' => (int) $connection->table('reversal_requests')->count(),
    'auditEventCount' => (int) $connection->table('audit_events')->count(),
    'balanceSnapshotCount' => (int) $connection->table('balance_snapshots')->count(),
];

echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
