<?php

declare(strict_types=1);

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;
use Tests\Concurrency\Concerns\AssertsConcurrencyEnvironment;
use Tests\Concurrency\Support\FileBarrier;
use Tests\TestCase;

uses(TestCase::class, AssertsConcurrencyEnvironment::class);

beforeEach(function (): void {
    $this->ensureConcurrencyEnvironmentReady('worker-output-privacy');
});

afterEach(function (): void {
    $this->resetConcurrencyDefaultConnection();
});

/**
 * Proves the worker script's own sanitization contract with a real
 * exception carrying a real, distinctive canary secret in its message -
 * not by re-reading the source and trusting the docblock's own claim.
 * ScenarioRegistry::resolve() throws a genuine RuntimeException whose
 * message literally embeds the caller-supplied scenario slug
 * ("Unknown concurrency scenario: {$slug}") - passing a canary string as
 * the scenario name itself produces a real, uncontrived exception whose
 * message contains the canary, then classifyUnexpectedException() +
 * emitAndExit() must reduce it to only the closed outcome enum plus the
 * exception's class name, never its message.
 */
test('an unexpected worker exception carrying a canary secret never leaks it into stdout or stderr', function (): void {
    $canary = 'CANARY-SECRET-db-password-hunter2-'.bin2hex(random_bytes(8));

    $barrierDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ccprivacy-'.uniqid();
    $payloadPath = tempnam(sys_get_temp_dir(), 'ccprivacy');
    file_put_contents($payloadPath, '{}');

    $barrier = new FileBarrier($barrierDir);
    $barrier->release();

    $phpBinary = (new PhpExecutableFinder)->find();
    expect($phpBinary)->not->toBeFalse();

    /** @var ProcessResult $result */
    $result = Process::path(base_path())
        ->timeout(30)
        ->env(['DB_CONNECTION' => 'mysql_concurrency', 'RUN_MYSQL_CONCURRENCY_TESTS' => '1'])
        ->run([
            $phpBinary,
            base_path('tests/Concurrency/bin/concurrency-worker.php'),
            $canary,
            'worker-a',
            'privacytestrun',
            $barrierDir,
            '--payload='.$payloadPath,
        ]);

    $barrier->cleanup();
    @unlink($payloadPath);

    $stdout = $result->output();
    $stderr = $result->errorOutput();

    expect($stdout)->not->toContain($canary, 'The canary secret leaked into worker stdout.');
    expect($stderr)->not->toContain($canary, 'The canary secret leaked into worker stderr.');

    $lines = array_values(array_filter(array_map(trim(...), explode("\n", trim($stdout))), fn (string $l): bool => $l !== ''));
    $decoded = json_decode($lines[count($lines) - 1], associative: true);

    expect($decoded)->toBeArray();
    expect($decoded['outcome'] ?? null)->toBe('unexpected_failure');
    // The 'scenario' field legitimately echoes back the caller-supplied
    // argv value (an identifier, not a secret) by design - the real
    // sanitization boundary under test is 'extra', which must carry only
    // the exception's *class name* (plus the unrelated, non-sensitive
    // serviceInvokedAt timestamp every exception path now includes),
    // never getMessage() or any string derived from it. Asserting this
    // on 'extra' specifically (not the whole decoded report, which would
    // also flag the expected 'scenario' echo as a false positive) is the
    // honest, precise version of this proof.
    expect(array_keys($decoded['extra']))->toEqualCanonicalizing(['exceptionClass', 'serviceInvokedAt']);
    expect($decoded['extra']['exceptionClass'])->toBe('RuntimeException');
    expect(str_contains(json_encode($decoded['extra']), $canary))->toBeFalse();
});

/**
 * The same proof for the identity-guard-abort path (a worker that never
 * even reaches scenario dispatch), which has its own separate emitAndExit()
 * call site with its own separate 'extra' shape (abortReason, a closed
 * enum-like guard reason string - never a raw driver/config value).
 */
test('a worker whose identity guard fails never leaks connection details, only a closed reason code', function (): void {
    $barrierDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ccprivacy-guard-'.uniqid();
    $payloadPath = tempnam(sys_get_temp_dir(), 'ccprivacy');
    file_put_contents($payloadPath, '{}');

    $phpBinary = (new PhpExecutableFinder)->find();
    expect($phpBinary)->not->toBeFalse();

    // Explicitly forced to '0', not merely omitted: phpunit.concurrency.xml's
    // own <env> block already sets RUN_MYSQL_CONCURRENCY_TESTS=1 on this
    // whole Pest process, and Symfony Process inherits the parent
    // environment by default - simply not passing the key here would
    // leave the inherited "1" in place instead of unsetting it. This
    // explicit override deterministically forces the guard to fail at its
    // very first check, before any DB connection is even attempted,
    // exercising the guard-abort emitAndExit() branch.
    $result = Process::path(base_path())
        ->timeout(30)
        ->env(['DB_CONNECTION' => 'mysql_concurrency', 'RUN_MYSQL_CONCURRENCY_TESTS' => '0'])
        ->run([
            $phpBinary,
            base_path('tests/Concurrency/bin/concurrency-worker.php'),
            'irrelevant-scenario',
            'worker-a',
            'privacytestrun2',
            $barrierDir,
            '--payload='.$payloadPath,
        ]);

    @unlink($payloadPath);
    @rmdir($barrierDir);

    $stdout = trim($result->output());
    expect($stdout)->not->toBe('');

    $decoded = json_decode($stdout, associative: true);
    expect($decoded)->toBeArray();
    expect($decoded['outcome'] ?? null)->toBe('unexpected_failure');
    expect($decoded['extra']['abortReason'] ?? null)->toBe('OPT_IN_FLAG_MISSING');

    // The guard's reason is always one of its own closed REASON_* string
    // constants - never a raw config value, host, port, or credential.
    foreach (['127.0.0.1', 'ellipzo_concurrency_test', 'mysql_concurrency', 'password', 'DSN'] as $forbidden) {
        expect($stdout)->not->toContain($forbidden);
    }
});
