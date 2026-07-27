<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('ledger_transactions has the expected columns and no updated_at', function () {
    expect(Schema::hasColumns('ledger_transactions', [
        'id', 'business_reference', 'type', 'currency_code', 'currency_scale',
        'description', 'actor_id', 'related_entity_type', 'related_entity_id',
        'correlation_id', 'reverses_transaction_id', 'created_at',
    ]))->toBeTrue();

    expect(Schema::hasColumn('ledger_transactions', 'updated_at'))->toBeFalse();
});

test('business_reference uniqueness is enforced at the database level', function () {
    $this->insertRawLedgerTransaction(['business_reference' => 'ref-dup']);

    expect(fn () => $this->insertRawLedgerTransaction(['business_reference' => 'ref-dup']))
        ->toThrow(QueryException::class);
});

test('reverses_transaction_id uniqueness is enforced, but multiple nulls are permitted', function () {
    $original = $this->insertRawLedgerTransaction();

    $this->insertRawLedgerTransaction(['reverses_transaction_id' => $original]);

    expect(fn () => $this->insertRawLedgerTransaction(['reverses_transaction_id' => $original]))
        ->toThrow(QueryException::class);

    // Two more separate rows with a null reverses_transaction_id must not
    // collide with each other, or with $original (also null by default) -
    // three null rows in total.
    $this->insertRawLedgerTransaction(['reverses_transaction_id' => null]);
    $this->insertRawLedgerTransaction(['reverses_transaction_id' => null]);

    expect(DB::table('ledger_transactions')->whereNull('reverses_transaction_id')->count())->toBe(3);
});

test('correlation_id is required at the database level', function () {
    expect(fn () => $this->insertRawLedgerTransaction(['correlation_id' => null]))
        ->toThrow(QueryException::class);
});

test('actor_id foreign key is restrictive', function () {
    $user = User::factory()->create();
    $this->insertRawLedgerTransaction(['actor_id' => $user->id]);

    expect(fn () => $user->delete())->toThrow(QueryException::class);
});

test('reverses_transaction_id self-referencing foreign key is restrictive', function () {
    $original = $this->insertRawLedgerTransaction();
    $this->insertRawLedgerTransaction(['reverses_transaction_id' => $original]);

    expect(fn () => DB::table('ledger_transactions')->where('id', $original)->delete())
        ->toThrow(QueryException::class);
});

test('the id primary key is a 26-character ULID', function () {
    $id = $this->insertRawLedgerTransaction();

    expect($id)->toHaveLength(26);
    expect((bool) Str::isUlid($id))->toBeTrue();
});

test('only the expected indexes exist on ledger_transactions, with no accidental duplicates', function () {
    $indexes = Schema::getIndexes('ledger_transactions');

    $primary = array_filter($indexes, fn (array $i): bool => $i['primary']);
    $unique = array_filter($indexes, fn (array $i): bool => $i['unique'] && ! $i['primary']);
    $plain = array_filter($indexes, fn (array $i): bool => ! $i['unique'] && ! $i['primary']);

    expect($primary)->toHaveCount(1);
    expect($unique)->toHaveCount(2);

    $uniqueColumnSets = array_map(fn (array $i): array => $i['columns'], array_values($unique));
    expect($uniqueColumnSets)->toContain(['business_reference']);
    expect($uniqueColumnSets)->toContain(['reverses_transaction_id']);

    $plainColumnSets = array_map(fn (array $i): array => $i['columns'], array_values($plain));
    expect($plainColumnSets)->toContain(['related_entity_type', 'related_entity_id']);
    expect($plainColumnSets)->toContain(['correlation_id']);
    expect($plain)->toHaveCount(2);

    expect($indexes)->toHaveCount(5);
});
