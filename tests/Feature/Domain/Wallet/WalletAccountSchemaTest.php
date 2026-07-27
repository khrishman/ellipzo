<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('wallet_accounts has the expected columns and no updated_at', function () {
    expect(Schema::hasColumns('wallet_accounts', [
        'id', 'scope_type', 'scope_key', 'user_id', 'account_type',
        'currency_code', 'currency_scale', 'created_at',
    ]))->toBeTrue();

    expect(Schema::hasColumn('wallet_accounts', 'updated_at'))->toBeFalse();
});

test('the composite identity unique index is enforced at the database level', function () {
    $user = User::factory()->create();

    $this->insertRawWalletAccount([
        'scope_type' => 'user', 'scope_key' => (string) $user->id, 'user_id' => $user->id,
        'account_type' => 'earning_available',
    ]);

    expect(fn () => $this->insertRawWalletAccount([
        'scope_type' => 'user', 'scope_key' => (string) $user->id, 'user_id' => $user->id,
        'account_type' => 'earning_available',
    ]))->toThrow(QueryException::class);
});

test('user_id foreign key is restrictive', function () {
    $user = User::factory()->create();

    $this->insertRawWalletAccount([
        'scope_type' => 'user', 'scope_key' => (string) $user->id, 'user_id' => $user->id,
        'account_type' => 'earning_available',
    ]);

    expect(fn () => $user->delete())->toThrow(QueryException::class);
    expect(User::find($user->id))->not->toBeNull();
});

test('the id primary key is a 26-character ULID', function () {
    $id = $this->insertRawWalletAccount();

    expect($id)->toHaveLength(26);
    expect((bool) Str::isUlid($id))->toBeTrue();
});

test('only the expected indexes exist on wallet_accounts, with no accidental duplicates', function () {
    $indexes = Schema::getIndexes('wallet_accounts');

    $primary = array_filter($indexes, fn (array $i): bool => $i['primary']);
    $unique = array_filter($indexes, fn (array $i): bool => $i['unique'] && ! $i['primary']);
    $plain = array_filter($indexes, fn (array $i): bool => ! $i['unique'] && ! $i['primary']);

    expect($primary)->toHaveCount(1);
    expect($unique)->toHaveCount(1);
    expect(array_values($unique)[0]['columns'])->toBe(['scope_type', 'scope_key', 'account_type', 'currency_code']);
    expect($plain)->toHaveCount(1);
    expect(array_values($plain)[0]['columns'])->toBe(['user_id']);

    expect($indexes)->toHaveCount(3);
});
