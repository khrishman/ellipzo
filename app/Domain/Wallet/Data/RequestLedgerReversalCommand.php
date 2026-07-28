<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Data;

use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A fully validated, normalized instruction to request a reversal of one
 * ledger transaction. Immutable after construction - every invariant this
 * class can prove without touching the database (input shape, charset,
 * length bounds, canonical casing) is proven here, before
 * ReversalRequestService ever opens a transaction. Normalization mirrors
 * PostLedgerTransactionCommand's own established rule shapes so the same
 * value is always canonicalized the same way across the wallet domain.
 */
final readonly class RequestLedgerReversalCommand
{
    public string $originalTransactionId;

    public string $idempotencyKey;

    public string $correlationId;

    public string $reason;

    public ?int $actorId;

    public function __construct(
        mixed $originalTransactionId,
        mixed $idempotencyKey,
        mixed $correlationId,
        mixed $reason,
        ?User $actor,
    ) {
        if (! is_string($originalTransactionId)) {
            throw new LedgerInvariantViolationException('Original transaction ID must be a string.');
        }

        if (! is_string($idempotencyKey)) {
            throw new LedgerInvariantViolationException('Idempotency key must be a string.');
        }

        if (! is_string($correlationId)) {
            throw new LedgerInvariantViolationException('Correlation ID must be a string.');
        }

        if (! is_string($reason)) {
            throw new LedgerInvariantViolationException('Reason must be a string.');
        }

        if ($actor !== null && (! $actor->exists || $actor->getKey() === null || $actor->getKey() <= 0)) {
            throw new LedgerInvariantViolationException('The actor must be a persisted user with a valid identifier.');
        }

        $this->originalTransactionId = self::normalizeOriginalTransactionId($originalTransactionId);
        $this->idempotencyKey = self::normalizeIdempotencyKey($idempotencyKey);
        $this->correlationId = self::normalizeCorrelationId($correlationId);
        $this->reason = self::normalizeReason($reason);
        $this->actorId = $actor?->getKey();
    }

    private static function normalizeOriginalTransactionId(string $value): string
    {
        $trimmed = trim($value);

        if (! Str::isUlid($trimmed)) {
            throw new LedgerInvariantViolationException('Original transaction ID must be a valid ULID.');
        }

        // HasUlids::newUniqueId() stores strtolower((string) Str::ulid()) -
        // confirmed from its own source - so lowercase is the true
        // canonical representation.
        return strtolower($trimmed);
    }

    private static function normalizeIdempotencyKey(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 191) {
            throw new LedgerInvariantViolationException('Idempotency key length is out of bounds.');
        }

        $normalized = strtolower($trimmed);

        if (preg_match('/^[a-z0-9:_-]+$/', $normalized) !== 1) {
            throw new LedgerInvariantViolationException('Idempotency key contains invalid characters.');
        }

        return $normalized;
    }

    private static function normalizeCorrelationId(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 36) {
            throw new LedgerInvariantViolationException('Correlation ID length is out of bounds.');
        }

        if (Str::isUlid($trimmed)) {
            return strtolower($trimmed);
        }

        if (Str::isUuid($trimmed)) {
            return strtolower($trimmed);
        }

        throw new LedgerInvariantViolationException('Correlation ID must be a canonical UUID or ULID.');
    }

    private static function normalizeReason(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 255) {
            throw new LedgerInvariantViolationException('Reason length is out of bounds.');
        }

        // Rejects every ASCII control character, including \n, \r, \t - a
        // single check covers both "no control characters" and "no
        // multiline content" at once.
        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            throw new LedgerInvariantViolationException('Reason contains invalid characters.');
        }

        return $trimmed; // meaningful casing preserved deliberately
    }
}
