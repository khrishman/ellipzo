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
    $this->ensureConcurrencyEnvironmentReady('bootstrap-smoke');
});

afterEach(function (): void {
    $this->resetConcurrencyDefaultConnection();
});

/**
 * Regression coverage for the exact defect a Pint auto-fix pass introduced
 * once already this task: relocating a standalone script's `use` imports
 * below its own `Kernel::class` bootstrap line silently fatal-errors the
 * script before it can print anything at all ("Worker process produced no
 * output"), since PHP resolves an unqualified class name against whatever
 * `use` imports are textually in effect *at that point in the file* -
 * imports are never file-wide hoisted. Both scripts now reference
 * `\Illuminate\Contracts\Console\Kernel::class` fully qualified instead,
 * immune to import position entirely - these tests prove that each
 * standalone entrypoint genuinely boots, runs its identity guard, and
 * produces real structured output, so a future formatting pass (or any
 * other edit) that reintroduces a blank-output failure is caught here
 * immediately rather than discovered later via a confusing scenario-test
 * failure.
 */
function runStandaloneScript(string $relativePath, array $args = [], array $extraEnv = []): ProcessResult
{
    $phpBinary = (new PhpExecutableFinder)->find();
    expect($phpBinary)->not->toBeFalse();

    return Process::path(base_path())
        ->timeout(30)
        ->env(['DB_CONNECTION' => 'mysql_concurrency', 'RUN_MYSQL_CONCURRENCY_TESTS' => '1', ...$extraEnv])
        ->run([$phpBinary, base_path($relativePath), ...$args]);
}

test('concurrency-worker.php boots, runs its identity guard, and produces one structured JSON line', function (): void {
    $barrierDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ccsmoke-'.uniqid();
    $payloadPath = tempnam(sys_get_temp_dir(), 'ccsmoke');
    file_put_contents($payloadPath, '{}');

    // Pre-released: this smoke test proves boot -> guard -> JSON output,
    // not barrier-wait behavior (that is WorkerTerminationRecoveryTest's
    // own concern) - without this, the worker would block for
    // FileBarrier's full default timeout waiting for a coordinator that
    // never arrives.
    $barrier = new FileBarrier($barrierDir);
    $barrier->release();

    $result = runStandaloneScript('tests/Concurrency/bin/concurrency-worker.php', [
        // An unknown scenario is deliberate: it proves the full
        // bootstrap -> identity guard -> JSON-report pipeline without
        // needing a real scenario, real barrier partner, or real
        // financial fixtures - exactly the seam the Pint regression
        // broke, before any of that scenario-specific machinery runs.
        'nonexistent-smoke-scenario',
        'worker-a',
        'smoketestrun',
        $barrierDir,
        '--payload='.$payloadPath,
    ]);

    $barrier->cleanup();
    @unlink($payloadPath);

    $output = trim($result->output());
    expect($output)->not->toBe('', 'Worker script produced no stdout output at all - the exact Pint-regression failure mode.');

    $lines = array_values(array_filter(array_map(trim(...), explode("\n", $output)), fn (string $l): bool => $l !== ''));
    $decoded = json_decode($lines[count($lines) - 1], associative: true);

    expect($decoded)->toBeArray();
    expect($decoded['scenario'] ?? null)->toBe('nonexistent-smoke-scenario');
    expect($decoded['role'] ?? null)->toBe('worker-a');
    expect($decoded['outcome'] ?? null)->toBe('unexpected_failure');
    expect($decoded['mysqlConnectionId'] ?? 0)->toBeGreaterThan(0);
    expect($decoded['extra']['exceptionClass'] ?? null)->toBe('RuntimeException');
});

test('mariadb-readonly-snapshot.php boots and produces one structured JSON line', function (): void {
    // This script is designed to run from a plain terminal, never as a
    // Pest subprocess (its own docblock says so explicitly) - so
    // phpunit.concurrency.xml's own defense-in-depth DB_DATABASE=":memory:"
    // override (shared by config/database.php's sqlite *and* mysql
    // connections, both reading the same DB_DATABASE key) would otherwise
    // leak into this subprocess and point its explicit `mysql` connection
    // at a nonexistent ":memory:" database. Restoring the real value from
    // .env directly here (not via env(), which is already shadowed by
    // Pest's own already-booted process environment) reproduces this
    // script's genuine real-world invocation instead of an artifact of
    // testing it from inside Pest.
    $envContents = file_get_contents(base_path('.env'));
    preg_match('/^DB_DATABASE=(.*)$/m', $envContents, $matches);
    $realDatabaseName = trim($matches[1] ?? '');
    expect($realDatabaseName)->not->toBe('', 'Could not read the real DB_DATABASE value from .env for this test.');

    $result = runStandaloneScript('tests/Concurrency/bin/mariadb-readonly-snapshot.php', [], ['DB_DATABASE' => $realDatabaseName]);

    $output = trim($result->output());
    expect($output)->not->toBe('', 'MariaDB snapshot script produced no stdout output at all.');

    $decoded = json_decode($output, associative: true);
    expect($decoded)->toBeArray();
    expect($decoded['database'] ?? null)->toBe($realDatabaseName);
    expect(array_key_exists('migrationCount', $decoded))->toBeTrue();
    expect(array_key_exists('userCount', $decoded))->toBeTrue();
});

test('recover-concurrency-database.php boots, runs its identity guard, and produces a dry-run report with no --confirm', function (): void {
    $result = runStandaloneScript('tests/Concurrency/bin/recover-concurrency-database.php');

    $output = trim($result->output());
    expect($output)->not->toBe('', 'Recovery script produced no stdout output at all.');
    expect($output)->toContain('dry run only');
});

test('run-concurrency-suite.php boots, runs its identity guard, captures a baseline, and restores it - even for a pest run that matches nothing', function (): void {
    $result = runStandaloneScript('tests/Concurrency/bin/run-concurrency-suite.php', ['--filter=this_matches_nothing_at_all_xyz']);

    $output = trim($result->output());
    expect($output)->not->toBe('', 'Suite wrapper script produced no stdout output at all.');
    expect($output)->toContain('baseline captured');
    expect($output)->toContain('restoration verified');
});
