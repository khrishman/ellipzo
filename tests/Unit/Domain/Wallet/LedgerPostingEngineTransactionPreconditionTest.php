<?php

use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\ReversalRequest;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\LedgerWriteContext;
use App\Domain\Wallet\Services\ReversalRequestWriteContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deliberately placed under tests/Unit, not tests/Feature/Domain/Wallet:
 * tests/Pest.php binds RefreshDatabase to the entire Feature directory,
 * and RefreshDatabase wraps every test in its own ambient database
 * transaction (RefreshDatabase::beginDatabaseTransaction()), which makes
 * DB::transactionLevel() >= 1 throughout any test placed there - the
 * "requires an active database transaction" precondition genuinely
 * cannot be observed as false from inside that wrapper. Binding
 * Tests\TestCase directly here (no RefreshDatabase) boots the real
 * Laravel application - so the DB facade resolves and
 * DB::transactionLevel() is meaningful - without opening any transaction
 * or requiring migrated tables, since the precondition check is the
 * first thing writeReversalEntriesWithinTransaction() does and every
 * other precondition, and every actual query, is unreachable once it
 * throws.
 */
uses(TestCase::class);

test('writeReversalEntriesWithinTransaction() rejects outside an active database transaction, writes nothing, and leaves both write contexts inactive', function () {
    expect(DB::transactionLevel())->toBe(0);

    $engine = new LedgerPostingEngine;

    // Safe, unsaved model arguments only - the precondition check must
    // fail before either model's own fields are ever inspected or any
    // database query is attempted, so their exact values are irrelevant.
    $original = new LedgerTransaction;
    $original->id = strtolower((string) Str::ulid());

    $request = new ReversalRequest;
    $request->original_ledger_transaction_id = $original->id;
    $request->status = ReversalRequestStatus::Pending;
    // $request->exists is deliberately left false (never saved).

    expect(fn () => $engine->writeReversalEntriesWithinTransaction($request, $original))
        ->toThrow(LedgerInvariantViolationException::class);

    expect(LedgerWriteContext::isActive())->toBeFalse();
    expect(ReversalRequestWriteContext::isActive())->toBeFalse();
    expect(DB::transactionLevel())->toBe(0);
});
