<?php

use App\Models\User;

test('the password confirmation screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/confirm-password');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('auth/confirm-password'));
});

test('password can be confirmed with the correct password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/confirm-password', [
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionHas('auth.password_confirmed_at');
});

test('password confirmation is rejected with an incorrect password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->from('/confirm-password')->post('/confirm-password', [
        'password' => 'wrong-password',
    ]);

    $response->assertRedirect('/confirm-password');
    $response->assertSessionHasErrors('password');
    $this->assertNull(session('auth.password_confirmed_at'));
});

test('a guest cannot reach the password confirmation screen', function () {
    $response = $this->get('/confirm-password');

    $response->assertRedirect('/login');
});
