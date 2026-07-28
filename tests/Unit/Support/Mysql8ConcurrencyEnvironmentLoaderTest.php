<?php

use App\Support\Mysql8ConcurrencyEnvironmentLoader;

function mysql8LoaderTestManagedKeys(): array
{
    return [
        'MYSQL8_HOST', 'MYSQL8_PORT', 'MYSQL8_DATABASE', 'MYSQL8_USERNAME',
        'MYSQL8_PASSWORD', 'MYSQL8_ATTR_SSL_CA', 'MYSQL8_ROOT_PASSWORD',
        'SOME_UNRELATED_TESTPROBE_VAR', 'DB_DATABASE', 'DB_HOST',
    ];
}

function writeFakeMysql8EnvFile(string $dir, string $contents): void
{
    file_put_contents($dir.'/.env.mysql8.local', $contents);
}

/**
 * Snapshots one environment variable across all three storage locations
 * Laravel's own env()/Env::getRepository() can read from - $_ENV, $_SERVER,
 * and the process-level getenv()/putenv() store - then clears it from all
 * three. $_ENV/$_SERVER alone are not sufficient: this process's own
 * getenv()/putenv() store can independently carry a real value for these
 * same keys by the time any test runs, regardless of $_ENV/$_SERVER being
 * clear (confirmed empirically - some part of the test-runner's own
 * environment handling, not this project's application code, accounts for
 * that gap, and PutenvAdapter is exactly what Illuminate\Support\Env
 * consults for it). Never reads or logs the real value anywhere.
 *
 * @return array{0: string|null, 1: string|null, 2: string|null}
 */
function snapshotAndClearMysql8TestKey(string $key): array
{
    $processValue = getenv($key);

    $snapshot = [
        $_ENV[$key] ?? null,
        $_SERVER[$key] ?? null,
        $processValue === false ? null : $processValue,
    ];

    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);

    return $snapshot;
}

/**
 * @param  array{0: string|null, 1: string|null, 2: string|null}  $snapshot
 */
function restoreMysql8TestKey(string $key, array $snapshot): void
{
    [$envValue, $serverValue, $processValue] = $snapshot;

    if ($envValue === null) {
        unset($_ENV[$key]);
    } else {
        $_ENV[$key] = $envValue;
    }

    if ($serverValue === null) {
        unset($_SERVER[$key]);
    } else {
        $_SERVER[$key] = $serverValue;
    }

    if ($processValue === null) {
        putenv($key);
    } else {
        putenv("{$key}={$processValue}");
    }
}

beforeEach(function () {
    // Snapshot and clear every managed key across all three storage
    // locations so every test starts from a genuinely clean, observable
    // slate regardless of how this process's environment got into its
    // current state, then restore all three afterwards (below) so nothing
    // else in the suite - or the developer's own shell - is ever affected.
    $this->envSnapshot = [];
    foreach (mysql8LoaderTestManagedKeys() as $key) {
        $this->envSnapshot[$key] = snapshotAndClearMysql8TestKey($key);
    }

    $this->tempDir = sys_get_temp_dir().'/mysql8_loader_test_'.bin2hex(random_bytes(8));
    mkdir($this->tempDir);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        // glob('*') does not match dotfiles - delete the one file this
        // suite ever writes by its known, exact name instead.
        $envFile = $this->tempDir.'/.env.mysql8.local';
        if (is_file($envFile)) {
            unlink($envFile);
        }
        rmdir($this->tempDir);
    }

    foreach ($this->envSnapshot as $key => $snapshot) {
        restoreMysql8TestKey($key, $snapshot);
    }
});

test('a missing file safely no-ops', function () {
    Mysql8ConcurrencyEnvironmentLoader::load($this->tempDir);

    expect(env('MYSQL8_HOST'))->toBeNull();
});

test('allowed mysql 8 connection variables load from the file', function () {
    writeFakeMysql8EnvFile($this->tempDir, implode("\n", [
        'MYSQL8_HOST=fake-test-host',
        'MYSQL8_PORT=13307',
        'MYSQL8_DATABASE=fake_test_database',
        'MYSQL8_USERNAME=fake_test_user',
        'MYSQL8_PASSWORD=fake-test-password-CANARY',
    ]));

    Mysql8ConcurrencyEnvironmentLoader::load($this->tempDir);

    expect(env('MYSQL8_HOST'))->toBe('fake-test-host');
    expect(env('MYSQL8_PORT'))->toBe('13307');
    expect(env('MYSQL8_DATABASE'))->toBe('fake_test_database');
    expect(env('MYSQL8_USERNAME'))->toBe('fake_test_user');
    expect(env('MYSQL8_PASSWORD'))->toBe('fake-test-password-CANARY');
});

test('an unrelated variable in the file is not loaded', function () {
    writeFakeMysql8EnvFile($this->tempDir, implode("\n", [
        'MYSQL8_HOST=fake-test-host',
        'SOME_UNRELATED_TESTPROBE_VAR=should-never-load-CANARY',
    ]));

    Mysql8ConcurrencyEnvironmentLoader::load($this->tempDir);

    expect(env('MYSQL8_HOST'))->toBe('fake-test-host');
    expect(env('SOME_UNRELATED_TESTPROBE_VAR'))->toBeNull();
});

test('MYSQL8_ROOT_PASSWORD is never loaded into the application environment', function () {
    writeFakeMysql8EnvFile($this->tempDir, implode("\n", [
        'MYSQL8_HOST=fake-test-host',
        'MYSQL8_ROOT_PASSWORD=fake-root-password-CANARY',
    ]));

    Mysql8ConcurrencyEnvironmentLoader::load($this->tempDir);

    expect(env('MYSQL8_HOST'))->toBe('fake-test-host');
    expect(env('MYSQL8_ROOT_PASSWORD'))->toBeNull();
});

test('an already-defined process environment variable is never overwritten', function () {
    $_ENV['MYSQL8_HOST'] = 'pre-existing-real-value';

    writeFakeMysql8EnvFile($this->tempDir, 'MYSQL8_HOST=attempted-overwrite-from-file');

    Mysql8ConcurrencyEnvironmentLoader::load($this->tempDir);

    expect(env('MYSQL8_HOST'))->toBe('pre-existing-real-value');
});

test('a malformed file fails closed with only a generic error', function () {
    writeFakeMysql8EnvFile($this->tempDir, 'MYSQL8_PASSWORD="abc\qSOME_CANARY_SECRET"');

    $caught = null;
    try {
        Mysql8ConcurrencyEnvironmentLoader::load($this->tempDir);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class);
    expect($caught->getMessage())->toBe('The local MySQL 8 environment file is invalid.');
});

test('a malformed file never exposes the offending line, its value, or a chained previous exception', function () {
    $secret = 'SOME_CANARY_SECRET_VALUE_'.bin2hex(random_bytes(4));
    writeFakeMysql8EnvFile($this->tempDir, 'MYSQL8_PASSWORD="abc\q'.$secret.'"');

    $caught = null;
    try {
        Mysql8ConcurrencyEnvironmentLoader::load($this->tempDir);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->getMessage())->not->toContain($secret);
    expect($caught->getPrevious())->toBeNull();
});

test('a malformed file is not silently ignored', function () {
    writeFakeMysql8EnvFile($this->tempDir, 'MYSQL8_PASSWORD="abc\qSOME_CANARY"');

    expect(fn () => Mysql8ConcurrencyEnvironmentLoader::load($this->tempDir))
        ->toThrow(RuntimeException::class);
});

test('the standard mysql and sqlite connections cannot be affected by the loader', function () {
    // DB_DATABASE/DB_HOST are exactly what the 'sqlite'/'mysql' connections
    // in config/database.php read - not in the loader's allow list, so
    // this is a direct test that it cannot touch them, without needing the
    // full app container (this suite runs under tests/Unit, so config()
    // - which requires a booted container - is deliberately not used here;
    // env() is what config/database.php's own connection arrays actually
    // call, so it is the meaningful thing to assert on).
    writeFakeMysql8EnvFile($this->tempDir, implode("\n", [
        'DB_DATABASE=malicious-override-CANARY',
        'DB_HOST=malicious-override-CANARY',
        'MYSQL8_HOST=fake-test-host',
    ]));

    Mysql8ConcurrencyEnvironmentLoader::load($this->tempDir);

    expect(env('DB_DATABASE'))->not->toBe('malicious-override-CANARY');
    expect(env('DB_HOST'))->not->toBe('malicious-override-CANARY');
});

// ---------------------------------------------------------------
// Regression tests for snapshotAndClearMysql8TestKey()/
// restoreMysql8TestKey() themselves - the isolation mechanism this whole
// suite depends on, exercised directly with a dedicated probe key (never
// a real MYSQL8_* name, so these can never interact with the managed-key
// list above) and fake canary values only, never a real credential.
// ---------------------------------------------------------------

test('environment restoration works correctly after normal completion', function () {
    $probeKey = 'MYSQL8_LOADER_ISOLATION_REGRESSION_PROBE';
    $_ENV[$probeKey] = 'original-env-value';
    $_SERVER[$probeKey] = 'original-server-value';
    putenv("{$probeKey}=original-process-value");

    try {
        $snapshot = snapshotAndClearMysql8TestKey($probeKey);

        expect($_ENV[$probeKey] ?? null)->toBeNull();
        expect($_SERVER[$probeKey] ?? null)->toBeNull();
        expect(getenv($probeKey))->toBeFalse();

        restoreMysql8TestKey($probeKey, $snapshot);

        expect($_ENV[$probeKey])->toBe('original-env-value');
        expect($_SERVER[$probeKey])->toBe('original-server-value');
        expect(getenv($probeKey))->toBe('original-process-value');
    } finally {
        unset($_ENV[$probeKey], $_SERVER[$probeKey]);
        putenv($probeKey);
    }
});

test('environment restoration still runs after an exception is thrown between clear and restore', function () {
    $probeKey = 'MYSQL8_LOADER_ISOLATION_REGRESSION_PROBE';
    $_ENV[$probeKey] = 'original-env-value';
    $_SERVER[$probeKey] = 'original-server-value';
    putenv("{$probeKey}=original-process-value");

    try {
        $snapshot = snapshotAndClearMysql8TestKey($probeKey);

        try {
            throw new RuntimeException('forced failure between clear and restore, simulating a real test assertion/exception path');
        } catch (RuntimeException) {
            // expected
        } finally {
            restoreMysql8TestKey($probeKey, $snapshot);
        }

        expect($_ENV[$probeKey])->toBe('original-env-value');
        expect($_SERVER[$probeKey])->toBe('original-server-value');
        expect(getenv($probeKey))->toBe('original-process-value');
    } finally {
        unset($_ENV[$probeKey], $_SERVER[$probeKey]);
        putenv($probeKey);
    }
});

test('environment restoration is stable across repeated execution in the same process', function () {
    $probeKey = 'MYSQL8_LOADER_ISOLATION_REGRESSION_PROBE';
    $_ENV[$probeKey] = 'original-env-value';
    $_SERVER[$probeKey] = 'original-server-value';
    putenv("{$probeKey}=original-process-value");

    try {
        for ($i = 0; $i < 5; $i++) {
            $snapshot = snapshotAndClearMysql8TestKey($probeKey);

            expect($_ENV[$probeKey] ?? null)->toBeNull();
            expect($_SERVER[$probeKey] ?? null)->toBeNull();
            expect(getenv($probeKey))->toBeFalse();

            restoreMysql8TestKey($probeKey, $snapshot);

            expect($_ENV[$probeKey])->toBe('original-env-value');
            expect($_SERVER[$probeKey])->toBe('original-server-value');
            expect(getenv($probeKey))->toBe('original-process-value');
        }
    } finally {
        unset($_ENV[$probeKey], $_SERVER[$probeKey]);
        putenv($probeKey);
    }
});

test('environment restoration correctly restores a key that was never set at all', function () {
    $probeKey = 'MYSQL8_LOADER_ISOLATION_REGRESSION_PROBE';
    unset($_ENV[$probeKey], $_SERVER[$probeKey]);
    putenv($probeKey);

    $snapshot = snapshotAndClearMysql8TestKey($probeKey);
    restoreMysql8TestKey($probeKey, $snapshot);

    expect($_ENV[$probeKey] ?? null)->toBeNull();
    expect($_SERVER[$probeKey] ?? null)->toBeNull();
    expect(getenv($probeKey))->toBeFalse();
});
