<?php

use App\Domain\Wallet\Services\AdministrativeAdjustmentService;
use App\Domain\Wallet\Services\BalanceSnapshotService;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\ReversalRequestService;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Two independent proofs that wallet:reconcile never writes anything - a
 * grep-based check alone is not treated as sufficient, per explicit
 * instruction.
 */
/**
 * Strips comments and docblocks via PHP's own tokenizer before scanning -
 * this class's own docblocks explain, in prose, exactly which write APIs
 * and services it must never use, which would otherwise make a naive
 * substring search over the raw file false-positive on its own
 * documentation.
 */
function sourceWithoutComments(string $path): string
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

test('static audit: the command source contains no mutation API call or reference to any financial write service', function () {
    $source = sourceWithoutComments(app_path('Console/Commands/ReconcileWalletLedgerCommand.php'));

    $forbiddenMutationCalls = [
        '->save(',
        '->create(',
        'forceCreate(',
        '->insert(',
        'DB::insert(',
        '->update(',
        'DB::update(',
        '->delete(',
        'DB::delete(',
        '->upsert(',
        'DB::table(',
        'DB::statement(',
        'DB::unprepared(',
        'Schema::',
    ];

    foreach ($forbiddenMutationCalls as $needle) {
        expect($source)->not->toContain($needle);
    }

    $forbiddenWriteServices = [
        'LedgerPostingEngine',
        'AdministrativeAdjustmentService',
        'ReversalRequestService',
        'WalletAccountProvisioner',
        'BalanceSnapshotService',
    ];

    foreach ($forbiddenWriteServices as $needle) {
        expect($source)->not->toContain($needle);
    }
});

test('runtime proof: executing wallet:reconcile against a real, populated, corrupted ledger issues no mutating SQL statement', function () {
    // A real, varied dataset - legitimate postings, a reversal, an
    // administrative adjustment, a snapshot, and one deliberately corrupt
    // row - so the command actually exercises every read path, not just
    // an empty-table no-op.
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('readonly-proof-clearing');
    $engine = new LedgerPostingEngine;

    $original = $engine->post($this->postingCommand(businessReference: 'deposit_credit:readonly-proof-1', entries: [
        $this->debitEntry($clearing->id, 400_000),
        $this->creditEntry($accounts->earningAvailable->id, 400_000),
    ]));

    $reversalService = new ReversalRequestService($engine);
    $reversalService->requestAndExecute($this->reversalCommand($original->transaction->id, reason: 'Read-only proof reversal'));

    $adjustmentActor = $this->ledgerAdjustActor();
    $adjustmentTarget = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($adjustmentTarget);
    app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($adjustmentActor, $adjustmentTarget));

    (new BalanceSnapshotService)->captureForAccount($accounts->earningAvailable);

    // A deliberately corrupt row - too few entries - so at least one
    // discrepancy is guaranteed, proving the command stays read-only even
    // while actively reporting a finding.
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $this->insertRawLedgerTransaction()]);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $exitCode = Artisan::call('wallet:reconcile');

    expect($exitCode)->toBe(1);
    expect($statements)->not->toBeEmpty();

    $mutationPattern = '/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i';

    foreach ($statements as $sql) {
        expect($sql)->not->toMatch($mutationPattern);
    }
});

test('table-content proof: every relevant table is byte-for-byte identical before and after a run that finds discrepancies', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('readonly-proof-content-clearing');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:readonly-proof-content-1', entries: [
        $this->debitEntry($clearing->id, 250_000),
        $this->creditEntry($accounts->earningAvailable->id, 250_000),
    ]));

    (new BalanceSnapshotService)->captureForAccount($accounts->earningAvailable);

    // A deliberately corrupt row, guaranteeing at least one discrepancy.
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $this->insertRawLedgerTransaction()]);

    $tables = [
        'users',
        'wallet_accounts',
        'ledger_transactions',
        'ledger_entries',
        'reversal_requests',
        'audit_events',
        'balance_snapshots',
    ];

    $snapshot = fn () => collect($tables)->mapWithKeys(
        fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()]
    )->all();

    $before = $snapshot();

    $exitCode = Artisan::call('wallet:reconcile');

    $after = $snapshot();

    expect($exitCode)->toBe(1);
    expect($after)->toEqual($before);
});
