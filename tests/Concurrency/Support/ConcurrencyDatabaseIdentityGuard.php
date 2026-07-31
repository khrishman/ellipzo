<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The abort-before-mutation safety check every worker and the coordinator
 * both run first, before touching any scenario table. Split into a pure
 * evaluate() (a plain array of already-gathered facts in, a result out - no
 * I/O, fully unit-testable with fabricated facts) and a thin
 * verifyRuntimeIdentity() wrapper that gathers those facts from the real
 * `mysql_concurrency` connection. This split exists specifically so the
 * guard's rejection logic can be proven correct (wrong database name,
 * MariaDB version string, cached config, missing opt-in flag) without ever
 * opening a real connection to `ellipzo_app`/MariaDB just to watch it get
 * rejected - the danger case is exercised via fabricated facts only.
 */
final class ConcurrencyDatabaseIdentityGuard
{
    public const string REASON_OK = 'OK';

    public const string REASON_OPT_IN_FLAG_MISSING = 'OPT_IN_FLAG_MISSING';

    public const string REASON_CONFIG_CACHED = 'CONFIG_CACHED';

    public const string REASON_WRONG_DATABASE_NAME = 'WRONG_DATABASE_NAME';

    public const string REASON_NOT_MYSQL_8 = 'NOT_MYSQL_8';

    public const string REASON_IS_MARIADB = 'IS_MARIADB';

    public const string REASON_HOST_PORT_COLLIDES_WITH_PRODUCTION_CONNECTION = 'HOST_PORT_COLLIDES_WITH_PRODUCTION_CONNECTION';

    public const string REASON_UNSCOPED_APPLICATION_USER = 'UNSCOPED_APPLICATION_USER';

    public const string REASON_MYSQL_SYSTEM_SCHEMA_ACCESSIBLE = 'MYSQL_SYSTEM_SCHEMA_ACCESSIBLE';

    public const string REASON_CRITICAL_MIGRATIONS_MISSING = 'CRITICAL_MIGRATIONS_MISSING';

    /**
     * Every wallet/ledger table this task's scenarios touch must already
     * exist - checked by migration-name substring, not an exact count, so
     * this guard doesn't need updating every time an unrelated migration is
     * added elsewhere in the app.
     *
     * @var list<string>
     */
    private const array REQUIRED_MIGRATION_SUBSTRINGS = [
        'create_wallet_accounts_table',
        'create_ledger_transactions_table',
        'create_ledger_entries_table',
        'create_reversal_requests_table',
        'add_entity_key_to_audit_events_table',
        'create_balance_snapshots_table',
    ];

    /**
     * Pure decision logic - no database or filesystem access. $facts keys:
     * optInFlagSet, configurationIsCached, resolvedDatabase,
     * expectedDatabase, version, versionComment, resolvedHost, resolvedPort,
     * productionConnectionHost, productionConnectionPort, currentUser,
     * expectedUser, mysqlSystemSchemaAccessible, criticalMigrationNames
     * (list<string> of migration names actually present).
     *
     * productionConnectionHost/Port always describe the real `mysql`
     * connection specifically (never "whatever config('database.default')
     * currently is") - inside a worker process, DB_CONNECTION is
     * deliberately overridden to `mysql_concurrency` itself, so comparing
     * against the ambient default there would trivially compare
     * mysql_concurrency against itself and always "collide".
     *
     * @param  array<string, mixed>  $facts
     */
    public static function evaluate(array $facts): ConcurrencyGuardResult
    {
        if ($facts['optInFlagSet'] !== true) {
            return ConcurrencyGuardResult::fail(self::REASON_OPT_IN_FLAG_MISSING);
        }

        if ($facts['configurationIsCached'] === true) {
            return ConcurrencyGuardResult::fail(self::REASON_CONFIG_CACHED);
        }

        if (! is_string($facts['resolvedDatabase']) || $facts['resolvedDatabase'] === '' || $facts['resolvedDatabase'] !== $facts['expectedDatabase']) {
            return ConcurrencyGuardResult::fail(self::REASON_WRONG_DATABASE_NAME);
        }

        $version = is_string($facts['version']) ? $facts['version'] : '';

        if (! str_starts_with($version, '8.')) {
            return ConcurrencyGuardResult::fail(self::REASON_NOT_MYSQL_8);
        }

        $versionComment = is_string($facts['versionComment']) ? $facts['versionComment'] : '';

        if (str_contains(strtolower($versionComment), 'mariadb') || ! str_contains(strtolower($versionComment), 'mysql')) {
            return ConcurrencyGuardResult::fail(self::REASON_IS_MARIADB);
        }

        $resolvedTuple = [$facts['resolvedHost'] ?? null, (string) ($facts['resolvedPort'] ?? '')];
        $productionTuple = [$facts['productionConnectionHost'] ?? null, (string) ($facts['productionConnectionPort'] ?? '')];

        if ($resolvedTuple === $productionTuple) {
            return ConcurrencyGuardResult::fail(self::REASON_HOST_PORT_COLLIDES_WITH_PRODUCTION_CONNECTION);
        }

        if (! is_string($facts['currentUser']) || $facts['currentUser'] === '' || $facts['currentUser'] !== $facts['expectedUser']) {
            return ConcurrencyGuardResult::fail(self::REASON_UNSCOPED_APPLICATION_USER);
        }

        if ($facts['mysqlSystemSchemaAccessible'] === true) {
            return ConcurrencyGuardResult::fail(self::REASON_MYSQL_SYSTEM_SCHEMA_ACCESSIBLE);
        }

        $presentMigrations = is_array($facts['criticalMigrationNames']) ? $facts['criticalMigrationNames'] : [];

        foreach (self::REQUIRED_MIGRATION_SUBSTRINGS as $required) {
            $found = false;

            foreach ($presentMigrations as $migrationName) {
                if (is_string($migrationName) && str_contains($migrationName, $required)) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                return ConcurrencyGuardResult::fail(self::REASON_CRITICAL_MIGRATIONS_MISSING);
            }
        }

        return ConcurrencyGuardResult::pass();
    }

    /**
     * Gathers real facts from the live application/connection and delegates
     * to evaluate(). This is the only method that touches I/O - every
     * branch of the decision logic itself lives in evaluate() above.
     */
    public static function verifyRuntimeIdentity(Application $app): ConcurrencyGuardResult
    {
        $optInFlagSet = filter_var(env('RUN_MYSQL_CONCURRENCY_TESTS', false), FILTER_VALIDATE_BOOL);

        if ($app->configurationIsCached()) {
            return ConcurrencyGuardResult::fail(self::REASON_CONFIG_CACHED);
        }

        try {
            $connection = DB::connection('mysql_concurrency');

            $identityRow = $connection->selectOne('select database() as db, @@version as version, @@version_comment as version_comment, current_user() as current_user_value');

            $resolvedDatabase = $identityRow?->db;
            $version = $identityRow?->version;
            $versionComment = $identityRow?->version_comment;
            $currentUser = is_string($identityRow?->current_user_value)
                ? explode('@', $identityRow->current_user_value)[0]
                : null;

            $mysqlSystemSchemaAccessible = self::canAccessMysqlSystemSchema($connection);

            $criticalMigrationNames = $connection->table('migrations')->pluck('migration')->all();

            return self::evaluate([
                'optInFlagSet' => $optInFlagSet,
                'configurationIsCached' => false,
                'resolvedDatabase' => $resolvedDatabase,
                'expectedDatabase' => config('database.connections.mysql_concurrency.database'),
                'version' => $version,
                'versionComment' => $versionComment,
                'resolvedHost' => config('database.connections.mysql_concurrency.host'),
                'resolvedPort' => config('database.connections.mysql_concurrency.port'),
                'productionConnectionHost' => config('database.connections.mysql.host'),
                'productionConnectionPort' => config('database.connections.mysql.port'),
                'currentUser' => $currentUser,
                'expectedUser' => config('database.connections.mysql_concurrency.username'),
                'mysqlSystemSchemaAccessible' => $mysqlSystemSchemaAccessible,
                'criticalMigrationNames' => $criticalMigrationNames,
            ]);
        } catch (Throwable) {
            // Any unexpected failure (connection refused, auth failure,
            // unexpected schema) is a fail-closed abort - never leaks the
            // underlying driver exception's message.
            return ConcurrencyGuardResult::fail(self::REASON_WRONG_DATABASE_NAME);
        }
    }

    /**
     * A properly docker-compose-provisioned application user has no grant
     * on the `mysql` system schema at all (the official mysql image only
     * grants the created user privileges on MYSQL_DATABASE). Success here
     * is the red flag, not the failure - an access-denied exception is the
     * expected, safe outcome and is deliberately swallowed without
     * inspecting its message.
     */
    private static function canAccessMysqlSystemSchema(mixed $connection): bool
    {
        try {
            $connection->selectOne('select count(*) as c from mysql.user');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
