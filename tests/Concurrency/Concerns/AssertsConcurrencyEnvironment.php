<?php

declare(strict_types=1);

namespace Tests\Concurrency\Concerns;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ConcurrencyDatabaseIdentityGuard;
use Tests\Concurrency\Support\ConcurrencyRunNamespace;

/**
 * Mixed into every scenario test via uses(). Two independent, defense-in-
 * depth reasons a test can cleanly skip (never fail) here: the opt-in flag
 * is absent, or the real MySQL 8 environment isn't reachable/isn't what it
 * claims to be. The structural guarantee that the default suite never even
 * discovers these files (tests/Concurrency sits outside phpunit.xml's own
 * testsuites) already does the heavy lifting - this is the friendly,
 * message-carrying layer for anyone who runs this directory by hand.
 *
 * @mixin TestCase
 */
trait AssertsConcurrencyEnvironment
{
    protected ConcurrencyRunNamespace $concurrencyNamespace;

    protected function ensureConcurrencyEnvironmentReady(string $scenarioSlug): void
    {
        if (! filter_var(env('RUN_MYSQL_CONCURRENCY_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped(
                'RUN_MYSQL_CONCURRENCY_TESTS is not set - the MySQL 8 concurrency suite only runs '.
                'via `composer test:mysql-concurrency`, never the default `composer test`.',
            );
        }

        // Verified via an EXPLICIT connection name first, before it is ever
        // trusted as the default below - the guard's own identity checks
        // (database name, MySQL 8, not MariaDB, host/port, scoped user,
        // required schema) all run against DB::connection('mysql_concurrency')
        // directly, never against whatever the ambient default happens to be.
        $guardResult = ConcurrencyDatabaseIdentityGuard::verifyRuntimeIdentity($this->app);

        if (! $guardResult->ok) {
            $this->markTestSkipped(
                'The isolated MySQL 8 concurrency environment is not ready ('.$guardResult->reason.') - '.
                'start it with `docker compose --env-file .env.mysql8.local -f docker-compose.mysql8.yml up -d` and retry.',
            );
        }

        // Only after the explicit-connection identity check above passes:
        // adopted as the default for the rest of this one test, purely so
        // this file can call the real production services
        // (WalletAccountProvisioner, LedgerPostingEngine, ...) directly for
        // pre-state setup - those services always resolve their models via
        // the default connection, never accept an explicit connection
        // name. Reset unconditionally in every file's own afterEach via
        // resetConcurrencyDefaultConnection() below.
        config(['database.default' => 'mysql_concurrency']);

        $this->concurrencyNamespace = new ConcurrencyRunNamespace($scenarioSlug, strtolower((string) Str::ulid()));
    }

    protected function resetConcurrencyDefaultConnection(): void
    {
        config(['database.default' => 'sqlite']);
    }

    protected function freshConcurrencyNamespace(string $scenarioSlug): ConcurrencyRunNamespace
    {
        return new ConcurrencyRunNamespace($scenarioSlug, strtolower((string) Str::ulid()));
    }
}
