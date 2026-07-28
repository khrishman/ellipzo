<?php

use App\Domain\Wallet\Exceptions\InvalidWalletAccountScopeException;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deliberately placed under tests/Unit, not tests/Feature: RefreshDatabase
 * wraps every Feature test in its own ambient database transaction
 * (RefreshDatabase::beginDatabaseTransaction()), which makes
 * DB::transactionLevel() >= 1 throughout any test placed there - the
 * "requires an active database transaction" precondition genuinely
 * cannot be observed as false from inside that wrapper. Binding
 * Tests\TestCase directly here (no RefreshDatabase) boots the real
 * application without opening any transaction, matching the same
 * pattern already established for
 * LedgerPostingEngineTransactionPreconditionTest.php.
 */
uses(TestCase::class);

test('provisionUserAccountsWithinTransaction() rejects use outside an active database transaction', function () {
    expect(DB::transactionLevel())->toBe(0);

    $provisioner = new WalletAccountProvisioner;
    $user = new User;
    $user->exists = true;
    $user->id = 999999;

    // The precondition check happens before any query, so a failure here
    // proves - by construction, not by re-querying a possibly-unmigrated
    // database - that nothing was ever attempted.
    expect(fn () => $provisioner->provisionUserAccountsWithinTransaction($user))
        ->toThrow(InvalidWalletAccountScopeException::class);

    expect(DB::transactionLevel())->toBe(0);
});
