<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

use Illuminate\Process\InvokedProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Spawns every worker for one scenario run as genuine independent OS
 * processes via Laravel's own Process facade (Illuminate\Process, built on
 * the already-installed symfony/process - no new dependency), each with an
 * explicit DB_CONNECTION=mysql_concurrency environment override and its own
 * payload file.
 *
 * Deliberately does not use Process::pool()'s own wait(): that convenience
 * method maps wait() across every invoked process in insertion order and
 * lets the first ProcessTimedOutException abort the whole call, discarding
 * results already obtained for other workers that finished cleanly before
 * it. Every worker here is started individually and polled with a single
 * shared bounded deadline instead, so one stuck worker never causes another
 * worker's already-available result to be lost, and every process still
 * running at the deadline is force-terminated before this method returns -
 * never left running indefinitely.
 */
final class WorkerLauncher
{
    private const string WORKER_SCRIPT = __DIR__.'/../bin/concurrency-worker.php';

    private const int POLL_INTERVAL_MICROSECONDS = 10_000;

    /**
     * $barrier, when given, is waited-on and released here, between
     * spawning and awaiting completion - the one moment a synchronous
     * coordinator can act while every worker is genuinely blocked in its
     * own waitForGo(). Centralized here (not duplicated per scenario test)
     * since every scenario needs the identical spawn -> confirm-all-ready
     * -> release -> await-completion sequence.
     *
     * $afterRelease, when given, runs synchronously immediately after
     * $barrier->release() and before the completion-polling loop begins -
     * the one seam a coordinator needs to hold a resource (e.g. a row lock
     * on a separate connection) across the exact window between "workers
     * are released to proceed" and "workers are allowed to actually
     * finish". Used by exactly one scenario (F, reversal-request
     * lock-serialization) as of this writing; every other call site simply
     * omits it and the sequence is unchanged.
     *
     * @param  array<string, array{runId: string, barrierDir: string, payloadPath: string}>  $workers  keyed by role
     * @return array<string, ?ProcessResult> null for a role that never finished (forcibly terminated)
     */
    public function spawnAndWait(string $scenario, array $workers, float $timeoutSeconds, ?FileBarrier $barrier = null, ?\Closure $afterRelease = null): array
    {
        $phpBinary = (new PhpExecutableFinder)->find();

        if ($phpBinary === false) {
            throw new RuntimeException('Unable to locate the PHP executable for spawning concurrency workers.');
        }

        /** @var array<string, InvokedProcess> $invoked */
        $invoked = [];

        foreach ($workers as $role => $spec) {
            $invoked[$role] = Process::path(base_path())
                ->timeout((int) ceil($timeoutSeconds))
                ->env(['DB_CONNECTION' => 'mysql_concurrency'])
                ->start([
                    $phpBinary,
                    self::WORKER_SCRIPT,
                    $scenario,
                    $role,
                    $spec['runId'],
                    $spec['barrierDir'],
                    '--payload='.$spec['payloadPath'],
                ]);
        }

        /** @var array<string, ?ProcessResult> $results */
        $results = array_fill_keys(array_keys($workers), null);
        $pending = $invoked;

        try {
            if ($barrier !== null) {
                try {
                    $barrier->waitForAllReady(array_keys($workers));
                    $barrier->release();

                    if ($afterRelease !== null) {
                        $afterRelease();
                    }
                } catch (RuntimeException) {
                    // A worker never reached "ready" (crashed before the
                    // barrier, guard aborted, etc.). Not rethrown here -
                    // falling through to the normal reap loop below still
                    // collects whatever report each worker managed to
                    // produce (including a worker's own guard-abort
                    // report), and the deadline/finally block below still
                    // guarantees every process is reaped or terminated.
                }
            }

            $deadline = microtime(true) + $timeoutSeconds;

            while ($pending !== [] && microtime(true) < $deadline) {
                foreach ($pending as $role => $process) {
                    if (! $process->running()) {
                        $results[$role] = $process->wait();
                        unset($pending[$role]);
                    }
                }

                if ($pending !== []) {
                    usleep(self::POLL_INTERVAL_MICROSECONDS);
                }
            }
        } finally {
            // Anything still running - whether the barrier threw or the
            // deadline was simply reached - is forcibly terminated here,
            // never left as an orphan process. Symfony's stop() sends
            // SIGTERM then SIGKILL (taskkill on this Windows machine); the
            // dropped connection causes MySQL/InnoDB to roll back and
            // release any locks automatically.
            foreach ($pending as $role => $process) {
                $process->stop(3);
                $results[$role] = null;
            }
        }

        return $results;
    }
}
