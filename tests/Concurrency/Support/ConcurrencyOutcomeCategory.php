<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

/**
 * The closed set of outcomes a worker's contested operation can report.
 * Every scenario's assertions are written against this enum, never a raw
 * exception class name or message string.
 */
enum ConcurrencyOutcomeCategory: string
{
    case Created = 'created';
    case Replay = 'replay';
    case InsufficientBalance = 'insufficient_balance';
    case DuplicateEvent = 'duplicate_event';
    case ConflictingRequest = 'conflicting_request';
    case ReviewRequired = 'review_required';
    case LockTimeout = 'lock_timeout';
    case Deadlock = 'deadlock';
    case UnexpectedFailure = 'unexpected_failure';
}
