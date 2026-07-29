<?php

use App\Domain\Wallet\Data\UserWalletAccounts;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Services\AdministrativeAdjustmentService;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\ReversalRequestService;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: User, 1: UserWalletAccounts}
 */
function makeUserWithAccounts(): array
{
    $user = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);

    return [$user, $accounts];
}

// ---------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------

test('a guest is redirected to login before reaching the page', function () {
    $this->get('/transactions')->assertRedirect(route('login', absolute: false));
});

test('an unverified user is redirected to the verification prompt', function () {
    $user = User::factory()->unverified()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($user);

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertRedirect(route('verification.notice', absolute: false));
});

test('a suspended user is redirected to the restricted page', function () {
    $user = User::factory()->suspended()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($user);

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertRedirect(route('account.restricted', absolute: false));
});

test('a closed user is redirected to the restricted page', function () {
    $user = User::factory()->closed()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($user);

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertRedirect(route('account.restricted', absolute: false));
});

test('an active authenticated user reaches the page normally', function () {
    [$user] = makeUserWithAccounts();

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('wallet/transactions/index'));
});

// ---------------------------------------------------------------------
// Balances
// ---------------------------------------------------------------------

test('exactly the four user account balances are returned, derived from ledger entries and never balance_snapshots', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-balance-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-balance-1', entries: [
        $this->debitEntry($clearing->id, 700_000),
        $this->creditEntry($accounts->earningAvailable->id, 700_000),
    ]));

    // A wildly wrong, stale snapshot for the same account - proven to have
    // zero effect on the displayed balance, since LedgerBalanceReader never
    // reads balance_snapshots at all.
    $this->insertRawBalanceSnapshot([
        'wallet_account_id' => $accounts->earningAvailable->id,
        'balance_atomic' => 999_999_999,
    ]);

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertInertia(
        fn ($page) => $page
            ->where('balances.earning_available.atomic', '700000')
            ->where('balances.earning_available.formatted', '0.700000')
            ->where('balances.earning_available.currency', 'USD')
            ->where('balances.earning_held.formatted', '0.000000')
            ->where('balances.advertising_available.formatted', '0.000000')
            ->where('balances.advertising_reserved.formatted', '0.000000'),
    );
});

test('an empty history returns four honest zero balances', function () {
    [$user] = makeUserWithAccounts();

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertInertia(
        fn ($page) => $page
            ->where('balances.earning_available.formatted', '0.000000')
            ->where('balances.earning_held.formatted', '0.000000')
            ->where('balances.advertising_available.formatted', '0.000000')
            ->where('balances.advertising_reserved.formatted', '0.000000')
            ->where('transactions.data', [])
            ->where('availableTransactionTypes', [])
            ->has('accountOptions', 4),
    );
});

// ---------------------------------------------------------------------
// Ownership, privacy, and movement representation
// ---------------------------------------------------------------------

test('a counterparty entry is never loaded or serialized, only the authenticated user’s own movement', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-counterparty-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-counterparty-1', entries: [
        $this->debitEntry($clearing->id, 250_000),
        $this->creditEntry($accounts->earningAvailable->id, 250_000),
    ]));

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertInertia(
        fn ($page) => $page
            ->has('transactions.data', 1)
            ->has('transactions.data.0.movements', 1)
            ->where('transactions.data.0.movements.0.accountType', 'earning_available'),
    );
    expect($response->getContent())->not->toContain($clearing->id);
});

test('another user’s transaction never appears in the authenticated user’s history', function () {
    [$user] = makeUserWithAccounts();
    [, $otherAccounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-cross-user-clearing');
    $engine = new LedgerPostingEngine;

    $otherTransaction = $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-cross-user-1', entries: [
        $this->debitEntry($clearing->id, 100_000),
        $this->creditEntry($otherAccounts->earningAvailable->id, 100_000),
    ]))->transaction;

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertInertia(fn ($page) => $page->where('transactions.data', []));
    expect($response->getContent())->not->toContain($otherTransaction->id);
    expect($response->getContent())->not->toContain($otherAccounts->earningAvailable->id);
});

test('a multi-movement transaction shows every movement belonging to the authenticated user', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-multi-movement-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-multi-movement-fund', entries: [
        $this->debitEntry($clearing->id, 40_000),
        $this->creditEntry($accounts->earningAvailable->id, 40_000),
    ]));
    $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:history-multi-movement-1',
        entries: [
            $this->debitEntry($accounts->earningAvailable->id, 40_000),
            $this->creditEntry($accounts->earningHeld->id, 40_000),
        ],
    ));

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertInertia(
        fn ($page) => $page
            ->has('transactions.data', 2)
            // created_at/id DESC - the reservation (posted second) is first.
            ->has('transactions.data.0.movements', 2)
            ->where('transactions.data.0.movements.0.accountType', 'earning_available')
            ->where('transactions.data.0.movements.0.direction', 'decrease')
            ->where('transactions.data.0.movements.1.accountType', 'earning_held')
            ->where('transactions.data.0.movements.1.direction', 'increase'),
    );
});

test('increase and decrease direction is correct for both sides of a posting', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-direction-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-direction-fund', entries: [
        $this->debitEntry($clearing->id, 500_000),
        $this->creditEntry($accounts->earningAvailable->id, 500_000),
    ]));

    $engine->post($this->postingCommand(
        type: LedgerTransactionType::WithdrawalHold,
        businessReference: 'withdrawal_hold:history-direction-withdraw',
        entries: [
            $this->debitEntry($accounts->earningAvailable->id, 200_000),
            $this->creditEntry($clearing->id, 200_000),
        ],
    ));

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertInertia(
        fn ($page) => $page
            ->has('transactions.data', 2)
            // created_at/id DESC - the withdrawal (posted second) is first.
            ->where('transactions.data.0.movements.0.direction', 'decrease')
            ->where('transactions.data.1.movements.0.direction', 'increase'),
    );
});

test('internal metadata never appears anywhere in the response', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $adjustmentActor = $this->ledgerAdjustActor();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-metadata-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(
        businessReference: 'deposit_credit:history-metadata-1',
        correlationId: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        entries: [
            $this->debitEntry($clearing->id, 100_000),
            $this->creditEntry($accounts->earningAvailable->id, 100_000),
        ],
    ));

    app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand(
        $adjustmentActor,
        $user,
        internalReason: 'Staff-only-internal-reason-must-never-render',
    ));

    $content = $this->actingAs($user)->get('/transactions')->getContent();

    expect($content)->not->toContain('Staff-only-internal-reason-must-never-render');
    expect($content)->not->toContain('correlation_id');
    expect($content)->not->toContain('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
    expect($content)->not->toContain('business_reference');
    expect($content)->not->toContain('audit_events');
    expect($content)->not->toContain($adjustmentActor->email);
    expect($content)->not->toContain($adjustmentActor->name);
});

test('an administrative adjustment’s user-visible description appears while its internal reason never does', function () {
    [$user] = makeUserWithAccounts();
    $adjustmentActor = $this->ledgerAdjustActor();

    app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand(
        $adjustmentActor,
        $user,
        internalReason: 'Correcting a support-verified reward calculation error.',
        userVisibleDescription: 'We adjusted your balance to correct a reward calculation issue.',
    ));

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertInertia(
        fn ($page) => $page
            ->where('transactions.data.0.type', 'administrative_adjustment')
            ->where('transactions.data.0.detail', 'We adjusted your balance to correct a reward calculation issue.'),
    );
    expect($response->getContent())->not->toContain('Correcting a support-verified reward calculation error.');
});

test('a reversal’s description is never exposed as detail, even though it is staff-facing reason text', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-reversal-clearing');
    $engine = new LedgerPostingEngine;

    $original = $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-reversal-1', entries: [
        $this->debitEntry($clearing->id, 300_000),
        $this->creditEntry($accounts->earningAvailable->id, 300_000),
    ]))->transaction;

    (new ReversalRequestService($engine))->requestAndExecute(
        $this->reversalCommand($original->id, reason: 'Staff-only-reversal-reason-must-never-render'),
    );

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertInertia(
        fn ($page) => $page
            ->has('transactions.data', 2)
            ->where('transactions.data.0.type', 'reversal')
            ->where('transactions.data.0.detail', null),
    );
    expect($response->getContent())->not->toContain('Staff-only-reversal-reason-must-never-render');
});

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------

test('account filtering narrows transactions but still shows every movement belonging to the authenticated user', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-filter-account-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-filter-account-fund-earning', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]));
    $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:history-filter-account-1',
        entries: [
            $this->debitEntry($accounts->earningAvailable->id, 10_000),
            $this->creditEntry($accounts->earningHeld->id, 10_000),
        ],
    ));
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-filter-account-fund-advertising', entries: [
        $this->debitEntry($clearing->id, 5_000),
        $this->creditEntry($accounts->advertisingAvailable->id, 5_000),
    ]));
    $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:history-filter-account-2',
        entries: [
            $this->debitEntry($accounts->advertisingAvailable->id, 5_000),
            $this->creditEntry($accounts->advertisingReserved->id, 5_000),
        ],
    ));

    $response = $this->actingAs($user)->get('/transactions?account=earning_available');

    $response->assertInertia(
        fn ($page) => $page
            ->where('filters.account', 'earning_available')
            // Only the two transactions touching earning_available qualify -
            // the advertising pair (posted just as recently) is excluded.
            ->has('transactions.data', 2)
            ->where('transactions.data.0.type', 'fund_reservation')
            ->has('transactions.data.0.movements', 2)
            ->where('transactions.data.0.movements.0.accountType', 'earning_available')
            ->where('transactions.data.0.movements.1.accountType', 'earning_held')
            ->where('transactions.data.1.type', 'deposit_credit')
            ->has('transactions.data.1.movements', 1),
    );
});

test('transaction-type filtering returns only matching transactions', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-filter-type-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-filter-type-1', entries: [
        $this->debitEntry($clearing->id, 100_000),
        $this->creditEntry($accounts->earningAvailable->id, 100_000),
    ]));
    $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:history-filter-type-2',
        entries: [
            $this->debitEntry($accounts->earningAvailable->id, 10_000),
            $this->creditEntry($accounts->earningHeld->id, 10_000),
        ],
    ));

    $response = $this->actingAs($user)->get('/transactions?type=deposit_credit');

    $response->assertInertia(
        fn ($page) => $page
            ->where('filters.type', 'deposit_credit')
            ->has('transactions.data', 1)
            ->where('transactions.data.0.type', 'deposit_credit'),
    );
});

test('available transaction types come only from the authenticated user’s own real history', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-available-types-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-available-types-1', entries: [
        $this->debitEntry($clearing->id, 100_000),
        $this->creditEntry($accounts->earningAvailable->id, 100_000),
    ]));

    $response = $this->actingAs($user)->get('/transactions');

    $response->assertInertia(
        fn ($page) => $page->where('availableTransactionTypes', [
            ['value' => 'deposit_credit', 'label' => 'Deposit'],
        ]),
    );
});

test('an unknown scalar filter value is rejected with Laravel’s normal validation redirect', function () {
    [$user] = makeUserWithAccounts();

    $response = $this->actingAs($user)->get('/transactions?account=platform_fee');

    $response->assertStatus(302);
    $response->assertSessionHasErrors('account');
});

test('an array-shaped filter value is rejected with Laravel’s normal validation redirect', function () {
    [$user] = makeUserWithAccounts();

    $response = $this->actingAs($user)->get('/transactions?account[]=earning_available');

    $response->assertStatus(302);
    $response->assertSessionHasErrors('account');
});

test('an unknown transaction type filter value is rejected with a validation redirect', function () {
    [$user] = makeUserWithAccounts();

    $response = $this->actingAs($user)->get('/transactions?type=not_a_real_type');

    $response->assertStatus(302);
    $response->assertSessionHasErrors('type');
});

// ---------------------------------------------------------------------
// Cursor pagination
// ---------------------------------------------------------------------

test('a malformed cursor redirects canonically to the page without a cursor, preserving valid filters', function () {
    [$user] = makeUserWithAccounts();

    $response = $this->actingAs($user)->get('/transactions?account=earning_available&cursor=not-a-real-cursor!!!');

    $response->assertRedirect(route('transactions.index', ['account' => 'earning_available'], absolute: false));
});

test('a structurally-valid but wrong-shaped cursor also redirects canonically instead of erroring', function () {
    [$user] = makeUserWithAccounts();

    $tamperedCursor = Cursor::fromEncoded(base64_encode(json_encode([
        '_pointsToNextItems' => true,
        'unrelated_field' => 'zzz',
    ])))->encode();

    $response = $this->actingAs($user)->get('/transactions?cursor='.$tamperedCursor);

    $response->assertRedirect(route('transactions.index', [], absolute: false));
});

test('cursor pagination walks the full history with no duplicate or skipped transaction', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-cursor-walk-clearing');
    $engine = new LedgerPostingEngine;

    $expectedIds = [];
    foreach (range(1, 23) as $i) {
        $posted = $engine->post($this->postingCommand(businessReference: "deposit_credit:history-cursor-walk-{$i}", entries: [
            $this->debitEntry($clearing->id, 1_000),
            $this->creditEntry($accounts->earningAvailable->id, 1_000),
        ]))->transaction;
        $expectedIds[] = $posted->id;
    }

    $firstPage = $this->actingAs($user)->get('/transactions');
    $firstIds = collect($firstPage->inertiaProps('transactions.data'))->pluck('id')->all();
    $nextCursor = $firstPage->inertiaProps('transactions.nextCursor');

    expect($firstIds)->toHaveCount(20);
    expect($nextCursor)->not->toBeNull();

    $secondPage = $this->actingAs($user)->get('/transactions?cursor='.$nextCursor);
    $secondIds = collect($secondPage->inertiaProps('transactions.data'))->pluck('id')->all();
    $secondNextCursor = $secondPage->inertiaProps('transactions.nextCursor');

    expect($secondIds)->toHaveCount(3);
    expect($secondNextCursor)->toBeNull();

    $allIds = array_merge($firstIds, $secondIds);
    expect($allIds)->toHaveCount(23);
    expect(array_unique($allIds))->toHaveCount(23);
    expect(collect($allIds)->sort()->values()->all())->toBe(collect($expectedIds)->sort()->values()->all());
});

test('a forward-then-back cursor round trip returns to the exact original page-1 transactions and order', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-cursor-roundtrip-clearing');
    $engine = new LedgerPostingEngine;

    foreach (range(1, 23) as $i) {
        $engine->post($this->postingCommand(businessReference: "deposit_credit:history-cursor-roundtrip-{$i}", entries: [
            $this->debitEntry($clearing->id, 1_000),
            $this->creditEntry($accounts->earningAvailable->id, 1_000),
        ]));
    }

    $page1 = $this->actingAs($user)->get('/transactions');
    $page1Ids = collect($page1->inertiaProps('transactions.data'))->pluck('id')->all();
    $nextCursor = $page1->inertiaProps('transactions.nextCursor');

    expect($page1Ids)->toHaveCount(20);

    $page2 = $this->actingAs($user)->get('/transactions?cursor='.$nextCursor);
    $previousCursor = $page2->inertiaProps('transactions.previousCursor');

    expect($previousCursor)->not->toBeNull();

    $page1Again = $this->actingAs($user)->get('/transactions?cursor='.$previousCursor);
    $page1AgainIds = collect($page1Again->inertiaProps('transactions.data'))->pluck('id')->all();

    // Exact array equality, not just set membership - proves both the
    // membership and the created_at DESC, id DESC order survive the round
    // trip identically.
    expect($page1AgainIds)->toBe($page1Ids);
});

test('a forward-then-back cursor round trip preserves valid account/type filters throughout', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-cursor-roundtrip-filtered-clearing');
    $engine = new LedgerPostingEngine;

    foreach (range(1, 23) as $i) {
        $engine->post($this->postingCommand(businessReference: "deposit_credit:history-cursor-roundtrip-filtered-{$i}", entries: [
            $this->debitEntry($clearing->id, 1_000),
            $this->creditEntry($accounts->earningAvailable->id, 1_000),
        ]));
    }
    // A different account/type combination that must never appear on any
    // page of the filtered round trip below - advertisingAvailable is
    // funded first so the reservation debit below has a balance to draw on.
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-cursor-roundtrip-filtered-fund-advertising', entries: [
        $this->debitEntry($clearing->id, 500),
        $this->creditEntry($accounts->advertisingAvailable->id, 500),
    ]));
    $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:history-cursor-roundtrip-filtered-other',
        entries: [
            $this->debitEntry($accounts->advertisingAvailable->id, 500),
            $this->creditEntry($accounts->advertisingReserved->id, 500),
        ],
    ));

    $query = '?account=earning_available&type=deposit_credit';

    $page1 = $this->actingAs($user)->get('/transactions'.$query);
    expect($page1->inertiaProps('filters.account'))->toBe('earning_available');
    expect($page1->inertiaProps('filters.type'))->toBe('deposit_credit');
    $page1Ids = collect($page1->inertiaProps('transactions.data'))->pluck('id')->all();
    $nextCursor = $page1->inertiaProps('transactions.nextCursor');
    expect($page1Ids)->toHaveCount(20);

    $page2 = $this->actingAs($user)->get('/transactions'.$query.'&cursor='.$nextCursor);
    expect($page2->inertiaProps('filters.account'))->toBe('earning_available');
    expect($page2->inertiaProps('filters.type'))->toBe('deposit_credit');
    $previousCursor = $page2->inertiaProps('transactions.previousCursor');

    $page1Again = $this->actingAs($user)->get('/transactions'.$query.'&cursor='.$previousCursor);
    expect($page1Again->inertiaProps('filters.account'))->toBe('earning_available');
    expect($page1Again->inertiaProps('filters.type'))->toBe('deposit_credit');
    $page1AgainIds = collect($page1Again->inertiaProps('transactions.data'))->pluck('id')->all();

    expect($page1AgainIds)->toBe($page1Ids);
});

test('the active filter persists across cursor navigation', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-filter-persist-clearing');
    $engine = new LedgerPostingEngine;

    foreach (range(1, 22) as $i) {
        $engine->post($this->postingCommand(businessReference: "deposit_credit:history-filter-persist-{$i}", entries: [
            $this->debitEntry($clearing->id, 1_000),
            $this->creditEntry($accounts->earningAvailable->id, 1_000),
        ]));
    }
    // A different-typed transaction that must never appear in either page.
    $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:history-filter-persist-other',
        entries: [
            $this->debitEntry($accounts->earningAvailable->id, 500),
            $this->creditEntry($accounts->earningHeld->id, 500),
        ],
    ));

    $firstPage = $this->actingAs($user)->get('/transactions?type=deposit_credit');

    expect($firstPage->inertiaProps('filters.type'))->toBe('deposit_credit');
    expect($firstPage->inertiaProps('transactions.data'))->toHaveCount(20);

    $nextCursor = $firstPage->inertiaProps('transactions.nextCursor');
    $secondPage = $this->actingAs($user)->get('/transactions?type=deposit_credit&cursor='.$nextCursor);

    expect($secondPage->inertiaProps('filters.type'))->toBe('deposit_credit');
    $secondData = $secondPage->inertiaProps('transactions.data');
    expect($secondData)->toHaveCount(2);
    foreach ($secondData as $transaction) {
        expect($transaction['type'])->toBe('deposit_credit');
    }
});

// ---------------------------------------------------------------------
// Query-performance and read-only proof
// ---------------------------------------------------------------------

test('the number of queries stays bounded as the authenticated user’s transaction history grows', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-query-count-clearing');
    $engine = new LedgerPostingEngine;

    foreach (range(1, 5) as $i) {
        $engine->post($this->postingCommand(businessReference: "deposit_credit:history-query-count-small-{$i}", entries: [
            $this->debitEntry($clearing->id, 1_000),
            $this->creditEntry($accounts->earningAvailable->id, 1_000),
        ]));
    }

    // A throwaway warm-up request first - the very first authenticated
    // request in a test process incurs one-time cache-population queries
    // (e.g. Spatie's permission registrar) unrelated to this feature's own
    // query behavior, which would otherwise make the "small" measurement
    // below look artificially larger than the "large" one.
    $this->actingAs($user)->get('/transactions');

    DB::enableQueryLog();
    $this->actingAs($user)->get('/transactions');
    $smallQueryCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    foreach (range(6, 45) as $i) {
        $engine->post($this->postingCommand(businessReference: "deposit_credit:history-query-count-large-{$i}", entries: [
            $this->debitEntry($clearing->id, 1_000),
            $this->creditEntry($accounts->earningAvailable->id, 1_000),
        ]));
    }

    // Query logging was never disabled, so the setup loop above was itself
    // logged - flushed here so only the second page load is measured,
    // exactly like the first measurement above.
    DB::flushQueryLog();
    $this->actingAs($user)->get('/transactions');
    $largeQueryCount = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    expect($largeQueryCount)->toBe($smallQueryCount);
});

test('loading the page executes no database mutation', function () {
    [$user, $accounts] = makeUserWithAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('history-readonly-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-readonly-1', entries: [
        $this->debitEntry($clearing->id, 400_000),
        $this->creditEntry($accounts->earningAvailable->id, 400_000),
    ]));

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->actingAs($user)->get('/transactions')->assertOk();

    // Laravel's own session persistence (an "insert/update into sessions
    // ...") is an unavoidable framework mechanic every authenticated page
    // triggers - CACHE_STORE=array and QUEUE_CONNECTION=sync in the test
    // environment (phpunit.xml) mean it is also the *only* table any HTTP
    // request can legitimately write to here. Every mutating statement is
    // asserted to target "sessions" and nothing else, not merely that the
    // wallet-domain tables specifically are clean.
    $mutationPattern = '/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i';

    $mutations = array_values(array_filter(
        $statements,
        fn (string $sql): bool => preg_match($mutationPattern, $sql) === 1,
    ));

    expect($mutations)->not->toBeEmpty();
    foreach ($mutations as $sql) {
        expect($sql)->toContain('sessions');
    }

    $domainTables = ['wallet_accounts', 'ledger_transactions', 'ledger_entries', 'reversal_requests', 'audit_events', 'balance_snapshots', 'users'];
    foreach ($mutations as $sql) {
        foreach ($domainTables as $table) {
            expect($sql)->not->toContain($table);
        }
    }

    expect($statements)->not->toBeEmpty();
});
