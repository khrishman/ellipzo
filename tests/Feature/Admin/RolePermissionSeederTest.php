<?php

use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('seeding creates the four documented roles with the smallest permission set, and only Administrator gets staff.manage', function () {
    (new RolePermissionSeeder)->run();

    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(['administrator', 'finance-operator', 'moderator', 'support-agent']);

    expect(Role::findByName('administrator')->hasPermissionTo('staff.manage'))->toBeTrue();
    expect(Role::findByName('moderator')->hasPermissionTo('staff.manage'))->toBeFalse();
    expect(Role::findByName('finance-operator')->hasPermissionTo('staff.manage'))->toBeFalse();
    expect(Role::findByName('support-agent')->hasPermissionTo('staff.manage'))->toBeFalse();
});

test('re-seeding is idempotent and does not duplicate roles or permissions', function () {
    (new RolePermissionSeeder)->run();
    $roleCountBefore = Role::count();
    $permissionCountBefore = Permission::count();

    (new RolePermissionSeeder)->run();

    expect(Role::count())->toBe($roleCountBefore);
    expect(Permission::count())->toBe($permissionCountBefore);
});

test('re-seeding preserves an unknown, operator-created role untouched', function () {
    (new RolePermissionSeeder)->run();

    $customRole = Role::create(['name' => 'custom-operator-role', 'guard_name' => 'web']);

    (new RolePermissionSeeder)->run();

    expect(Role::where('name', 'custom-operator-role')->exists())->toBeTrue();
    expect($customRole->fresh())->not->toBeNull();
});

test('no earner or advertiser role is ever created by the seeder', function () {
    (new RolePermissionSeeder)->run();

    expect(Role::whereIn('name', ['earner', 'advertiser'])->exists())->toBeFalse();
});

test('only Administrator receives users.status.manage, and users.limit is unaffected', function () {
    (new RolePermissionSeeder)->run();

    expect(Role::findByName('administrator')->hasPermissionTo('users.status.manage'))->toBeTrue();
    expect(Role::findByName('moderator')->hasPermissionTo('users.status.manage'))->toBeFalse();
    expect(Role::findByName('finance-operator')->hasPermissionTo('users.status.manage'))->toBeFalse();
    expect(Role::findByName('support-agent')->hasPermissionTo('users.status.manage'))->toBeFalse();

    // users.limit keeps its existing, separate meaning - untouched by
    // adding the new permission alongside it.
    expect(Role::findByName('administrator')->hasPermissionTo('users.limit'))->toBeTrue();
});

test('the seeder clears the Spatie permission cache', function () {
    $registrar = app(PermissionRegistrar::class);
    $cache = $registrar->getCacheRepository();

    // Prime the cache with something so we can prove it was forgotten.
    $cache->put($registrar->cacheKey, ['stale' => true], 60);
    expect($cache->has($registrar->cacheKey))->toBeTrue();

    (new RolePermissionSeeder)->run();

    // The cache now reflects freshly reconciled data, not the stale marker.
    $cached = $cache->get($registrar->cacheKey);
    expect($cached)->not->toBe(['stale' => true]);
});
