<?php

use App\Domain\Wallet\Data\UserWalletAccounts;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * Direct proof that no allowlisted filter can ever duplicate a
 * LedgerTransaction row in the result set. Every filter in
 * AdminLedgerQuery::paginate() uses whereHas() (confirmed via
 * ->toSql() during this audit to compile to a correlated
 * "WHERE EXISTS (...)" subquery, never a JOIN), so a transaction with
 * several matching entries can only ever satisfy the EXISTS check once -
 * these tests prove that behavior end-to-end through the real HTTP
 * response, not just by inspecting the generated SQL.
 */
beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->staff = User::factory()->create();
    $this->staff->assignRole('administrator');
});

test('a transaction with two entries of the same filtered account type appears exactly once', function () {
    $provisioner = new WalletAccountProvisioner;
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $accountsA = $provisioner->provisionUserAccounts($userA);
    $accountsB = $provisioner->provisionUserAccounts($userB);
    $engine = new LedgerPostingEngine;

    // Funding userA's earning_available necessarily also touches
    // earning_available (it is the only way to increase a credit-normal
    // account's balance), so the assertion below counts both this
    // funding transaction and the cross-user transaction - the point
    // under test is that *neither* is duplicated, not that only one
    // transaction matches.
    $funding = $provisioner->providerClearingAccount('cardinality-same-type-clearing');
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:cardinality-same-type-fund', entries: [
        $this->debitEntry($funding->id, 10_000),
        $this->creditEntry($accountsA->earningAvailable->id, 10_000),
    ]));

    // Both entries touch an `earning_available` account - one debit, one
    // credit, across two different users' identically-typed accounts.
    // The EXISTS-based accountType filter matches on either leg; the
    // transaction itself must still appear exactly once, not twice.
    $crossUserTransaction = $engine->post($this->postingCommand(businessReference: 'deposit_credit:cardinality-same-type-1', entries: [
        $this->debitEntry($accountsA->earningAvailable->id, 10_000),
        $this->creditEntry($accountsB->earningAvailable->id, 10_000),
    ]))->transaction;

    $response = $this->actingAs($this->staff)->get('/admin/ledger?accountType=earning_available');

    // Two distinct transactions both legitimately match (funding +
    // cross-user) - neither is duplicated by the EXISTS-based filter.
    $data = $response->inertiaProps('transactions.data');
    expect($data)->toHaveCount(2);
    $ids = collect($data)->pluck('id')->all();
    expect(array_unique($ids))->toHaveCount(2);

    $crossUserRow = collect($data)->firstWhere('id', $crossUserTransaction->id);
    expect($crossUserRow)->not->toBeNull();
    expect($crossUserRow['entryCount'])->toBe(2);
});

test('a transaction touching two accounts of the same user appears exactly once when filtered by that user', function () {
    [$accounts, $user] = provisionRealUserForCardinality();
    $engine = new LedgerPostingEngine;

    $funding = (new WalletAccountProvisioner)->providerClearingAccount('cardinality-same-user-clearing');
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:cardinality-same-user-fund', entries: [
        $this->debitEntry($funding->id, 40_000),
        $this->creditEntry($accounts->earningAvailable->id, 40_000),
    ]));

    // Both legs of this transaction belong to the same user - the EXISTS
    // subquery matches on either the earningAvailable or the earningHeld
    // leg, but the transaction must still be counted once.
    $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:cardinality-same-user-1',
        entries: [
            $this->debitEntry($accounts->earningAvailable->id, 40_000),
            $this->creditEntry($accounts->earningHeld->id, 40_000),
        ],
    ));

    $response = $this->actingAs($this->staff)->get("/admin/ledger?userId={$user->id}&type=fund_reservation");

    $response->assertInertia(fn ($page) => $page->has('transactions.data', 1)->where('transactions.data.0.entryCount', 2));
});

test('a username filter matching two entries in one transaction still returns exactly one transaction', function () {
    [$accounts, $user] = provisionRealUserForCardinality();
    $user->profile()->create(['username' => 'cardinality_username_test']);
    $engine = new LedgerPostingEngine;

    $funding = (new WalletAccountProvisioner)->providerClearingAccount('cardinality-username-clearing');
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:cardinality-username-fund', entries: [
        $this->debitEntry($funding->id, 40_000),
        $this->creditEntry($accounts->advertisingAvailable->id, 40_000),
    ]));
    $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:cardinality-username-1',
        entries: [
            $this->debitEntry($accounts->advertisingAvailable->id, 40_000),
            $this->creditEntry($accounts->advertisingReserved->id, 40_000),
        ],
    ));

    $response = $this->actingAs($this->staff)->get('/admin/ledger?username=cardinality_username_test&type=fund_reservation');

    $response->assertInertia(fn ($page) => $page->has('transactions.data', 1));
});

test('cursor pagination has no duplicate or skipped transaction when every transaction has two matching entries', function () {
    [$accounts, $user] = provisionRealUserForCardinality();
    $engine = new LedgerPostingEngine;
    $funding = (new WalletAccountProvisioner)->providerClearingAccount('cardinality-cursor-clearing');

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:cardinality-cursor-fund', entries: [
        $this->debitEntry($funding->id, 1_000_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000_000),
    ]));

    $expectedIds = [];
    foreach (range(1, 27) as $i) {
        // Every one of these transactions has two entries that both
        // satisfy the accountType=earning_available filter's EXISTS
        // condition is not the case here (only one leg is
        // earning_available), but the *user* filter below matches both
        // legs (both belong to the same user), which is the genuinely
        // duplication-prone shape.
        $transaction = $engine->post($this->postingCommand(
            type: LedgerTransactionType::FundReservation,
            businessReference: "fund_reservation:cardinality-cursor-{$i}",
            entries: [
                $this->debitEntry($accounts->earningAvailable->id, 1_000),
                $this->creditEntry($accounts->earningHeld->id, 1_000),
            ],
        ))->transaction;
        $expectedIds[] = $transaction->id;
    }

    $page1 = $this->actingAs($this->staff)->get("/admin/ledger?userId={$user->id}&type=fund_reservation");
    $page1Ids = collect($page1->inertiaProps('transactions.data'))->pluck('id')->all();
    $nextCursor = $page1->inertiaProps('transactions.nextCursor');

    expect($page1Ids)->toHaveCount(25);

    $page2 = $this->actingAs($this->staff)->get("/admin/ledger?userId={$user->id}&type=fund_reservation&cursor={$nextCursor}");
    $page2Ids = collect($page2->inertiaProps('transactions.data'))->pluck('id')->all();

    expect($page2Ids)->toHaveCount(2);

    $allIds = array_merge($page1Ids, $page2Ids);
    expect($allIds)->toHaveCount(27);
    expect(array_unique($allIds))->toHaveCount(27);
    expect(collect($allIds)->sort()->values()->all())->toBe(collect($expectedIds)->sort()->values()->all());
});

/**
 * @return array{0: UserWalletAccounts, 1: User}
 */
function provisionRealUserForCardinality(): array
{
    $user = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);

    return [$accounts, $user];
}
