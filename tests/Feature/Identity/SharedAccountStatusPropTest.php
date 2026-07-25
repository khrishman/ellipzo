<?php

use App\Models\User;

test('a guest gets a null shared account status', function () {
    $response = $this->get('/login');

    $response->assertInertia(fn ($page) => $page->where('auth.accountStatus', null));
});

test('a freshly authenticated active user gets an active shared account status with no refresh in between', function () {
    // User::factory()->create() returns an in-memory model that has never
    // been refresh()'d; actingAs() hands that same instance straight to
    // the Auth guard, exactly mirroring what a real login/registration
    // request does. If the model default did not work, this would throw
    // rather than silently share a wrong or missing value.
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page->where('auth.accountStatus', 'active'));
});

test('a suspended user gets their true shared account status, not the model default', function () {
    $user = User::factory()->suspended()->create();

    $response = $this->actingAs($user)->get('/account/restricted');

    $response->assertInertia(fn ($page) => $page->where('auth.accountStatus', 'suspended'));
});
