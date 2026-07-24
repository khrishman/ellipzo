<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

test('a null-password (Google-created) user cannot authenticate with a password', function () {
    $user = User::forceCreate([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'email_verified_at' => now(),
    ]);
    expect($user->password)->toBeNull();

    $response = $this->from('/login')->post('/login', [
        'email' => 'ada@example.com',
        'password' => 'whatever-password',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('an existing password account continues to authenticate normally', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('password reset can securely set a password for a Google-created user through the existing reset flow', function () {
    $user = User::forceCreate([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'email_verified_at' => now(),
    ]);
    expect($user->password)->toBeNull();

    $token = Password::createToken($user);

    $response = $this->post('/reset-password', [
        'token' => $token,
        'email' => 'ada@example.com',
        'password' => 'NewPassword!123',
        'password_confirmation' => 'NewPassword!123',
    ]);

    $response->assertRedirect(route('login', absolute: false));

    $user->refresh();
    expect($user->password)->not->toBeNull();
    expect(Hash::check('NewPassword!123', $user->password))->toBeTrue();

    $loginResponse = $this->post('/login', [
        'email' => 'ada@example.com',
        'password' => 'NewPassword!123',
    ]);

    $this->assertAuthenticatedAs($user);
    $loginResponse->assertRedirect(route('dashboard', absolute: false));
});
