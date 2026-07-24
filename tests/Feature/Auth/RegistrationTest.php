<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;

test('the registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('auth/register'));
});

test('a new user can register with valid data', function () {
    Event::fake([Registered::class]);

    $response = $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->name)->toBe('Ada Lovelace');
    expect($user->email_verified_at)->toBeNull();

    Event::assertDispatched(Registered::class, fn (Registered $event) => $event->user->is($user));
});

test('registration normalizes the email address to lowercase and trimmed before storing', function () {
    $response = $this->post('/register', [
        'name' => 'Grace Hopper',
        'email' => '  Grace@Example.COM  ',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));

    $this->assertDatabaseHas('users', ['email' => 'grace@example.com']);
    $this->assertDatabaseMissing('users', ['email' => '  Grace@Example.COM  ']);
});

test('registration is rejected for a duplicate email regardless of casing', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->post('/register', [
        'name' => 'Someone Else',
        'email' => 'Taken@Example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('registration requires a name', function () {
    $response = $this->post('/register', [
        'name' => '',
        'email' => 'someone@example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ]);

    $response->assertSessionHasErrors('name');
    $this->assertGuest();
});

test('registration rejects a password that does not meet complexity requirements', function () {
    $response = $this->post('/register', [
        'name' => 'Someone',
        'email' => 'weak-password@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('password');
    $this->assertGuest();
});

test('registration rejects a mismatched password confirmation', function () {
    $response = $this->post('/register', [
        'name' => 'Someone',
        'email' => 'mismatch@example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Different!123',
    ]);

    $response->assertSessionHasErrors('password');
    $this->assertGuest();
});

test('an authenticated user cannot view or submit the registration screen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/register')->assertRedirect(route('dashboard', absolute: false));
    $this->actingAs($user)->post('/register', [
        'name' => 'Someone',
        'email' => 'new@example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ])->assertRedirect(route('dashboard', absolute: false));
});
