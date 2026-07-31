<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

/**
 * The identity guard's only output shape - `ok` plus one fixed, sanitized
 * reason code from a closed set (see ConcurrencyDatabaseIdentityGuard's own
 * REASON_* constants). Never carries a raw driver message, DSN, or
 * credential - the guard's whole purpose is to fail closed without leaking
 * anything about *why* in enough detail to be a diagnostic footgun.
 */
final readonly class ConcurrencyGuardResult
{
    public function __construct(
        public bool $ok,
        public string $reason,
    ) {}

    public static function pass(): self
    {
        return new self(true, ConcurrencyDatabaseIdentityGuard::REASON_OK);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
