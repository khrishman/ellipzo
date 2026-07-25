<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

test('a guest is redirected to login for every admin route', function () {
    $this->get('/admin')->assertRedirect(route('login', absolute: false));
    $this->get('/admin/staff-access')->assertRedirect(route('login', absolute: false));
    $this->post('/admin/staff-access', [])->assertRedirect(route('login', absolute: false));
});

test('a normal authenticated user receives 403 for every admin route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
    $this->actingAs($user)->get('/admin/staff-access')->assertForbidden();
    $this->actingAs($user)->post('/admin/staff-access', [])->assertForbidden();
});

test('an unverified user cannot access the admin shell even with the right permission', function () {
    $user = User::factory()->unverified()->create();
    $user->assignRole('administrator');

    $response = $this->actingAs($user)->get('/admin');

    $response->assertRedirect(route('verification.notice', absolute: false));
});

test('staff with the required permission can access the overview route', function () {
    $user = User::factory()->create();
    $user->assignRole('moderator');

    $this->actingAs($user)->get('/admin')->assertOk();
});

test('staff lacking the required permission receives 403 on a route another role can access', function () {
    $user = User::factory()->create();
    $user->assignRole('support-agent');

    // support-agent has admin.overview.view but not staff.view.
    $this->actingAs($user)->get('/admin')->assertOk();
    $this->actingAs($user)->get('/admin/staff-access')->assertForbidden();
});

test('permissions do not leak between staff accounts', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $financeOperator = User::factory()->create();
    $financeOperator->assignRole('finance-operator');

    // Neither role includes staff.view/staff.manage.
    $this->actingAs($moderator)->get('/admin/staff-access')->assertForbidden();
    $this->actingAs($financeOperator)->get('/admin/staff-access')->assertForbidden();

    // Assigning finance-operator a role does not grant moderator's permissions.
    expect($financeOperator->can('campaigns.moderate'))->toBeFalse();
    expect($moderator->can('withdrawals.approve'))->toBeFalse();
});

test('having one admin permission does not grant unrelated permissions', function () {
    $user = User::factory()->create();
    $user->assignRole('support-agent');

    expect($user->can('admin.overview.view'))->toBeTrue();
    expect($user->can('support.view'))->toBeTrue();
    expect($user->can('staff.manage'))->toBeFalse();
    expect($user->can('deposits.review'))->toBeFalse();
    expect($user->can('ledger.adjust'))->toBeFalse();
});

test('client-supplied permission claims cannot authorize a backend action', function () {
    $user = User::factory()->create();
    $user->assignRole('moderator');

    // A moderator has no staff.manage permission. Posting a payload that
    // pretends otherwise (as if a tampered frontend prop were trusted)
    // has no effect - the server only ever consults the database.
    $response = $this->actingAs($user)->post('/admin/staff-access', [
        'email' => $user->email,
        'role' => 'administrator',
        'reason' => 'Pretending to have staff.manage via a forged payload.',
        'permissions' => ['staff.manage'],
    ]);

    $response->assertForbidden();
});
