<?php

use App\Models\User;
use Database\Seeders\LocalStaffSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

test('local staff seeding cannot execute in production, even if the config value is set', function () {
    $user = User::factory()->create(['email' => 'dev-admin@example.test']);

    $this->app['env'] = 'production';
    Config::set('staff.local_admin_email', 'dev-admin@example.test');

    (new LocalStaffSeeder)->run();

    expect($user->fresh()->hasRole('administrator'))->toBeFalse();
});

test('local staff seeding assigns Administrator to an existing user configured via LOCAL_STAFF_ADMIN_EMAIL, in testing', function () {
    $user = User::factory()->create(['email' => 'dev-admin@example.test']);

    Config::set('staff.local_admin_email', 'dev-admin@example.test');

    (new LocalStaffSeeder)->run();

    expect($user->fresh()->hasRole('administrator'))->toBeTrue();
});

test('local staff seeding never creates a user and never sets a password', function () {
    $countBefore = User::count();

    Config::set('staff.local_admin_email', 'nobody-configured@example.test');

    (new LocalStaffSeeder)->run();

    expect(User::count())->toBe($countBefore);
});

test('local staff seeding skips safely when the config value is absent', function () {
    Config::set('staff.local_admin_email', null);

    // Must not throw.
    (new LocalStaffSeeder)->run();

    expect(true)->toBeTrue();
});

test('local staff seeding is idempotent when the user already holds the role', function () {
    $user = User::factory()->create(['email' => 'dev-admin@example.test']);
    $user->assignRole('administrator');

    Config::set('staff.local_admin_email', 'dev-admin@example.test');

    (new LocalStaffSeeder)->run();

    expect($user->fresh()->roles()->where('name', 'administrator')->count())->toBe(1);
});
