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

beforeEach(function () {
    // The real .env.mysql8.local already loaded these for real during this
    // process's own app boot (config/database.php runs the real loader
    // against the real project root, which genuinely has that file) -
    // snapshot and clear the relevant keys so every test starts from a
    // clean, observable slate, then restore them afterwards so nothing
    // else in the suite is affected. Never reads the real file's content.
    $this->envSnapshot = [];
    foreach (mysql8LoaderTestManagedKeys() as $key) {
        $this->envSnapshot[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        unset($_ENV[$key], $_SERVER[$key]);
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

    foreach ($this->envSnapshot as $key => [$envValue, $serverValue]) {
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
