<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

use RuntimeException;

/**
 * A bounded, deterministic release barrier built entirely from filesystem
 * markers - no arbitrary sleep is ever treated as correctness evidence.
 * Every marker file is written to a `.tmp` sibling then atomically
 * `rename()`d into place, removing the "file exists but not fully written"
 * race a direct write could hit. Every wait has a hard ceiling; exceeding
 * it is a loud, sanitized failure, never a silent pass.
 */
final class FileBarrier
{
    public function __construct(
        private readonly string $directory,
        private readonly int $pollIntervalMicroseconds = 10_000,
        private readonly float $timeoutSeconds = 5.0,
    ) {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0777, true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Unable to create barrier directory: {$this->directory}");
        }
    }

    public static function forRun(string $baseDirectory, string $runId, string $scenario): self
    {
        return new self($baseDirectory.DIRECTORY_SEPARATOR.$scenario.DIRECTORY_SEPARATOR.$runId);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Atomically signals that $role has reached the synchronization point.
     */
    public function signalReady(string $role): void
    {
        $this->writeAtomically($this->readyPath($role), (string) microtime(true));
    }

    /**
     * Blocks (bounded) until every role in $expectedRoles has signalled
     * ready. Called by the coordinator only.
     *
     * @param  list<string>  $expectedRoles
     */
    public function waitForAllReady(array $expectedRoles): void
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (microtime(true) < $deadline) {
            $allReady = true;

            foreach ($expectedRoles as $role) {
                if (! is_file($this->readyPath($role))) {
                    $allReady = false;

                    break;
                }
            }

            if ($allReady) {
                return;
            }

            usleep($this->pollIntervalMicroseconds);
        }

        $missing = array_values(array_filter(
            $expectedRoles,
            fn (string $role): bool => ! is_file($this->readyPath($role)),
        ));

        throw new RuntimeException('Barrier timeout: worker(s) never signalled ready: '.implode(', ', $missing));
    }

    /**
     * Atomically releases every worker blocked in waitForGo(). Called by
     * the coordinator only, and only after waitForAllReady() has returned.
     */
    public function release(): void
    {
        $this->writeAtomically($this->goPath(), (string) microtime(true));
    }

    /**
     * Blocks (bounded) until the coordinator has called release(). Called
     * by a worker only.
     */
    public function waitForGo(): void
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (is_file($this->goPath())) {
                return;
            }

            usleep($this->pollIntervalMicroseconds);
        }

        throw new RuntimeException('Barrier timeout: release signal never arrived.');
    }

    public function cleanup(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->directory);
    }

    private function readyPath(string $role): string
    {
        return $this->directory.DIRECTORY_SEPARATOR."ready-{$role}";
    }

    private function goPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'go';
    }

    private function writeAtomically(string $finalPath, string $contents): void
    {
        $tempPath = $finalPath.'.tmp-'.getmypid();
        file_put_contents($tempPath, $contents, LOCK_EX);
        rename($tempPath, $finalPath);
    }
}
