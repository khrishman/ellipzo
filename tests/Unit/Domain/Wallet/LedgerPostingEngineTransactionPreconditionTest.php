<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\ReversalRequest;
use App\Domain\Wallet\Services\AdministrativeAdjustmentWriteContext;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\LedgerWriteContext;
use App\Domain\Wallet\Services\ReversalRequestWriteContext;
use App\Models\User;
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

/**
 * Syntactically valid but otherwise arbitrary in-memory-only arguments -
 * every precondition test below fails before any entry, actor, or
 * account is ever inspected, so their exact values are irrelevant.
 *
 * @return list<PostLedgerEntryCommand>
 */
function makeAdjustmentPreconditionTestEntries(): array
{
    return [
        new PostLedgerEntryCommand(strtolower((string) Str::ulid()), LedgerEntryType::Debit, Money::fromAtomic(100, Currency::USD)),
        new PostLedgerEntryCommand(strtolower((string) Str::ulid()), LedgerEntryType::Credit, Money::fromAtomic(100, Currency::USD)),
    ];
}

function makeAdjustmentPreconditionTestActor(): User
{
    $actor = new User;
    $actor->exists = true;
    $actor->id = 1;

    return $actor;
}

test('writeAdministrativeAdjustmentWithinTransaction() rejects outside an active database transaction and leaves both write contexts inactive', function () {
    expect(DB::transactionLevel())->toBe(0);

    $engine = new LedgerPostingEngine;

    expect(fn () => $engine->writeAdministrativeAdjustmentWithinTransaction(
        LedgerTransactionType::AdministrativeAdjustment,
        'administrative_adjustment:'.strtolower((string) Str::ulid()),
        'Test adjustment',
        makeAdjustmentPreconditionTestActor(),
        (string) Str::uuid(),
        makeAdjustmentPreconditionTestEntries(),
    ))->toThrow(LedgerInvariantViolationException::class);

    expect(LedgerWriteContext::isActive())->toBeFalse();
    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
    expect(DB::transactionLevel())->toBe(0);
});

test('writeAdministrativeAdjustmentWithinTransaction() rejects without an active LedgerWriteContext even inside a real database transaction', function () {
    $engine = new LedgerPostingEngine;

    DB::beginTransaction();

    try {
        expect(fn () => $engine->writeAdministrativeAdjustmentWithinTransaction(
            LedgerTransactionType::AdministrativeAdjustment,
            'administrative_adjustment:'.strtolower((string) Str::ulid()),
            'Test adjustment',
            makeAdjustmentPreconditionTestActor(),
            (string) Str::uuid(),
            makeAdjustmentPreconditionTestEntries(),
        ))->toThrow(LedgerInvariantViolationException::class);

        expect(LedgerWriteContext::isActive())->toBeFalse();
        expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
    } finally {
        DB::rollBack();
    }

    expect(DB::transactionLevel())->toBe(0);
});

test('writeAdministrativeAdjustmentWithinTransaction() rejects without an active AdministrativeAdjustmentWriteContext even with LedgerWriteContext active', function () {
    $engine = new LedgerPostingEngine;

    DB::beginTransaction();

    try {
        LedgerWriteContext::run(function () use ($engine): void {
            expect(fn () => $engine->writeAdministrativeAdjustmentWithinTransaction(
                LedgerTransactionType::AdministrativeAdjustment,
                'administrative_adjustment:'.strtolower((string) Str::ulid()),
                'Test adjustment',
                makeAdjustmentPreconditionTestActor(),
                (string) Str::uuid(),
                makeAdjustmentPreconditionTestEntries(),
            ))->toThrow(LedgerInvariantViolationException::class);

            expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
        });
    } finally {
        DB::rollBack();
    }

    expect(DB::transactionLevel())->toBe(0);
});

test('writeAdministrativeAdjustmentWithinTransaction() rejects a transaction type other than AdministrativeAdjustment even with every other precondition satisfied', function () {
    $engine = new LedgerPostingEngine;

    DB::beginTransaction();

    try {
        LedgerWriteContext::run(function () use ($engine): void {
            AdministrativeAdjustmentWriteContext::run(function () use ($engine): void {
                expect(fn () => $engine->writeAdministrativeAdjustmentWithinTransaction(
                    LedgerTransactionType::DepositCredit,
                    'deposit_credit:'.strtolower((string) Str::ulid()),
                    'Test adjustment',
                    makeAdjustmentPreconditionTestActor(),
                    (string) Str::uuid(),
                    makeAdjustmentPreconditionTestEntries(),
                ))->toThrow(LedgerInvariantViolationException::class);
            });
        });
    } finally {
        DB::rollBack();
    }

    // No row-count re-query here: the :memory: SQLite database this
    // uses(TestCase::class) file boots is never migrated (no
    // RefreshDatabase), so a post-throw query would itself fail with "no
    // such table" - the precondition throw above is sufficient proof by
    // construction, matching the same reasoning already established for
    // WalletAccountProvisionerTransactionPreconditionTest.php.
    expect(DB::transactionLevel())->toBe(0);
});
