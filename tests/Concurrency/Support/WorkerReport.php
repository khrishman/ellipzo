<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

use JsonException;
use RuntimeException;

/**
 * The one and only structured line a worker process ever writes to stdout.
 * A closed, explicit field set - scenario/role/pid/mysql connection id/
 * timestamps/outcome/committed transaction id, plus a small allowlisted
 * "extra" map for scenario-specific IDs (e.g. a reversal request ID). Never
 * carries a raw exception message, SQL string, credential, or DSN -
 * unexpected failures are classified and only their exception *class name*
 * is recorded, never getMessage().
 */
final readonly class WorkerReport
{
    /**
     * @param  array<string, string>  $extra
     */
    public function __construct(
        public string $scenario,
        public string $role,
        public int $pid,
        public int $mysqlConnectionId,
        public float $tBefore,
        public float $tAfter,
        public ConcurrencyOutcomeCategory $outcome,
        public ?string $committedTransactionId,
        public array $extra = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scenario' => $this->scenario,
            'role' => $this->role,
            'pid' => $this->pid,
            'mysqlConnectionId' => $this->mysqlConnectionId,
            'tBefore' => $this->tBefore,
            'tAfter' => $this->tAfter,
            'outcome' => $this->outcome->value,
            'committedTransactionId' => $this->committedTransactionId,
            'extra' => $this->extra,
        ];
    }

    public function toJsonLine(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJsonLine(string $line): self
    {
        try {
            $data = json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Worker report line was not valid JSON.', previous: $exception);
        }

        if (! is_array($data)) {
            throw new RuntimeException('Worker report line did not decode to an object.');
        }

        $outcome = ConcurrencyOutcomeCategory::tryFrom((string) ($data['outcome'] ?? ''));

        if ($outcome === null) {
            throw new RuntimeException('Worker report contained an unrecognized outcome category.');
        }

        return new self(
            scenario: (string) ($data['scenario'] ?? ''),
            role: (string) ($data['role'] ?? ''),
            pid: (int) ($data['pid'] ?? 0),
            mysqlConnectionId: (int) ($data['mysqlConnectionId'] ?? 0),
            tBefore: (float) ($data['tBefore'] ?? 0.0),
            tAfter: (float) ($data['tAfter'] ?? 0.0),
            outcome: $outcome,
            committedTransactionId: isset($data['committedTransactionId']) && $data['committedTransactionId'] !== null
                ? (string) $data['committedTransactionId']
                : null,
            extra: is_array($data['extra'] ?? null)
                ? array_map(strval(...), $data['extra'])
                : [],
        );
    }

    /**
     * Extracts the report from a worker's full stdout (which may contain
     * other noise, e.g. a stray framework warning on a line of its own) by
     * taking the last non-blank line - the worker script's own contract is
     * to always emit the report as its final output.
     */
    public static function fromProcessOutput(string $stdout): self
    {
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $stdout)),
            fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            throw new RuntimeException('Worker process produced no output.');
        }

        return self::fromJsonLine($lines[count($lines) - 1]);
    }
}
