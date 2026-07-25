<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

test('an active user reaches the dashboard normally', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

test('a limited user still reaches the dashboard normally', function () {
    $user = User::factory()->limited()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

test('a suspended user is redirected away from the dashboard to the restricted page', function () {
    $user = User::factory()->suspended()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertRedirect(route('account.restricted', absolute: false));
});

test('a closed user is redirected away from the dashboard to the restricted page', function () {
    $user = User::factory()->closed()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertRedirect(route('account.restricted', absolute: false));
});

test('a suspended user is redirected away from settings', function () {
    $user = User::factory()->suspended()->create();

    $response = $this->actingAs($user)->get('/settings/profile');

    $response->assertRedirect(route('account.restricted', absolute: false));
});

test('a suspended staff member cannot reach admin routes even while holding administrator permissions', function () {
    $staff = User::factory()->suspended()->create();
    $staff->assignRole('administrator');

    $response = $this->actingAs($staff)->get('/admin');

    $response->assertRedirect(route('account.restricted', absolute: false));
});

test('a closed staff member cannot reach admin routes even while holding administrator permissions', function () {
    $staff = User::factory()->closed()->create();
    $staff->assignRole('administrator');

    $response = $this->actingAs($staff)->get('/admin/staff-access');

    $response->assertRedirect(route('account.restricted', absolute: false));
});

test('a limited staff member reaches admin routes exactly as their permissions allow', function () {
    $staff = User::factory()->limited()->create();
    $staff->assignRole('administrator');

    $this->actingAs($staff)->get('/admin')->assertOk();
});

test('the restricted page is reachable by a suspended user without looping', function () {
    $user = User::factory()->suspended()->create();

    $this->actingAs($user)->get('/account/restricted')->assertOk();
});

test('the restricted page redirects an active user back to the dashboard, since there is nothing to show them', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/account/restricted');

    $response->assertRedirect(route('dashboard', absolute: false));
});

test('logout remains reachable by a suspended user', function () {
    $user = User::factory()->suspended()->create();

    $response = $this->actingAs($user)->post('/logout');

    $response->assertRedirect('/');
    $this->assertGuest();
});

test('a suspended and unverified user receives the restriction response, not the email-verification prompt', function () {
    $user = User::factory()->suspended()->unverified()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    // If 'verified' ran before the status check, this would redirect to
    // the verification notice instead - proving the required order
    // (auth -> account-status -> verified) is what actually executes,
    // not just what is declared.
    $response->assertRedirect(route('account.restricted', absolute: false));
});

test('a guest is redirected to login before any status check applies', function () {
    $this->get('/dashboard')->assertRedirect(route('login', absolute: false));
});
