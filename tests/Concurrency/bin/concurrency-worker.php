<?php

declare(strict_types=1);

/**
 * Task 2.9 worker entrypoint - deliberately NOT an Artisan command.
 * bootstrap/app.php auto-registers every class under app/Console/Commands
 * unconditionally in every environment (confirmed by reading
 * ApplicationBuilder), so a runtime guard inside handle() only stops
 * *execution* somewhere it shouldn't - the command would still exist and
 * ship with the app. A standalone script under tests/ is strictly safer
 * (most Laravel deploys don't even ship tests/) and bootstraps exactly the
 * way artisan does internally, giving this process a genuine, independent,
 * full application bootstrap - its own service providers, config, and
 * database manager, none shared with the coordinator or any other worker.
 *
 * Argv contract: {scenario} {role} {run-id} {barrier-dir} --payload={path}
 * No credential ever appears here - scenario data lives in the payload
 * file, credentials live only in .env.mysql8.local, read by
 * Mysql8ConcurrencyEnvironmentLoader inside this process's own bootstrap.
 *
 * Stdout contract: exactly one JSON line (WorkerReport::toJsonLine()) as
 * the last thing this script ever prints. Stderr may carry a short,
 * sanitized diagnostic (exception class name only, never getMessage()).
 */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ConcurrencyDatabaseIdentityGuard;
use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;
use Tests\Concurrency\Support\FileBarrier;
use Tests\Concurrency\Support\Scenarios\ScenarioRegistry;
use Tests\Concurrency\Support\WorkerReport;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
// The `use Illuminate\Contracts\Console\Kernel;` import above is
// deliberately kept in the file's own top-of-file use-block, before this
// line - not after the require statements. PHP resolves an unqualified
// class name against whatever `use` imports are textually in effect *at
// that point in the file*; imports are never file-wide hoisted. A prior
// version of this script had its use-block positioned *after* this
// bootstrap line (using a fully-qualified `\Illuminate\...\Kernel::class`
// reference instead) - Pint's own `fully_qualified_strict_types` fixer
// unavoidably rewrites a fully-qualified class-const reference back into
// short-form-plus-import in this project, so a leading backslash does not
// survive a real `pint` run here and cannot be relied on as the
// protection; the use-block's own position, verified safe by
// BootstrapScriptSmokeTest.php, is the real one. See docs/memory.md for
// the incident this caused once already.
$app->make(Kernel::class)->bootstrap();

function emitAndExit(WorkerReport $report, int $exitCode): never
{
    echo $report->toJsonLine().PHP_EOL;
    exit($exitCode);
}

$argv = $_SERVER['argv'];
array_shift($argv); // script name

$positional = [];
$payloadPath = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--payload=')) {
        $payloadPath = substr($arg, strlen('--payload='));
    } else {
        $positional[] = $arg;
    }
}

[$scenario, $role, $runId, $barrierDir] = array_pad($positional, 4, null);

if ($scenario === null || $role === null || $runId === null || $barrierDir === null || $payloadPath === null) {
    fwrite(STDERR, 'ArgumentError'.PHP_EOL);
    exit(1);
}

$payloadRaw = is_file($payloadPath) ? file_get_contents($payloadPath) : false;
$payload = $payloadRaw !== false ? json_decode($payloadRaw, associative: true) : null;
$payload = is_array($payload) ? $payload : [];

$pid = getmypid();

// The abort-before-mutation identity guard - run before touching any
// scenario table, exactly as it runs for the coordinator.
$guardResult = ConcurrencyDatabaseIdentityGuard::verifyRuntimeIdentity($app);

if (! $guardResult->ok) {
    emitAndExit(new WorkerReport(
        scenario: $scenario,
        role: $role,
        pid: $pid,
        mysqlConnectionId: 0,
        tBefore: microtime(true),
        tAfter: microtime(true),
        outcome: ConcurrencyOutcomeCategory::UnexpectedFailure,
        committedTransactionId: null,
        extra: ['abortReason' => $guardResult->reason],
    ), 1);
}

$connectionIdRow = DB::connection('mysql_concurrency')->selectOne('select connection_id() as id');
$mysqlConnectionId = (int) $connectionIdRow->id;

$tBefore = microtime(true);

try {
    $barrier = new FileBarrier($barrierDir);
    $barrier->signalReady($role);
    $barrier->waitForGo();

    // A second, generic marker distinct from "ready" - signalled the
    // instant this worker is about to invoke the scenario's own service
    // call, i.e. the earliest point a coordinator can safely treat this
    // worker as "genuinely attempting the contested operation now" rather
    // than merely "released to proceed". Harmless for every scenario that
    // doesn't wait on it (FileBarrier::cleanup() removes it along with
    // every other marker file regardless of name); Scenario F's
    // coordinator uses it together with a lock held on its own separate
    // connection to force a provable overlapping-invocation window rather
    // than relying on process-scheduling coincidence.
    $serviceInvokedAt = microtime(true);
    $barrier->signalReady("{$role}-invoked");

    $scenarioHandler = ScenarioRegistry::resolve($scenario);
    $result = $scenarioHandler->runWorker($role, $payload);

    $tAfter = microtime(true);

    emitAndExit(new WorkerReport(
        scenario: $scenario,
        role: $role,
        pid: $pid,
        mysqlConnectionId: $mysqlConnectionId,
        tBefore: $tBefore,
        tAfter: $tAfter,
        outcome: $result['outcome'],
        committedTransactionId: $result['committedTransactionId'],
        extra: ['serviceInvokedAt' => (string) $serviceInvokedAt, ...($result['extra'] ?? [])],
    ), 0);
} catch (Throwable $exception) {
    $tAfter = microtime(true);

    $outcome = classifyUnexpectedException($exception);

    fwrite(STDERR, get_class($exception).PHP_EOL);

    emitAndExit(new WorkerReport(
        scenario: $scenario,
        role: $role,
        pid: $pid,
        mysqlConnectionId: $mysqlConnectionId,
        tBefore: $tBefore,
        tAfter: $tAfter,
        outcome: $outcome,
        committedTransactionId: null,
        extra: [
            'exceptionClass' => $exception::class,
            ...(isset($serviceInvokedAt) ? ['serviceInvokedAt' => (string) $serviceInvokedAt] : []),
        ],
    ), 0);
}

/**
 * Classifies a genuinely unexpected exception into the closed outcome enum
 * by SQLSTATE/driver error code only - never by inspecting the message
 * text, so no SQL fragment or identifier can leak into the report.
 */
function classifyUnexpectedException(Throwable $exception): ConcurrencyOutcomeCategory
{
    if ($exception instanceof QueryException) {
        $sqlState = $exception->getCode();
        $driverCode = $exception->errorInfo[1] ?? null;

        if ($sqlState === '40001' || $driverCode === 1213) {
            return ConcurrencyOutcomeCategory::Deadlock;
        }

        if ($driverCode === 1205) {
            return ConcurrencyOutcomeCategory::LockTimeout;
        }
    }

    return ConcurrencyOutcomeCategory::UnexpectedFailure;
}
