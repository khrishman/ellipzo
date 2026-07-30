<?php

use App\Domain\Wallet\Data\UserWalletAccounts;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->staff = User::factory()->create();
    $this->staff->assignRole('administrator');
});

test('an empty ledger returns an honest empty state', function () {
    $response = $this->actingAs($this->staff)->get('/admin/ledger');

    $response->assertInertia(
        fn ($page) => $page
            ->where('transactions.data', [])
            ->where('transactions.nextCursor', null)
            ->where('transactions.previousCursor', null)
            ->has('typeOptions', 9)
            ->has('accountTypeOptions', 7),
    );
});

test('filtering by exact transaction ID returns only that transaction', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-id-clearing');
    $engine = new LedgerPostingEngine;

    $first = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-id-1', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]))->transaction;
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-id-2', entries: [
        $this->debitEntry($clearing->id, 5_000),
        $this->creditEntry($accounts->earningAvailable->id, 5_000),
    ]));

    $response = $this->actingAs($this->staff)->get("/admin/ledger?id={$first->id}");

    $response->assertInertia(
        fn ($page) => $page
            ->has('transactions.data', 1)
            ->where('transactions.data.0.id', $first->id),
    );
});

test('filtering by transaction type returns only matching transactions', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-type-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-type-1', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]));
    $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:ledger-list-type-2',
        entries: [
            $this->debitEntry($accounts->earningAvailable->id, 1_000),
            $this->creditEntry($accounts->earningHeld->id, 1_000),
        ],
    ));

    $response = $this->actingAs($this->staff)->get('/admin/ledger?type=fund_reservation');

    $response->assertInertia(
        fn ($page) => $page
            ->where('filters.type', 'fund_reservation')
            ->has('transactions.data', 1)
            ->where('transactions.data.0.type', 'fund_reservation'),
    );
});

test('filtering by wallet account type returns only transactions touching that account type', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-account-type-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-account-type-1', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]));
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-account-type-2', entries: [
        $this->debitEntry($clearing->id, 5_000),
        $this->creditEntry($accounts->advertisingAvailable->id, 5_000),
    ]));

    $response = $this->actingAs($this->staff)->get('/admin/ledger?accountType=provider_settlement_clearing');

    $response->assertInertia(fn ($page) => $page->has('transactions.data', 2));

    $response2 = $this->actingAs($this->staff)->get('/admin/ledger?accountType=advertising_available');
    $response2->assertInertia(fn ($page) => $page->has('transactions.data', 1));
});

test('filtering by exact numeric user ID returns only that user’s transactions', function () {
    [$accountsA, $userA] = provisionRealUser();
    [$accountsB] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-user-id-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-user-id-a', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accountsA->earningAvailable->id, 10_000),
    ]));
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-user-id-b', entries: [
        $this->debitEntry($clearing->id, 5_000),
        $this->creditEntry($accountsB->earningAvailable->id, 5_000),
    ]));

    $response = $this->actingAs($this->staff)->get("/admin/ledger?userId={$userA->id}");

    $response->assertInertia(fn ($page) => $page->has('transactions.data', 1));
});

test('filtering by exact username returns only that user’s transactions', function () {
    [$accountsA, $userA] = provisionRealUser();
    [$accountsB] = provisionRealUser();
    $userA->profile()->create(['username' => 'ledger_list_username_a']);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-username-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-username-a', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accountsA->earningAvailable->id, 10_000),
    ]));
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-username-b', entries: [
        $this->debitEntry($clearing->id, 5_000),
        $this->creditEntry($accountsB->earningAvailable->id, 5_000),
    ]));

    $response = $this->actingAs($this->staff)->get('/admin/ledger?username=ledger_list_username_a');

    $response->assertInertia(fn ($page) => $page->has('transactions.data', 1));
});

test('an email-shaped username filter is rejected outright', function () {
    $response = $this->actingAs($this->staff)->get('/admin/ledger?username=person@example.com');

    $response->assertStatus(302);
    $response->assertSessionHasErrors('username');
});

test('supplying both userId and username at once is rejected outright', function () {
    [, $userA] = provisionRealUser();
    $userA->profile()->create(['username' => 'ledger_list_both_fields']);

    $response = $this->actingAs($this->staff)->get("/admin/ledger?userId={$userA->id}&username=ledger_list_both_fields");

    $response->assertStatus(302);
    $response->assertSessionHasErrors(['userId', 'username']);
});

test('a numeric username is never silently interpreted as a user ID', function () {
    [$accountsA, $userA] = provisionRealUser();
    [$accountsB, $userB] = provisionRealUser();
    // Deliberately larger than any user ID this test run could produce
    // (RefreshDatabase means IDs start low and sequential) - if this
    // numeric-shaped username were ever silently reinterpreted as a
    // user-ID lookup, that lookup would hit a nonexistent user and
    // return zero transactions instead of user B's own transaction.
    $numericUsername = '900123';
    $userB->profile()->create(['username' => $numericUsername]);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-numeric-username-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-numeric-username-a', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accountsA->earningAvailable->id, 10_000),
    ]));
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-numeric-username-b', entries: [
        $this->debitEntry($clearing->id, 5_000),
        $this->creditEntry($accountsB->earningAvailable->id, 5_000),
    ]));

    // userId={userA->id} must resolve to user A's own transaction.
    $byId = $this->actingAs($this->staff)->get("/admin/ledger?userId={$userA->id}");
    $byId->assertInertia(fn ($page) => $page->has('transactions.data', 1)
        ->where('transactions.data.0.businessReference', 'deposit_credit:ledger-list-numeric-username-a'));

    // username=900123 (a real, numeric-shaped username) must resolve to
    // user B's own transaction via a genuine username lookup, never be
    // silently reinterpreted as a user-ID lookup for a nonexistent
    // user 900123 (which would return zero transactions instead).
    $byUsername = $this->actingAs($this->staff)->get("/admin/ledger?username={$numericUsername}");
    $byUsername->assertInertia(fn ($page) => $page->has('transactions.data', 1)
        ->where('transactions.data.0.businessReference', 'deposit_credit:ledger-list-numeric-username-b'));
});

test('a rejected email-shaped username filter is never retained, repeated, or propagated anywhere', function () {
    $canary = 'diagnostic-canary-'.uniqid().'@example.com';

    $logPath = storage_path('logs/laravel.log');
    $logSizeBefore = file_exists($logPath) ? filesize($logPath) : 0;

    $response = $this->actingAs($this->staff)->get('/admin/ledger?username='.urlencode($canary));

    $response->assertStatus(302);
    $response->assertSessionHasErrors('username');

    // Response content (redirect responses carry minimal/no body, but
    // prove it regardless).
    expect($response->getContent())->not->toContain($canary);

    // Location header.
    expect($response->headers->get('Location'))->not->toContain($canary);

    // Validation error message text - the closure rule uses a fixed
    // static message, never interpolates the submitted value.
    $errors = session('errors')->getBag('default')->all();
    foreach ($errors as $message) {
        expect($message)->not->toContain($canary);
    }

    // Flashed old input - confirmed empirically to leak by default via
    // Laravel's own Handler::invalid()->withInput() before this was
    // fixed with AdminLedgerFilterRequest::failedValidation().
    expect(session('_old_input'))->not->toHaveKey('username');
    expect(json_encode(session('_old_input')))->not->toContain($canary);

    // Following the redirect must not resurrect the canary into a
    // subsequent canonical Inertia page's props or pagination links.
    $next = $this->actingAs($this->staff)->get($response->headers->get('Location'));
    expect($next->getContent())->not->toContain($canary);

    // Application-generated logs - ValidationException is in Laravel's
    // own $internalDontReport list (confirmed by reading the framework
    // source), so this is expected to never grow/contain the canary;
    // verified rather than assumed.
    $logSizeAfter = file_exists($logPath) ? filesize($logPath) : 0;
    if ($logSizeAfter > $logSizeBefore) {
        $newLogContent = file_get_contents($logPath);
        expect($newLogContent)->not->toContain($canary);
    }
});

test('filtering by exact business reference and correlation ID returns only that transaction', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-reference-clearing');
    $engine = new LedgerPostingEngine;

    $correlationId = (string) Str::uuid();
    $transaction = $engine->post($this->postingCommand(
        businessReference: 'deposit_credit:ledger-list-reference-1',
        correlationId: $correlationId,
        entries: [
            $this->debitEntry($clearing->id, 10_000),
            $this->creditEntry($accounts->earningAvailable->id, 10_000),
        ],
    ))->transaction;
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-reference-2', entries: [
        $this->debitEntry($clearing->id, 5_000),
        $this->creditEntry($accounts->earningAvailable->id, 5_000),
    ]));

    $byReference = $this->actingAs($this->staff)->get('/admin/ledger?businessReference=deposit_credit:ledger-list-reference-1');
    $byReference->assertInertia(fn ($page) => $page->has('transactions.data', 1)->where('transactions.data.0.id', $transaction->id));

    $byCorrelation = $this->actingAs($this->staff)->get("/admin/ledger?correlationId={$correlationId}");
    $byCorrelation->assertInertia(fn ($page) => $page->has('transactions.data', 1)->where('transactions.data.0.id', $transaction->id));
});

test('UTC date-range filtering is inclusive at the 00:00:00 and 23:59:59 boundaries', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-date-clearing');
    $engine = new LedgerPostingEngine;

    $this->travelTo(Carbon\Carbon::create(2026, 3, 5, 0, 0, 0, 'UTC'));
    $atStart = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-start', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]))->transaction;

    $this->travelTo(Carbon\Carbon::create(2026, 3, 5, 23, 59, 59, 'UTC'));
    $atEnd = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-end', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]))->transaction;

    $this->travelTo(Carbon\Carbon::create(2026, 3, 4, 23, 59, 58, 'UTC'));
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-before', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]));

    $this->travelTo(Carbon\Carbon::create(2026, 3, 6, 0, 0, 1, 'UTC'));
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-after', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]));

    $this->travelBack();

    $response = $this->actingAs($this->staff)->get('/admin/ledger?dateFrom=2026-03-05&dateTo=2026-03-05');

    $ids = collect($response->inertiaProps('transactions.data'))->pluck('id')->all();

    expect($ids)->toContain($atStart->id);
    expect($ids)->toContain($atEnd->id);
    expect($ids)->toHaveCount(2);
});

test('a reversed date range is rejected with a validation redirect', function () {
    $response = $this->actingAs($this->staff)->get('/admin/ledger?dateFrom=2026-03-10&dateTo=2026-03-01');

    $response->assertStatus(302);
    $response->assertSessionHasErrors('dateTo');
});

test('dateFrom alone includes everything from its 00:00:00 UTC boundary onward, with no implicit upper bound', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-date-from-only-clearing');
    $engine = new LedgerPostingEngine;

    $this->travelTo(Carbon\Carbon::create(2026, 3, 4, 23, 59, 59, 'UTC'));
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-from-only-before', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]));

    $this->travelTo(Carbon\Carbon::create(2026, 3, 5, 0, 0, 0, 'UTC'));
    $atStart = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-from-only-at', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]))->transaction;

    $this->travelTo(Carbon\Carbon::create(2030, 1, 1, 0, 0, 0, 'UTC'));
    $farFuture = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-from-only-future', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]))->transaction;

    $this->travelBack();

    $response = $this->actingAs($this->staff)->get('/admin/ledger?dateFrom=2026-03-05');

    $ids = collect($response->inertiaProps('transactions.data'))->pluck('id')->all();

    expect($ids)->toContain($atStart->id);
    expect($ids)->toContain($farFuture->id);
    expect($ids)->toHaveCount(2);
});

test('dateTo alone includes everything up to its 23:59:59 UTC boundary, with no implicit lower bound', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-date-to-only-clearing');
    $engine = new LedgerPostingEngine;

    $this->travelTo(Carbon\Carbon::create(2020, 1, 1, 0, 0, 0, 'UTC'));
    $farPast = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-to-only-past', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]))->transaction;

    $this->travelTo(Carbon\Carbon::create(2026, 3, 5, 23, 59, 59, 'UTC'));
    $atEnd = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-to-only-at', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]))->transaction;

    $this->travelTo(Carbon\Carbon::create(2026, 3, 6, 0, 0, 1, 'UTC'));
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-to-only-after', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]));

    $this->travelBack();

    $response = $this->actingAs($this->staff)->get('/admin/ledger?dateTo=2026-03-05');

    $ids = collect($response->inertiaProps('transactions.data'))->pluck('id')->all();

    expect($ids)->toContain($farPast->id);
    expect($ids)->toContain($atEnd->id);
    expect($ids)->toHaveCount(2);
});

test('an equal dateFrom and dateTo filters to exactly that single UTC calendar day', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-date-equal-clearing');
    $engine = new LedgerPostingEngine;

    $this->travelTo(Carbon\Carbon::create(2026, 3, 5, 12, 0, 0, 'UTC'));
    $onDay = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-equal-on', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]))->transaction;

    $this->travelTo(Carbon\Carbon::create(2026, 3, 4, 12, 0, 0, 'UTC'));
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-date-equal-before', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]));

    $this->travelBack();

    $response = $this->actingAs($this->staff)->get('/admin/ledger?dateFrom=2026-03-05&dateTo=2026-03-05');

    $ids = collect($response->inertiaProps('transactions.data'))->pluck('id')->all();

    expect($ids)->toContain($onDay->id);
    expect($ids)->toHaveCount(1);
});

test('cursor pagination preserves both dateFrom and dateTo in the echoed filters and in the follow-up page', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-date-cursor-clearing');
    $engine = new LedgerPostingEngine;

    $this->travelTo(Carbon\Carbon::create(2026, 3, 5, 12, 0, 0, 'UTC'));
    foreach (range(1, 27) as $i) {
        $engine->post($this->postingCommand(businessReference: "deposit_credit:ledger-list-date-cursor-{$i}", entries: [
            $this->debitEntry($clearing->id, 1_000),
            $this->creditEntry($accounts->earningAvailable->id, 1_000),
        ]));
    }
    $this->travelBack();

    $page1 = $this->actingAs($this->staff)->get('/admin/ledger?dateFrom=2026-03-05&dateTo=2026-03-05');

    $page1->assertInertia(fn ($page) => $page
        ->where('filters.dateFrom', '2026-03-05')
        ->where('filters.dateTo', '2026-03-05')
    );

    $page1Ids = collect($page1->inertiaProps('transactions.data'))->pluck('id')->all();
    $nextCursor = $page1->inertiaProps('transactions.nextCursor');
    expect($page1Ids)->toHaveCount(25);
    expect($nextCursor)->not->toBeNull();

    $page2 = $this->actingAs($this->staff)->get("/admin/ledger?dateFrom=2026-03-05&dateTo=2026-03-05&cursor={$nextCursor}");
    $page2Ids = collect($page2->inertiaProps('transactions.data'))->pluck('id')->all();

    $page2->assertInertia(fn ($page) => $page
        ->where('filters.dateFrom', '2026-03-05')
        ->where('filters.dateTo', '2026-03-05')
    );
    expect($page2Ids)->toHaveCount(2);
    expect(array_intersect($page1Ids, $page2Ids))->toBeEmpty();
});

test('array-shaped values are rejected for every scalar filter', function () {
    $fields = ['id', 'type', 'accountType', 'userId', 'username', 'businessReference', 'correlationId', 'dateFrom', 'dateTo'];

    foreach ($fields as $field) {
        $response = $this->actingAs($this->staff)->get("/admin/ledger?{$field}[]=x");
        $response->assertStatus(302);
        $response->assertSessionHasErrors($field);
    }
});

test('an unknown transaction type or account type value is rejected', function () {
    $this->actingAs($this->staff)->get('/admin/ledger?type=not_a_real_type')
        ->assertStatus(302)->assertSessionHasErrors('type');

    $this->actingAs($this->staff)->get('/admin/ledger?accountType=not_a_real_account_type')
        ->assertStatus(302)->assertSessionHasErrors('accountType');
});

test('a malformed cursor redirects canonically without a cursor, preserving valid filters', function () {
    $response = $this->actingAs($this->staff)->get('/admin/ledger?type=deposit_credit&cursor=not-a-real-cursor!!!');

    $response->assertRedirect(route('admin.ledger.index', ['type' => 'deposit_credit'], absolute: false));
});

test('a structurally-valid but wrong-shaped cursor also redirects canonically', function () {
    $tamperedCursor = Cursor::fromEncoded(base64_encode(json_encode([
        '_pointsToNextItems' => true,
        'unrelated_field' => 'zzz',
    ])))->encode();

    $response = $this->actingAs($this->staff)->get('/admin/ledger?cursor='.$tamperedCursor);

    $response->assertRedirect(route('admin.ledger.index', [], absolute: false));
});

test('cursor pagination has stable ordering with no duplicate or skipped transaction', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-cursor-clearing');
    $engine = new LedgerPostingEngine;

    $expectedIds = [];
    foreach (range(1, 27) as $i) {
        $transaction = $engine->post($this->postingCommand(businessReference: "deposit_credit:ledger-list-cursor-{$i}", entries: [
            $this->debitEntry($clearing->id, 1_000),
            $this->creditEntry($accounts->earningAvailable->id, 1_000),
        ]))->transaction;
        $expectedIds[] = $transaction->id;
    }

    $page1 = $this->actingAs($this->staff)->get('/admin/ledger');
    $page1Ids = collect($page1->inertiaProps('transactions.data'))->pluck('id')->all();
    $nextCursor = $page1->inertiaProps('transactions.nextCursor');

    expect($page1Ids)->toHaveCount(25);

    $page2 = $this->actingAs($this->staff)->get('/admin/ledger?cursor='.$nextCursor);
    $page2Ids = collect($page2->inertiaProps('transactions.data'))->pluck('id')->all();
    $previousCursor = $page2->inertiaProps('transactions.previousCursor');

    expect($page2Ids)->toHaveCount(2);

    $allIds = array_merge($page1Ids, $page2Ids);
    expect($allIds)->toHaveCount(27);
    expect(array_unique($allIds))->toHaveCount(27);
    expect(collect($allIds)->sort()->values()->all())->toBe(collect($expectedIds)->sort()->values()->all());

    // Bidirectional round trip: following page 2's previousCursor must
    // reconstruct page 1 exactly, same IDs and same order.
    $page1Again = $this->actingAs($this->staff)->get('/admin/ledger?cursor='.$previousCursor);
    $page1AgainIds = collect($page1Again->inertiaProps('transactions.data'))->pluck('id')->all();
    expect($page1AgainIds)->toBe($page1Ids);
});

test('the query count stays bounded as the ledger grows', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-query-count-clearing');
    $engine = new LedgerPostingEngine;

    foreach (range(1, 5) as $i) {
        $engine->post($this->postingCommand(businessReference: "deposit_credit:ledger-list-query-count-small-{$i}", entries: [
            $this->debitEntry($clearing->id, 1_000),
            $this->creditEntry($accounts->earningAvailable->id, 1_000),
        ]));
    }

    // Warm-up request first - excludes one-time cache-population queries
    // (e.g. Spatie's permission registrar) from the measurement below.
    $this->actingAs($this->staff)->get('/admin/ledger');

    DB::enableQueryLog();
    $this->actingAs($this->staff)->get('/admin/ledger');
    $smallQueryCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    foreach (range(6, 60) as $i) {
        $engine->post($this->postingCommand(businessReference: "deposit_credit:ledger-list-query-count-large-{$i}", entries: [
            $this->debitEntry($clearing->id, 1_000),
            $this->creditEntry($accounts->earningAvailable->id, 1_000),
        ]));
    }

    DB::flushQueryLog();
    $this->actingAs($this->staff)->get('/admin/ledger');
    $largeQueryCount = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    expect($largeQueryCount)->toBe($smallQueryCount);
});

test('full email addresses never appear in the response, only the masked form', function () {
    [$accounts, $user] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-mask-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-mask-1', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]));

    $response = $this->actingAs($this->staff)->get('/admin/ledger');
    $content = $response->getContent();

    expect($content)->not->toContain($user->email);
    $expectedMask = substr($user->email, 0, 1).'***@'.substr($user->email, strpos($user->email, '@') + 1);
    expect($content)->toContain($expectedMask);
});

test('no credential, session, token, or raw provider identity ever appears in the response', function () {
    [$accounts] = provisionRealUser();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-secrets-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-secrets-1', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]));

    $content = $this->actingAs($this->staff)->get('/admin/ledger')->getContent();

    expect($content)->not->toContain('remember_token');
    expect($content)->not->toContain('oauth_identities');
    expect($content)->not->toContain($this->staff->getAuthPassword());
    expect($content)->not->toContain('ledger-list-secrets-clearing');
});

test('involvedUsers contains a user exactly once even when they participate through two accounts and two entries', function () {
    [$accounts, $user] = provisionRealUser();
    $user->profile()->create(['username' => 'involved_user_dedup_test']);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-dedup-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-dedup-fund', entries: [
        $this->debitEntry($clearing->id, 40_000),
        $this->creditEntry($accounts->earningAvailable->id, 40_000),
    ]));

    // A single transaction whose two entries both belong to the same
    // user, via two different account types.
    $transaction = $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:ledger-list-dedup-1',
        entries: [
            $this->debitEntry($accounts->earningAvailable->id, 40_000),
            $this->creditEntry($accounts->earningHeld->id, 40_000),
        ],
    ))->transaction;

    $response = $this->actingAs($this->staff)->get('/admin/ledger?businessReference=fund_reservation:ledger-list-dedup-1');

    $item = collect($response->inertiaProps('transactions.data'))->firstWhere('id', $transaction->id);

    expect($item['involvedUsers'])->toHaveCount(1);
    expect($item['involvedUsers'][0])->toBe([
        'id' => $user->id,
        'username' => 'involved_user_dedup_test',
        'maskedEmail' => substr($user->email, 0, 1).'***@'.substr($user->email, strpos($user->email, '@') + 1),
    ]);
});

test('involvedUsers lists distinct users in stable, deterministic entry order', function () {
    $provisioner = new WalletAccountProvisioner;
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $accountsA = $provisioner->provisionUserAccounts($userA);
    $accountsB = $provisioner->provisionUserAccounts($userB);
    $clearing = $provisioner->providerClearingAccount('ledger-list-order-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-order-fund', entries: [
        $this->debitEntry($clearing->id, 30_000),
        $this->creditEntry($accountsA->earningAvailable->id, 30_000),
    ]));

    // userA's entry is inserted first, userB's second - the posting
    // engine writes entries in array order, and each gets a
    // monotonically-later created_at/ULID, so the presenter's own
    // created_at/id-ordered eager load must reproduce this same order.
    $transaction = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-order-1', entries: [
        $this->debitEntry($accountsA->earningAvailable->id, 30_000),
        $this->creditEntry($accountsB->earningAvailable->id, 30_000),
    ]))->transaction;

    $response = $this->actingAs($this->staff)->get('/admin/ledger?businessReference=deposit_credit:ledger-list-order-1');
    $item = collect($response->inertiaProps('transactions.data'))->firstWhere('id', $transaction->id);

    expect($item['involvedUsers'])->toHaveCount(2);
    expect($item['involvedUsers'][0]['id'])->toBe($userA->id);
    expect($item['involvedUsers'][1]['id'])->toBe($userB->id);

    // Repeating the same request must reproduce the identical order -
    // "deterministic", not merely "happened to match once".
    $again = $this->actingAs($this->staff)->get('/admin/ledger?businessReference=deposit_credit:ledger-list-order-1');
    $itemAgain = collect($again->inertiaProps('transactions.data'))->firstWhere('id', $transaction->id);
    expect($itemAgain['involvedUsers'])->toBe($item['involvedUsers']);
});

test('involvedUsers never contains raw email, real name, profile fields, or a scope key', function () {
    [$accounts, $user] = provisionRealUser();
    $user->profile()->create(['username' => 'involved_user_privacy_test']);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('secret-scope-key-canary');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-privacy-1', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]));

    $response = $this->actingAs($this->staff)->get('/admin/ledger');
    $item = collect($response->inertiaProps('transactions.data'))->first();

    expect($item['involvedUsers'][0])->toHaveKeys(['id', 'username', 'maskedEmail']);
    expect($item['involvedUsers'][0])->toHaveCount(3);

    $content = $response->getContent();
    expect($content)->not->toContain($user->email);
    expect($content)->not->toContain($user->name);
    expect($content)->not->toContain('secret-scope-key-canary');
});

test('deliberately injected canary values for legal name, email, and OAuth identity never appear anywhere in the list response', function () {
    $canaryName = 'CANARY_LEGAL_NAME_'.uniqid();
    $canaryEmail = 'canary-privacy-'.uniqid().'@example.com';
    $canaryOAuthProviderUserId = 'CANARY_OAUTH_SUBJECT_'.uniqid();

    $user = User::factory()->create(['name' => $canaryName, 'email' => $canaryEmail]);
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);
    $user->profile()->create(['username' => 'canary_privacy_list_user']);
    $user->oauthIdentities()->create(['provider' => 'google', 'provider_user_id' => $canaryOAuthProviderUserId]);

    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-list-canary-clearing');
    $engine = new LedgerPostingEngine;
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-list-canary-1', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]));

    $response = $this->actingAs($this->staff)->get('/admin/ledger');
    $content = $response->getContent();

    expect($content)->not->toContain($canaryName);
    expect($content)->not->toContain($canaryEmail);
    expect($content)->not->toContain($canaryOAuthProviderUserId);
    expect($content)->not->toContain('google');
    expect($content)->not->toContain('remember_token');
    expect($content)->not->toContain($user->getAuthPassword());
    expect($content)->not->toContain(session()->getId());

    // No debug/exception/SQL leakage markers under normal, successful operation.
    expect($content)->not->toContain('SQLSTATE');
    expect($content)->not->toContain('Illuminate\\Database');
    expect($content)->not->toContain('Stack trace');

    // The involved user's identity is present, but only through the
    // allowlisted, masked shape - proving the canaries above weren't
    // simply omitted because the user never appeared at all.
    expect($content)->toContain('canary_privacy_list_user');
});

/**
 * @return array{0: UserWalletAccounts, 1: User}
 */
function provisionRealUser(): array
{
    $user = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);

    return [$accounts, $user];
}
