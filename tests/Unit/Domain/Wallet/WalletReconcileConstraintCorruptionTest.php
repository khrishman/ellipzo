<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deliberately placed under tests/Unit, not tests/Feature: RefreshDatabase
 * wraps every Feature test in its own ambient database transaction, and
 * SQLite's own PRAGMA foreign_keys is a documented no-op when there is
 * already an open transaction ("This pragma is a no-op within a
 * transaction; foreign key constraint enforcement may only be enabled or
 * disabled when there is no pending BEGIN...COMMIT" - SQLite's own
 * documentation). Toggling it inside that ambient transaction would
 * silently do nothing: the "corruption" insert would then fail with a
 * real FK violation (masking the intended test), or - worse - some other
 * change could appear to work while the restore-in-finally never
 * actually restores anything, since nothing real ever changed. Binding
 * Tests\TestCase directly here (no RefreshDatabase) guarantees no
 * transaction is open when the pragma is toggled.
 *
 * Every test below: confirms the current constraint state before
 * changing it, changes it, performs exactly one corruption insert,
 * restores the original state in a finally block (so restoration
 * happens even if an assertion throws), and then proves the restoration
 * is real - not merely reported - by attempting a genuine violation
 * afterward and confirming it is still rejected. No test here ever
 * leaves global state altered for the rest of the suite.
 */
uses(TestCase::class);

beforeEach(function (): void {
    // Idempotent - skipped for already-run migrations. Needed for this
    // file to be self-contained when run in isolation, since it has no
    // RefreshDatabase of its own to trigger the initial migration on the
    // shared :memory: SQLite connection.
    Artisan::call('migrate', ['--force' => true]);
});

function currentForeignKeyState(): int
{
    return (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
}

function insertRawWalletAccountForConstraintTest(): string
{
    $id = strtolower((string) Str::ulid());

    DB::table('wallet_accounts')->insert([
        'id' => $id,
        'scope_type' => 'platform',
        'scope_key' => 'ellipzo-constraint-test-'.Str::random(8),
        'user_id' => null,
        'account_type' => 'platform_fee',
        'currency_code' => 'USD',
        'currency_scale' => 6,
        'created_at' => now(),
    ]);

    return $id;
}

test('a genuinely orphaned ledger entry with no parent transaction is detected, and foreign-key enforcement is fully restored', function () {
    expect(DB::transactionLevel())->toBe(0);

    $originalState = currentForeignKeyState();
    expect($originalState)->toBe(1);

    $accountId = insertRawWalletAccountForConstraintTest();
    $orphanEntryId = strtolower((string) Str::ulid());
    $danglingTransactionId = strtolower((string) Str::ulid());

    try {
        DB::statement('PRAGMA foreign_keys = OFF');
        expect(currentForeignKeyState())->toBe(0);

        DB::table('ledger_entries')->insert([
            'id' => $orphanEntryId,
            'ledger_transaction_id' => $danglingTransactionId,
            'wallet_account_id' => $accountId,
            'entry_type' => 'credit',
            'amount_atomic' => 100,
            'created_at' => now(),
        ]);

        Artisan::call('wallet:reconcile');
        $output = Artisan::output();

        expect($output)->toContain("ENTRY_TRANSACTION_MISSING ledger_entry {$orphanEntryId}");
    } finally {
        DB::statement('PRAGMA foreign_keys = ON');
    }

    expect(currentForeignKeyState())->toBe($originalState);

    expect(fn () => DB::table('ledger_entries')->insert([
        'id' => strtolower((string) Str::ulid()),
        'ledger_transaction_id' => strtolower((string) Str::ulid()),
        'wallet_account_id' => $accountId,
        'entry_type' => 'credit',
        'amount_atomic' => 100,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a genuinely orphaned ledger entry with no parent wallet account is detected, and foreign-key enforcement is fully restored', function () {
    expect(DB::transactionLevel())->toBe(0);

    $originalState = currentForeignKeyState();
    expect($originalState)->toBe(1);

    DB::table('ledger_transactions')->insert([
        'id' => $transactionId = strtolower((string) Str::ulid()),
        'business_reference' => 'test-ref-'.Str::random(16),
        'type' => 'deposit_credit',
        'currency_code' => 'USD',
        'currency_scale' => 6,
        'description' => 'Test transaction',
        'actor_id' => null,
        'related_entity_type' => null,
        'related_entity_id' => null,
        'correlation_id' => (string) Str::uuid(),
        'reverses_transaction_id' => null,
        'created_at' => now(),
    ]);

    $orphanEntryId = strtolower((string) Str::ulid());
    $danglingAccountId = strtolower((string) Str::ulid());

    try {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::table('ledger_entries')->insert([
            'id' => $orphanEntryId,
            'ledger_transaction_id' => $transactionId,
            'wallet_account_id' => $danglingAccountId,
            'entry_type' => 'credit',
            'amount_atomic' => 100,
            'created_at' => now(),
        ]);

        Artisan::call('wallet:reconcile');
        $output = Artisan::output();

        expect($output)->toContain("ENTRY_ACCOUNT_MISSING ledger_entry {$orphanEntryId}");
    } finally {
        DB::statement('PRAGMA foreign_keys = ON');
    }

    expect(currentForeignKeyState())->toBe($originalState);

    expect(fn () => DB::table('ledger_entries')->insert([
        'id' => strtolower((string) Str::ulid()),
        'ledger_transaction_id' => $transactionId,
        'wallet_account_id' => strtolower((string) Str::ulid()),
        'entry_type' => 'credit',
        'amount_atomic' => 100,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a reversal request whose claimed original transaction does not exist is detected, and foreign-key enforcement is fully restored', function () {
    expect(DB::transactionLevel())->toBe(0);

    $originalState = currentForeignKeyState();

    $requestId = strtolower((string) Str::ulid());
    $danglingOriginalId = strtolower((string) Str::ulid());

    try {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::table('reversal_requests')->insert([
            'id' => $requestId,
            'original_ledger_transaction_id' => $danglingOriginalId,
            'reversal_transaction_id' => null,
            'idempotency_key' => 'test-idempotency-'.Str::random(16),
            'fingerprint' => str_repeat('a', 64),
            'status' => 'pending',
            'actor_id' => null,
            'reason' => 'Raw test reversal request',
            'correlation_id' => (string) Str::uuid(),
            'failure_code' => null,
            'applied_at' => null,
            'review_required_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('wallet:reconcile');
        $output = Artisan::output();

        expect($output)->toContain("REVERSAL_ORIGINAL_MISSING reversal_request {$requestId}");
    } finally {
        DB::statement('PRAGMA foreign_keys = ON');
    }

    expect(currentForeignKeyState())->toBe($originalState);

    expect(fn () => DB::table('reversal_requests')->insert([
        'id' => strtolower((string) Str::ulid()),
        'original_ledger_transaction_id' => strtolower((string) Str::ulid()),
        'reversal_transaction_id' => null,
        'idempotency_key' => 'test-idempotency-'.Str::random(16),
        'fingerprint' => str_repeat('a', 64),
        'status' => 'pending',
        'actor_id' => null,
        'reason' => 'Raw test reversal request',
        'correlation_id' => (string) Str::uuid(),
        'failure_code' => null,
        'applied_at' => null,
        'review_required_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a snapshot whose stored cutoff entry no longer exists is detected, and foreign-key enforcement is fully restored', function () {
    expect(DB::transactionLevel())->toBe(0);

    $originalState = currentForeignKeyState();

    $accountId = insertRawWalletAccountForConstraintTest();
    $snapshotId = strtolower((string) Str::ulid());
    $danglingEntryId = strtolower((string) Str::ulid());

    try {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::table('balance_snapshots')->insert([
            'id' => $snapshotId,
            'wallet_account_id' => $accountId,
            'balance_atomic' => 100,
            'currency_code' => 'USD',
            'currency_scale' => 6,
            'cutoff_ledger_entry_id' => $danglingEntryId,
            'cutoff_entry_created_at' => now(),
            'entry_count' => 1,
            'fingerprint_version' => 1,
            'fingerprint' => str_repeat('a', 64),
            'created_at' => now(),
        ]);

        Artisan::call('wallet:reconcile');
        $output = Artisan::output();

        expect($output)->toContain("SNAPSHOT_CUTOFF_MISSING balance_snapshot {$snapshotId}");
    } finally {
        DB::statement('PRAGMA foreign_keys = ON');
    }

    expect(currentForeignKeyState())->toBe($originalState);

    expect(fn () => DB::table('balance_snapshots')->insert([
        'id' => strtolower((string) Str::ulid()),
        'wallet_account_id' => $accountId,
        'balance_atomic' => 100,
        'currency_code' => 'USD',
        'currency_scale' => 6,
        'cutoff_ledger_entry_id' => strtolower((string) Str::ulid()),
        'cutoff_entry_created_at' => now(),
        'entry_count' => 1,
        'fingerprint_version' => 1,
        'fingerprint' => str_repeat('a', 64),
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('two audit events for the same entity/action is detected, and the unique index is fully restored', function () {
    expect(DB::transactionLevel())->toBe(0);

    $actorId = DB::table('users')->insertGetId([
        'name' => 'Constraint Test User',
        'email' => 'constraint-test-'.Str::random(8).'@example.com',
        'password' => 'irrelevant',
        'account_status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A real administrative_adjustment transaction with balanced entries -
    // checkAdjustmentTransaction() only iterates real transactions of
    // this type, so the duplicate-audit branch is only reachable once one
    // exists to match against.
    $targetAccountId = insertRawWalletAccountForConstraintTest();
    $suspenseAccountId = insertRawWalletAccountForConstraintTest();
    $entityKey = strtolower((string) Str::ulid());

    DB::table('ledger_transactions')->insert([
        'id' => $entityKey,
        'business_reference' => 'administrative_adjustment:'.Str::random(16),
        'type' => 'administrative_adjustment',
        'currency_code' => 'USD',
        'currency_scale' => 6,
        'description' => 'Test adjustment',
        'actor_id' => $actorId,
        'related_entity_type' => null,
        'related_entity_id' => null,
        'correlation_id' => (string) Str::uuid(),
        'reverses_transaction_id' => null,
        'created_at' => now(),
    ]);
    DB::table('ledger_entries')->insert([
        'id' => strtolower((string) Str::ulid()),
        'ledger_transaction_id' => $entityKey,
        'wallet_account_id' => $targetAccountId,
        'entry_type' => 'credit',
        'amount_atomic' => 500,
        'created_at' => now(),
    ]);
    DB::table('ledger_entries')->insert([
        'id' => strtolower((string) Str::ulid()),
        'ledger_transaction_id' => $entityKey,
        'wallet_account_id' => $suspenseAccountId,
        'entry_type' => 'debit',
        'amount_atomic' => 500,
        'created_at' => now(),
    ]);

    $firstAuditId = null;
    $secondAuditId = null;

    try {
        DB::statement('DROP INDEX audit_events_entity_key_action_unique');

        $firstAuditId = DB::table('audit_events')->insertGetId([
            'actor_id' => $actorId,
            'action' => 'ledger.administrative_adjustment',
            'entity_type' => 'ledger_transaction',
            'entity_key' => $entityKey,
            'before_state' => json_encode([]),
            'after_state' => json_encode([]),
            'reason' => 'First duplicate',
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        $secondAuditId = DB::table('audit_events')->insertGetId([
            'actor_id' => $actorId,
            'action' => 'ledger.administrative_adjustment',
            'entity_type' => 'ledger_transaction',
            'entity_key' => $entityKey,
            'before_state' => json_encode([]),
            'after_state' => json_encode([]),
            'reason' => 'Second duplicate',
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        Artisan::call('wallet:reconcile');
        $output = Artisan::output();

        expect($output)->toContain("ADJUSTMENT_AUDIT_DUPLICATE ledger_transaction {$entityKey}");
    } finally {
        // The duplicate rows this test itself created must be cleaned up
        // before the unique index can be recreated - recreating it while
        // they still exist would fail with the very violation the index
        // is meant to prevent.
        if ($secondAuditId !== null) {
            DB::table('audit_events')->where('id', $secondAuditId)->delete();
        }
        DB::statement('CREATE UNIQUE INDEX audit_events_entity_key_action_unique ON audit_events (entity_type, entity_key, action)');
    }

    // Prove restoration is real: a genuine duplicate must be rejected again.
    expect(fn () => DB::table('audit_events')->insert([
        'actor_id' => $actorId,
        'action' => 'ledger.administrative_adjustment',
        'entity_type' => 'ledger_transaction',
        'entity_key' => $entityKey,
        'before_state' => json_encode([]),
        'after_state' => json_encode([]),
        'reason' => 'Third duplicate attempt',
        'correlation_id' => (string) Str::uuid(),
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});
