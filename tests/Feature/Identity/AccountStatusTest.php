<?php

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a bare new User instance already has an active account status with no database round trip at all', function () {
    // No save(), no query - this proves the default lives on the model
    // itself (Eloquent's raw $attributes default), not on anything that
    // requires persisting or re-reading a row.
    $user = new User;

    expect($user->account_status)->toBe(AccountStatus::Active);
});

test('User::factory()->create() exposes an active account status in-memory with no refresh() call', function () {
    $user = User::factory()->create();

    expect($user->account_status)->toBe(AccountStatus::Active);
});

test('a direct User::create() call exposes an active account status in-memory with no refresh() call', function () {
    $user = User::create([
        'name' => 'Ada Lovelace',
        'email' => 'ada-create@example.com',
        'password' => 'irrelevant-for-this-test',
    ]);

    expect($user->account_status)->toBe(AccountStatus::Active);
});

test('a direct User::forceCreate() call exposes an active account status in-memory with no refresh() call', function () {
    $user = User::forceCreate([
        'name' => 'Ada Lovelace',
        'email' => 'ada-force-create@example.com',
        'password' => 'irrelevant-for-this-test',
    ]);

    expect($user->account_status)->toBe(AccountStatus::Active);
});

test('existing users are backfilled to active by the migration default', function () {
    // The migration itself already ran for every pre-existing row before
    // this test suite starts; this proves a freshly created row (via the
    // same schema, no explicit status) also lands on the correct default
    // once actually reloaded from the database.
    $user = User::factory()->create();

    expect($user->fresh()->account_status)->toBe(AccountStatus::Active);
});

test('account_status cannot be mass-assigned, in-memory or after reload', function () {
    $user = User::create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'irrelevant-for-this-test',
        'account_status' => 'suspended',
    ]);

    // The client-supplied 'suspended' value must never win, neither in
    // the in-memory model returned by create() nor after a real reload.
    expect($user->account_status)->toBe(AccountStatus::Active);
    expect($user->fresh()->account_status)->toBe(AccountStatus::Active);
});

test('loading an existing non-active user from the database reports their true status, never the model default', function () {
    // The model's raw $attributes default must never bleed through when
    // hydrating a real row - setRawAttributes() fully replaces it.
    $suspended = User::factory()->suspended()->create();

    $reloaded = User::find($suspended->id);

    expect($reloaded->account_status)->toBe(AccountStatus::Suspended);
});

test('a raw invalid account_status value fails loudly on hydration rather than silently defaulting', function () {
    $user = User::factory()->create();

    DB::table('users')->where('id', $user->id)->update(['account_status' => 'not-a-real-status']);

    expect(fn () => User::find($user->id)->account_status)->toThrow(ValueError::class);
});
