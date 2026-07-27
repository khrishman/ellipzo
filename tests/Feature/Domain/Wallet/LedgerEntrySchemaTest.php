<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('ledger_entries has the expected columns and no updated_at', function () {
    expect(Schema::hasColumns('ledger_entries', [
        'id', 'ledger_transaction_id', 'wallet_account_id', 'entry_type', 'amount_atomic', 'created_at',
    ]))->toBeTrue();

    expect(Schema::hasColumn('ledger_entries', 'updated_at'))->toBeFalse();
});

test('amount_atomic is genuinely signed, not unsigned', function () {
    $id = $this->insertRawLedgerEntry(['amount_atomic' => -123456]);

    expect((int) DB::table('ledger_entries')->where('id', $id)->value('amount_atomic'))->toBe(-123456);
});

test('an entry cannot reference a non-existent ledger transaction', function () {
    expect(fn () => $this->insertRawLedgerEntry(['ledger_transaction_id' => (string) Str::ulid()]))
        ->toThrow(QueryException::class);
});

test('an entry cannot reference a non-existent wallet account', function () {
    expect(fn () => $this->insertRawLedgerEntry(['wallet_account_id' => (string) Str::ulid()]))
        ->toThrow(QueryException::class);
});

test('ledger_transaction_id foreign key is restrictive', function () {
    $transactionId = $this->insertRawLedgerTransaction();
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $transactionId]);

    expect(fn () => DB::table('ledger_transactions')->where('id', $transactionId)->delete())
        ->toThrow(QueryException::class);
});

test('wallet_account_id foreign key is restrictive', function () {
    $walletAccountId = $this->insertRawWalletAccount();
    $this->insertRawLedgerEntry(['wallet_account_id' => $walletAccountId]);

    expect(fn () => DB::table('wallet_accounts')->where('id', $walletAccountId)->delete())
        ->toThrow(QueryException::class);
});

test('the id primary key is a 26-character ULID', function () {
    $id = $this->insertRawLedgerEntry();

    expect($id)->toHaveLength(26);
    expect((bool) Str::isUlid($id))->toBeTrue();
});

test('only the expected indexes exist on ledger_entries, with no accidental duplicates', function () {
    $indexes = Schema::getIndexes('ledger_entries');

    $primary = array_filter($indexes, fn (array $i): bool => $i['primary']);
    $plain = array_filter($indexes, fn (array $i): bool => ! $i['unique'] && ! $i['primary']);

    expect($primary)->toHaveCount(1);

    $plainColumnSets = array_map(fn (array $i): array => $i['columns'], array_values($plain));
    expect($plainColumnSets)->toContain(['ledger_transaction_id']);
    expect($plainColumnSets)->toContain(['wallet_account_id']);
    expect($plain)->toHaveCount(2);

    expect($indexes)->toHaveCount(3);
});
