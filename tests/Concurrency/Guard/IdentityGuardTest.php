<?php

declare(strict_types=1);

use Tests\Concurrency\Support\ConcurrencyDatabaseIdentityGuard as Guard;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Every rejection branch is proven here via evaluate() - the guard's pure
 * decision logic - fed entirely fabricated facts, no database connection of
 * any kind. This deliberately never opens a real connection to
 * `ellipzo_app`/MariaDB just to watch it get rejected: the danger case
 * (wrong database, MariaDB, etc.) is exercised through fabricated data
 * only, never a real write-capable MariaDB worker. The one test that
 * touches the real, correctly-scoped connection lives separately in
 * IdentityGuardIntegrationTest.php.
 *
 * @return array<string, mixed>
 */
function validGuardFacts(): array
{
    return [
        'optInFlagSet' => true,
        'configurationIsCached' => false,
        'resolvedDatabase' => 'ellipzo_concurrency_test',
        'expectedDatabase' => 'ellipzo_concurrency_test',
        'version' => '8.0.46',
        'versionComment' => 'MySQL Community Server - GPL',
        'resolvedHost' => '127.0.0.1',
        'resolvedPort' => '3307',
        'productionConnectionHost' => '127.0.0.1',
        'productionConnectionPort' => '3306',
        'currentUser' => 'ellipzo_concurrency',
        'expectedUser' => 'ellipzo_concurrency',
        'mysqlSystemSchemaAccessible' => false,
        'criticalMigrationNames' => [
            '2026_07_26_141233_create_wallet_accounts_table',
            '2026_07_26_141234_create_ledger_transactions_table',
            '2026_07_26_141235_create_ledger_entries_table',
            '2026_07_28_090000_create_reversal_requests_table',
            '2026_07_28_150000_add_entity_key_to_audit_events_table',
            '2026_07_29_090000_create_balance_snapshots_table',
        ],
    ];
}

test('a fully correct fact set permits the run', function (): void {
    $result = Guard::evaluate(validGuardFacts());

    expect($result->ok)->toBeTrue();
    expect($result->reason)->toBe(Guard::REASON_OK);
});

test('the missing opt-in flag refuses before any other check', function (): void {
    $result = Guard::evaluate([...validGuardFacts(), 'optInFlagSet' => false]);

    expect($result->ok)->toBeFalse();
    expect($result->reason)->toBe(Guard::REASON_OPT_IN_FLAG_MISSING);
});

test('cached configuration refuses, even with an otherwise-correct fact set', function (): void {
    $result = Guard::evaluate([...validGuardFacts(), 'configurationIsCached' => true]);

    expect($result->ok)->toBeFalse();
    expect($result->reason)->toBe(Guard::REASON_CONFIG_CACHED);
});

test('a mismatched database name refuses before mutation', function (): void {
    $result = Guard::evaluate([...validGuardFacts(), 'resolvedDatabase' => 'ellipzo_app']);

    expect($result->ok)->toBeFalse();
    expect($result->reason)->toBe(Guard::REASON_WRONG_DATABASE_NAME);
});

test('an empty resolved database name refuses', function (): void {
    $result = Guard::evaluate([...validGuardFacts(), 'resolvedDatabase' => '']);

    expect($result->ok)->toBeFalse();
    expect($result->reason)->toBe(Guard::REASON_WRONG_DATABASE_NAME);
});

test('a non-8.x version string refuses', function (): void {
    $result = Guard::evaluate([...validGuardFacts(), 'version' => '10.4.32']);

    expect($result->ok)->toBeFalse();
    expect($result->reason)->toBe(Guard::REASON_NOT_MYSQL_8);
});

test('a MariaDB version comment refuses, even alongside an 8.x version string', function (): void {
    // This is the MariaDB/default-connection danger case, exercised purely
    // through a fabricated version_comment - never a real MariaDB connection.
    $result = Guard::evaluate([...validGuardFacts(), 'version' => '8.0.32', 'versionComment' => 'mariadb.org binary distribution']);

    expect($result->ok)->toBeFalse();
    expect($result->reason)->toBe(Guard::REASON_IS_MARIADB);
});

test('a version comment that does not even mention MySQL refuses', function (): void {
    $result = Guard::evaluate([...validGuardFacts(), 'versionComment' => 'Some other database server']);

    expect($result->ok)->toBeFalse();
    expect($result->reason)->toBe(Guard::REASON_IS_MARIADB);
});

test('resolving to the same host and port as the production connection refuses', function (): void {
    $result = Guard::evaluate([
        ...validGuardFacts(),
        'resolvedHost' => '127.0.0.1',
        'resolvedPort' => '3306',
        'productionConnectionHost' => '127.0.0.1',
        'productionConnectionPort' => '3306',
    ]);

    expect($result->ok)->toBeFalse();
    expect($result->reason)->toBe(Guard::REASON_HOST_PORT_COLLIDES_WITH_PRODUCTION_CONNECTION);
});

test('an identical host but genuinely different port is permitted (the real isolated-container shape)', function (): void {
    $result = Guard::evaluate([
        ...validGuardFacts(),
        'resolvedHost' => '127.0.0.1',
        'resolvedPort' => '3307',
        'productionConnectionHost' => '127.0.0.1',
        'productionConnectionPort' => '3306',
    ]);

    expect($result->ok)->toBeTrue();
});

test('an unscoped or mismatched database user refuses', function (): void {
    $result = Guard::evaluate([...validGuardFacts(), 'currentUser' => 'root']);

    expect($result->ok)->toBeFalse();
    expect($result->reason)->toBe(Guard::REASON_UNSCOPED_APPLICATION_USER);
});

test('a connection that can read the mysql system schema refuses', function (): void {
    $result = Guard::evaluate([...validGuardFacts(), 'mysqlSystemSchemaAccessible' => true]);

    expect($result->ok)->toBeFalse();
    expect($result->reason)->toBe(Guard::REASON_MYSQL_SYSTEM_SCHEMA_ACCESSIBLE);
});

test('a missing required migration refuses', function (): void {
    $result = Guard::evaluate([...validGuardFacts(), 'criticalMigrationNames' => ['2026_07_26_141233_create_wallet_accounts_table']]);

    expect($result->ok)->toBeFalse();
    expect($result->reason)->toBe(Guard::REASON_CRITICAL_MIGRATIONS_MISSING);
});
