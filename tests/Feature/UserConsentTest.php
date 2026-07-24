<?php

use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;

test('a consent record cannot be updated via mass assignment', function () {
    $user = User::factory()->create();
    $consent = UserConsent::forceCreate([
        'user_id' => $user->id,
        'document' => 'terms',
        'version' => 'v1',
        'accepted_at' => now('UTC'),
        'method' => 'registration_checkbox',
    ]);

    expect(fn () => $consent->update(['version' => 'tampered']))->toThrow(MassAssignmentException::class);
});

test('a consent record cannot be updated even by direct property assignment, bypassing mass assignment entirely', function () {
    $user = User::factory()->create();
    $consent = UserConsent::forceCreate([
        'user_id' => $user->id,
        'document' => 'terms',
        'version' => 'v1',
        'accepted_at' => now('UTC'),
        'method' => 'registration_checkbox',
    ]);

    $consent->version = 'tampered';

    expect(fn () => $consent->save())->toThrow(LogicException::class);
});

test('a consent record cannot be deleted through application code', function () {
    $user = User::factory()->create();
    $consent = UserConsent::forceCreate([
        'user_id' => $user->id,
        'document' => 'terms',
        'version' => 'v1',
        'accepted_at' => now('UTC'),
        'method' => 'registration_checkbox',
    ]);

    expect(fn () => $consent->delete())->toThrow(LogicException::class);
});

test('deleting a user with consent records is blocked by the restrictive foreign key', function () {
    $user = User::factory()->create();
    UserConsent::forceCreate([
        'user_id' => $user->id,
        'document' => 'terms',
        'version' => 'v1',
        'accepted_at' => now('UTC'),
        'method' => 'registration_checkbox',
    ]);

    expect(fn () => $user->delete())->toThrow(QueryException::class);

    expect(User::find($user->id))->not->toBeNull();
});

test('a duplicate user, document, and version combination is rejected by the database', function () {
    $user = User::factory()->create();
    UserConsent::forceCreate([
        'user_id' => $user->id,
        'document' => 'terms',
        'version' => 'v1',
        'accepted_at' => now('UTC'),
        'method' => 'registration_checkbox',
    ]);

    expect(fn () => UserConsent::forceCreate([
        'user_id' => $user->id,
        'document' => 'terms',
        'version' => 'v1',
        'accepted_at' => now('UTC'),
        'method' => 'registration_checkbox',
    ]))->toThrow(QueryException::class);
});

test('the same user can hold consent rows for different documents or versions', function () {
    $user = User::factory()->create();

    UserConsent::forceCreate([
        'user_id' => $user->id, 'document' => 'terms', 'version' => 'v1',
        'accepted_at' => now('UTC'), 'method' => 'registration_checkbox',
    ]);
    UserConsent::forceCreate([
        'user_id' => $user->id, 'document' => 'privacy', 'version' => 'v1',
        'accepted_at' => now('UTC'), 'method' => 'registration_checkbox',
    ]);
    UserConsent::forceCreate([
        'user_id' => $user->id, 'document' => 'terms', 'version' => 'v2',
        'accepted_at' => now('UTC'), 'method' => 'registration_checkbox',
    ]);

    expect(UserConsent::where('user_id', $user->id)->count())->toBe(3);
});

test('none of a consent record\'s columns are mass assignable', function () {
    $user = User::factory()->create();

    expect(fn () => UserConsent::create([
        'user_id' => $user->id,
        'document' => 'terms',
        'version' => 'v1',
        'accepted_at' => now('UTC'),
        'method' => 'registration_checkbox',
    ]))->toThrow(MassAssignmentException::class);
});
