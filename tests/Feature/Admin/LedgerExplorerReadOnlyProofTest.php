<?php

use App\Domain\Wallet\Services\AdministrativeAdjustmentService;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\ReversalRequestService;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Two independent proofs that the ledger explorer never writes anything -
 * a grep-based check alone is not treated as sufficient, mirroring
 * WalletReconcileReadOnlyProofTest's own established technique exactly.
 */
function sourceWithoutCommentsFor(string $path): string
{
    $tokens = token_get_all(file_get_contents($path));
    $code = '';

    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

test('static audit: the controller and query service contain no mutation API call or reference to any financial write service', function () {
    $files = [
        app_path('Http/Controllers/Admin/AdminLedgerController.php'),
        app_path('Domain/Wallet/Services/AdminLedgerQuery.php'),
        app_path('Domain/Wallet/Services/AdminLedgerPresenter.php'),
    ];

    $forbiddenMutationCalls = [
        '->save(', '->create(', 'forceCreate(', '->insert(', 'DB::insert(',
        '->update(', 'DB::update(', '->delete(', 'DB::delete(', '->upsert(',
        '->increment(', '->decrement(',
        'DB::table(', 'DB::statement(', 'DB::unprepared(', 'Schema::',
    ];

    $forbiddenWriteServices = [
        'LedgerPostingEngine',
        'AdministrativeAdjustmentService',
        'ReversalRequestService',
        'WalletAccountProvisioner',
        'BalanceSnapshotService',
    ];

    foreach ($files as $file) {
        $source = sourceWithoutCommentsFor($file);

        foreach ($forbiddenMutationCalls as $needle) {
            expect($source)->not->toContain($needle);
        }

        foreach ($forbiddenWriteServices as $needle) {
            expect($source)->not->toContain($needle);
        }
    }
});

test('runtime proof: loading the populated ledger index and detail pages issues no wallet-domain mutation', function () {
    $staff = User::factory()->create();
    $staff->assignRole('administrator');

    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('readonly-proof-clearing');
    $engine = new LedgerPostingEngine;

    $original = $engine->post($this->postingCommand(businessReference: 'deposit_credit:admin-readonly-proof-1', entries: [
        $this->debitEntry($clearing->id, 400_000),
        $this->creditEntry($accounts->earningAvailable->id, 400_000),
    ]));

    (new ReversalRequestService($engine))->requestAndExecute(
        $this->reversalCommand($original->transaction->id, reason: 'Read-only proof reversal'),
    );

    $adjustmentActor = $this->ledgerAdjustActor();
    $adjustmentTarget = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($adjustmentTarget);
    app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($adjustmentActor, $adjustmentTarget));

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->actingAs($staff)->get('/admin/ledger')->assertOk();
    $this->actingAs($staff)->get("/admin/ledger/{$original->transaction->id}")->assertOk();

    expect($statements)->not->toBeEmpty();

    $mutationPattern = '/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i';
    $mutations = array_values(array_filter($statements, fn (string $sql): bool => preg_match($mutationPattern, $sql) === 1));

    // Only Laravel's own unavoidable session-table write is permitted -
    // CACHE_STORE=array and QUEUE_CONNECTION=sync in the test environment
    // mean it is the only table any GET request can legitimately write to.
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
});

test('table-content proof: every relevant table is byte-for-byte identical before and after loading both pages', function () {
    $staff = User::factory()->create();
    $staff->assignRole('administrator');

    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('readonly-proof-content-clearing');
    $engine = new LedgerPostingEngine;

    $transaction = $engine->post($this->postingCommand(businessReference: 'deposit_credit:admin-readonly-proof-content-1', entries: [
        $this->debitEntry($clearing->id, 250_000),
        $this->creditEntry($accounts->earningAvailable->id, 250_000),
    ]))->transaction;

    $adjustmentActor = $this->ledgerAdjustActor();
    $adjustmentTarget = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($adjustmentTarget);
    app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($adjustmentActor, $adjustmentTarget));

    $tables = ['users', 'wallet_accounts', 'ledger_transactions', 'ledger_entries', 'reversal_requests', 'audit_events', 'balance_snapshots'];

    $snapshot = fn () => collect($tables)->mapWithKeys(
        fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()]
    )->all();

    $before = $snapshot();

    $this->actingAs($staff)->get('/admin/ledger?type=deposit_credit')->assertOk();
    $this->actingAs($staff)->get("/admin/ledger/{$transaction->id}")->assertOk();

    $after = $snapshot();

    expect($after)->toEqual($before);
});
