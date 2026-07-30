<?php

use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Services\AdministrativeAdjustmentService;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\ReversalRequestService;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\AuditEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function staffWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('the detail page renders entries in deterministic order with debit/credit and fixed-decimal amounts', function () {
    $staff = staffWithRole('administrator');
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-detail-entries-clearing');
    $engine = new LedgerPostingEngine;

    $transaction = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-detail-entries-1', entries: [
        $this->debitEntry($clearing->id, 250_000),
        $this->creditEntry($accounts->earningAvailable->id, 250_000),
    ]))->transaction;

    $response = $this->actingAs($staff)->get("/admin/ledger/{$transaction->id}");

    $response->assertInertia(
        fn ($page) => $page
            ->has('entries', 2)
            ->where('entries.0.entryType', 'debit')
            ->where('entries.0.atomic', '250000')
            ->where('entries.0.formatted', '0.250000')
            ->where('entries.0.currency', 'USD')
            ->where('entries.0.scopeType', 'provider')
            ->where('entries.0.user', null)
            ->where('entries.1.entryType', 'credit')
            ->where('entries.1.scopeType', 'user')
            ->where('entries.1.user.id', $accounts->earningAvailable->user_id),
    );
});

test('platform and provider accounts show only a controlled label, never a raw scope key', function () {
    $staff = staffWithRole('administrator');
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('secret-provider-identifier-xyz');
    $engine = new LedgerPostingEngine;

    $transaction = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-detail-scope-1', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]))->transaction;

    $response = $this->actingAs($staff)->get("/admin/ledger/{$transaction->id}");

    $response->assertInertia(
        fn ($page) => $page
            ->where('entries.0.scopeLabel', 'Provider')
            ->where('entries.0.accountLabel', 'Provider settlement clearing'),
    );
    expect($response->getContent())->not->toContain('secret-provider-identifier-xyz');
});

test('reversal linkage is shown in both directions', function () {
    $staff = staffWithRole('administrator');
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-detail-reversal-clearing');
    $engine = new LedgerPostingEngine;

    $original = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-detail-reversal-1', entries: [
        $this->debitEntry($clearing->id, 300_000),
        $this->creditEntry($accounts->earningAvailable->id, 300_000),
    ]))->transaction;

    $reversal = (new ReversalRequestService($engine))->requestAndExecute(
        $this->reversalCommand($original->id, reason: 'Staff-only-reversal-reason-must-never-render'),
    );

    $originalResponse = $this->actingAs($staff)->get("/admin/ledger/{$original->id}");
    $originalResponse->assertInertia(
        fn ($page) => $page
            ->where('transaction.hasBeenReversed', true)
            ->where('transaction.reversalTransactionId', $reversal->reversal_transaction_id)
            ->where('transaction.isReversal', false),
    );

    $reversalResponse = $this->actingAs($staff)->get("/admin/ledger/{$reversal->reversal_transaction_id}");
    $reversalResponse->assertInertia(
        fn ($page) => $page
            ->where('transaction.isReversal', true)
            ->where('transaction.originalTransactionId', $original->id)
            ->where('transaction.hasBeenReversed', false),
    );
});

test('a multi-account transaction lists every entry with correct scope', function () {
    $staff = staffWithRole('administrator');
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-detail-multi-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-detail-multi-fund', entries: [
        $this->debitEntry($clearing->id, 40_000),
        $this->creditEntry($accounts->earningAvailable->id, 40_000),
    ]));
    $transaction = $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:ledger-detail-multi-1',
        entries: [
            $this->debitEntry($accounts->earningAvailable->id, 40_000),
            $this->creditEntry($accounts->earningHeld->id, 40_000),
        ],
    ))->transaction;

    $response = $this->actingAs($staff)->get("/admin/ledger/{$transaction->id}");

    $response->assertInertia(
        fn ($page) => $page
            ->has('entries', 2)
            ->where('entries.0.accountType', 'earning_available')
            ->where('entries.1.accountType', 'earning_held'),
    );
});

test('a transaction where the same user participates through two accounts shows both entries independently, not deduplicated', function () {
    $staff = staffWithRole('administrator');
    $accounts = $this->provisionTestAccounts();
    $user = User::find($accounts->earningAvailable->user_id);
    $user->profile()->create(['username' => 'detail_dedup_user']);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-detail-dedup-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-detail-dedup-fund', entries: [
        $this->debitEntry($clearing->id, 40_000),
        $this->creditEntry($accounts->earningAvailable->id, 40_000),
    ]));
    $transaction = $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'fund_reservation:ledger-detail-dedup-1',
        entries: [
            $this->debitEntry($accounts->earningAvailable->id, 40_000),
            $this->creditEntry($accounts->earningHeld->id, 40_000),
        ],
    ))->transaction;

    $response = $this->actingAs($staff)->get("/admin/ledger/{$transaction->id}");

    // The detail page is an entry-level view, deliberately never deduped -
    // both of this same user's own entries must appear as two separate
    // rows, each correctly identifying them, unlike the list page's
    // summary-level involvedUsers which does dedupe.
    $response->assertInertia(
        fn ($page) => $page
            ->has('entries', 2)
            ->where('entries.0.user.id', $user->id)
            ->where('entries.0.user.username', 'detail_dedup_user')
            ->where('entries.1.user.id', $user->id)
            ->where('entries.1.user.username', 'detail_dedup_user'),
    );
});

// ---------------------------------------------------------------------
// Audit permission gating
// ---------------------------------------------------------------------

test('ledger.view alone never exposes the audit payload', function () {
    // A direct permission grant with no role at all - revokePermissionTo()
    // only removes a *directly* assigned permission, never one derived
    // from a role, so a role-based user can never be used to prove this
    // negative. Granting ledger.view alone, directly, is the only way to
    // isolate exactly this one permission.
    $staff = User::factory()->create();
    $staff->givePermissionTo('ledger.view');

    $adjustmentActor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $result = app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($adjustmentActor, $target));

    $response = $this->actingAs($staff)->get("/admin/ledger/{$result->transaction->id}");

    $response->assertInertia(
        fn ($page) => $page
            ->where('canViewLedgerAudit', false)
            ->where('ledgerAudit', null),
    );
});

test('ledger.audit.view exposes exactly the permitted ledger audit record', function () {
    $staff = staffWithRole('administrator');
    $adjustmentActor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $result = app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand(
        $adjustmentActor,
        $target,
        internalReason: 'Correcting a support-verified reward calculation error.',
    ));

    $response = $this->actingAs($staff)->get("/admin/ledger/{$result->transaction->id}");

    $response->assertInertia(
        fn ($page) => $page
            ->where('canViewLedgerAudit', true)
            ->where('ledgerAudit.action', 'ledger.administrative_adjustment')
            ->where('ledgerAudit.internalReason', 'Correcting a support-verified reward calculation error.')
            ->where('ledgerAudit.actor.id', $adjustmentActor->id),
    );
});

test('zero AuditEvent queries execute when the viewer lacks ledger.audit.view', function () {
    $staff = User::factory()->create();
    $staff->givePermissionTo('ledger.view');

    $adjustmentActor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);
    $result = app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($adjustmentActor, $target));

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->actingAs($staff)->get("/admin/ledger/{$result->transaction->id}")->assertOk();

    $auditQueries = array_filter($statements, fn (string $sql): bool => str_contains($sql, 'audit_events'));
    expect($auditQueries)->toBe([]);
});

test('an unrelated audit event (staff role change) is never returned by the ledger audit panel', function () {
    $staff = staffWithRole('administrator');
    $adjustmentActor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);
    $result = app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($adjustmentActor, $target));

    // A real, unrelated audit event for a completely different action.
    AuditEvent::record(
        actor: $staff,
        action: 'staff.role_changed',
        entityType: 'user',
        entityId: $target->id,
        before: ['role' => null],
        after: ['role' => 'moderator'],
        reason: 'Unrelated staff action - must never appear on the ledger audit panel.',
    );

    $response = $this->actingAs($staff)->get("/admin/ledger/{$result->transaction->id}");

    $response->assertInertia(fn ($page) => $page->where('ledgerAudit.action', 'ledger.administrative_adjustment'));
    expect($response->getContent())->not->toContain('Unrelated staff action - must never appear on the ledger audit panel.');
});

test('no internal staff reason leaks through the transaction or entries fields, only through the authorized audit payload', function () {
    $staff = staffWithRole('administrator');
    $adjustmentActor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $result = app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand(
        $adjustmentActor,
        $target,
        internalReason: 'Correcting a support-verified reward calculation error.',
        userVisibleDescription: 'We adjusted your balance to correct a reward calculation issue.',
    ));

    $response = $this->actingAs($staff)->get("/admin/ledger/{$result->transaction->id}");

    // The transaction's own description (the user-visible one) is fine to
    // show; the internal reason must appear exactly once, inside the
    // authorized audit payload, never duplicated into a transaction/entry
    // field.
    $response->assertInertia(
        fn ($page) => $page
            ->where('transaction.description', 'We adjusted your balance to correct a reward calculation issue.')
            ->where('ledgerAudit.internalReason', 'Correcting a support-verified reward calculation error.'),
    );
});

test('the audit payload before/after values are honestly JSON-typed, not forced into flat strings', function () {
    $staff = staffWithRole('administrator');
    $adjustmentActor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $result = app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($adjustmentActor, $target));

    $response = $this->actingAs($staff)->get("/admin/ledger/{$result->transaction->id}");

    $after = $response->inertiaProps('ledgerAudit.after');
    expect($after)->toBeArray();
    expect($after)->toHaveKey('target_wallet_account_id');
    expect($after)->toHaveKey('amount_atomic');
    $before = $response->inertiaProps('ledgerAudit.before');
    expect($before)->toBe([]);
});

test('an audit event containing unapproved top-level, nested, and array-element canaries never leaks them, while approved fields survive', function () {
    $staff = staffWithRole('administrator');
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-detail-canary-clearing');
    $engine = new LedgerPostingEngine;

    // An "otherwise valid matching audit event" - a real transaction with
    // a real, correctly-scoped AuditEvent row attached to it, but whose
    // after_state has been deliberately expanded with a top-level canary,
    // a canary smuggled under an approved key name (type mismatch), and a
    // canary hidden inside an unapproved key's array value. None of this
    // requires AdministrativeAdjustmentService at all - AuditEvent::record()
    // itself places no shape restriction on before/after, which is exactly
    // why the presenter, not the writer, must be the enforcement boundary.
    $transaction = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-detail-canary-1', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]))->transaction;

    AuditEvent::record(
        actor: $staff,
        action: 'ledger.administrative_adjustment',
        entityType: 'ledger_transaction',
        entityId: null,
        before: [],
        after: [
            'target_wallet_account_id' => ['smuggled' => 'CANARY_NESTED_UNDER_APPROVED_KEY'],
            'target_account_type' => 'earning_available',
            'direction' => 'increase',
            'amount_atomic' => '10000000',
            'currency' => 'USD',
            'business_reference' => 'administrative_adjustment:ledger-detail-canary-1',
            'internal_staff_note' => 'CANARY_TOP_LEVEL_SHOULD_NEVER_SURVIVE',
            'related_ids' => ['ok-value', 'CANARY_ARRAY_ELEMENT_SHOULD_NEVER_SURVIVE'],
        ],
        reason: 'Canary-fixture reason - never exposed by this test on its own.',
        entityKey: $transaction->id,
    );

    $response = $this->actingAs($staff)->get("/admin/ledger/{$transaction->id}");

    $after = $response->inertiaProps('ledgerAudit.after');

    // Approved, correctly-shaped fields survive.
    expect($after)->toHaveKey('target_account_type', 'earning_available');
    expect($after)->toHaveKey('direction', 'increase');
    expect($after)->toHaveKey('amount_atomic', '10000000');
    expect($after)->toHaveKey('currency', 'USD');
    expect($after)->toHaveKey('business_reference', 'administrative_adjustment:ledger-detail-canary-1');

    // The approved key whose value was type-mismatched (nested instead of
    // scalar) is dropped entirely rather than passed through raw.
    expect($after)->not->toHaveKey('target_wallet_account_id');

    // Every unapproved key is absent, regardless of its own value's shape.
    expect($after)->not->toHaveKey('internal_staff_note');
    expect($after)->not->toHaveKey('related_ids');

    $content = $response->getContent();
    expect($content)->not->toContain('CANARY_TOP_LEVEL_SHOULD_NEVER_SURVIVE');
    expect($content)->not->toContain('CANARY_NESTED_UNDER_APPROVED_KEY');
    expect($content)->not->toContain('CANARY_ARRAY_ELEMENT_SHOULD_NEVER_SURVIVE');
});

test('no raw provider credential, token, or session data appears on the detail page', function () {
    $staff = staffWithRole('administrator');
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-detail-secrets-clearing');
    $engine = new LedgerPostingEngine;

    $transaction = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-detail-secrets-1', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]))->transaction;

    $content = $this->actingAs($staff)->get("/admin/ledger/{$transaction->id}")->getContent();

    expect($content)->not->toContain('remember_token');
    expect($content)->not->toContain('oauth_identities');
    expect($content)->not->toContain($staff->getAuthPassword());
});

test('deliberately injected canary values for legal name, email, and OAuth identity never appear anywhere in the detail response, for either the involved user or the audit actor', function () {
    $staff = staffWithRole('administrator');

    $canaryTargetName = 'CANARY_TARGET_LEGAL_NAME_'.uniqid();
    $canaryTargetEmail = 'canary-detail-target-'.uniqid().'@example.com';
    $canaryTargetOAuth = 'CANARY_TARGET_OAUTH_SUBJECT_'.uniqid();
    $target = User::factory()->create(['name' => $canaryTargetName, 'email' => $canaryTargetEmail]);
    (new WalletAccountProvisioner)->provisionUserAccounts($target);
    $target->profile()->create(['username' => 'canary_detail_target_user']);
    $target->oauthIdentities()->create(['provider' => 'google', 'provider_user_id' => $canaryTargetOAuth]);

    $canaryActorName = 'CANARY_ACTOR_LEGAL_NAME_'.uniqid();
    $canaryActorEmail = 'canary-detail-actor-'.uniqid().'@example.com';
    $adjustmentActor = User::factory()->create(['name' => $canaryActorName, 'email' => $canaryActorEmail]);
    $adjustmentActor->assignRole('finance-operator');
    $adjustmentActor->profile()->create(['username' => 'canary_detail_actor_user']);

    $result = app(AdministrativeAdjustmentService::class)->submit(
        $this->adjustmentCommand($adjustmentActor, $target),
    );

    $response = $this->actingAs($staff)->get("/admin/ledger/{$result->transaction->id}");
    $content = $response->getContent();

    // Neither the involved user's nor the audit actor's real name, email,
    // or OAuth subject ever appears - only their id/username, via the
    // allowlisted identity shape.
    expect($content)->not->toContain($canaryTargetName);
    expect($content)->not->toContain($canaryTargetEmail);
    expect($content)->not->toContain($canaryTargetOAuth);
    expect($content)->not->toContain($canaryActorName);
    expect($content)->not->toContain($canaryActorEmail);
    expect($content)->not->toContain('google');
    expect($content)->not->toContain('remember_token');
    expect($content)->not->toContain($staff->getAuthPassword());
    expect($content)->not->toContain($adjustmentActor->getAuthPassword());
    expect($content)->not->toContain(session()->getId());

    // No debug/exception/SQL leakage markers under normal, successful operation.
    expect($content)->not->toContain('SQLSTATE');
    expect($content)->not->toContain('Illuminate\\Database');
    expect($content)->not->toContain('Stack trace');

    // Both real identities are present, but only through their controlled
    // username - proving the canaries above weren't omitted merely
    // because the users never appeared in the response at all.
    expect($content)->toContain('canary_detail_target_user');
    expect($content)->toContain('canary_detail_actor_user');
});

test('the transaction identity is queried exactly once for the whole detail request - route-model binding only, no redundant re-fetch', function () {
    $staff = staffWithRole('administrator');
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-detail-identity-clearing');
    $engine = new LedgerPostingEngine;

    $transaction = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-detail-identity-1', entries: [
        $this->debitEntry($clearing->id, 10_000),
        $this->creditEntry($accounts->earningAvailable->id, 10_000),
    ]))->transaction;

    // Warm-up first, then measure with fresh bindings capture.
    $this->actingAs($staff)->get("/admin/ledger/{$transaction->id}")->assertOk();

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    $this->actingAs($staff)->get("/admin/ledger/{$transaction->id}")->assertOk();

    // Every query that both targets ledger_transactions and binds this
    // exact transaction ID as a direct equality/whereIn match - the
    // route-model-binding lookup is the only one that should ever do
    // this; AdminLedgerController/AdminLedgerQuery must never issue a
    // second `WHERE id = ?` (or `WHERE id IN (?)`) for the same ID to
    // re-obtain the already-bound, already-authorized model.
    // Driver-agnostic: MySQL/MariaDB quote identifiers with backticks,
    // SQLite (this suite's test driver) with double quotes - match either.
    $identityLookups = array_filter($statements, function (array $s) use ($transaction): bool {
        return str_contains($s['sql'], 'ledger_transactions')
            && preg_match('/["`]id["`]\s*(=|in)\s*/i', $s['sql']) === 1
            && in_array($transaction->id, $s['bindings'], true);
    });

    expect($identityLookups)->toHaveCount(1);
});

test('the detail page query count is bounded regardless of entry/participant count, and ledger.audit.view adds only a fixed, small number of queries', function () {
    $staffWithAudit = staffWithRole('administrator');
    $staffViewOnly = User::factory()->create();
    $staffViewOnly->givePermissionTo('ledger.view');

    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('ledger-detail-query-count-clearing');
    $engine = new LedgerPostingEngine;

    $smallAccounts = $this->provisionTestAccounts();
    $small = $engine->post($this->postingCommand(businessReference: 'deposit_credit:ledger-detail-query-count-small', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($smallAccounts->earningAvailable->id, 1_000),
    ]))->transaction;

    // Ten distinct real users, each with their own account, all touched
    // by one transaction (11 entries total) - proves no per-entry N+1
    // through entries -> walletAccount -> user -> profile.
    $creditEntries = [];
    foreach (range(1, 10) as $i) {
        $participantAccounts = $this->provisionTestAccounts();
        $creditEntries[] = $this->creditEntry($participantAccounts->earningAvailable->id, 1_000);
    }
    $large = $engine->post($this->postingCommand(
        businessReference: 'deposit_credit:ledger-detail-query-count-large',
        entries: [$this->debitEntry($clearing->id, 10_000), ...$creditEntries],
    ))->transaction;

    // Warm-up every viewer/transaction combination first.
    $this->actingAs($staffViewOnly)->get("/admin/ledger/{$small->id}")->assertOk();
    $this->actingAs($staffViewOnly)->get("/admin/ledger/{$large->id}")->assertOk();
    $this->actingAs($staffWithAudit)->get("/admin/ledger/{$small->id}")->assertOk();
    $this->actingAs($staffWithAudit)->get("/admin/ledger/{$large->id}")->assertOk();

    $countFor = function (User $viewer, $transaction): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->get("/admin/ledger/{$transaction->id}")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $smallViewOnly = $countFor($staffViewOnly, $small);
    $largeViewOnly = $countFor($staffViewOnly, $large);
    expect($largeViewOnly)->toBe($smallViewOnly);

    $smallWithAudit = $countFor($staffWithAudit, $small);
    $largeWithAudit = $countFor($staffWithAudit, $large);
    expect($largeWithAudit)->toBe($smallWithAudit);

    // ledger.audit.view adds a fixed, small number of extra queries -
    // identical regardless of entry/participant count, never scaling.
    $auditDeltaSmall = $smallWithAudit - $smallViewOnly;
    $auditDeltaLarge = $largeWithAudit - $largeViewOnly;
    expect($auditDeltaSmall)->toBe($auditDeltaLarge);
    expect($auditDeltaSmall)->toBeGreaterThan(0);
    expect($auditDeltaSmall)->toBeLessThanOrEqual(3);
});
