<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

test('the forgot password screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('auth/forgot-password'));
});

test('a password reset link is emailed for an existing account', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('requesting a reset link for a nonexistent email returns the exact same response as for a real account', function () {
    Notification::fake();

    $existingUser = User::factory()->create(['email' => 'real@example.com']);

    $responseForRealAccount = $this->post('/forgot-password', ['email' => 'real@example.com']);
    $responseForFakeAccount = $this->post('/forgot-password', ['email' => 'no-such-account@example.com']);

    expect($responseForRealAccount->getSession()->get('status'))
        ->toBe($responseForFakeAccount->getSession()->get('status'));

    expect($responseForRealAccount->status())->toBe($responseForFakeAccount->status());

    // Exactly one notification was sent in total across both requests -
    // proves the nonexistent-email request did not also trigger a send.
    Notification::assertSentTo($existingUser, ResetPassword::class);
    Notification::assertCount(1);
});

test('a user can reset their password with a valid token', function () {
    Event::fake([PasswordReset::class]);

    $user = User::factory()->create();
    $token = Password::createToken($user);

    $response = $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword!123',
        'password_confirmation' => 'NewPassword!123',
    ]);

    $response->assertRedirect(route('login', absolute: false));
    Event::assertDispatched(PasswordReset::class);

    expect(Hash::check('NewPassword!123', $user->fresh()->password))->toBeTrue();
});

test('password reset normalizes the email before matching the token', function () {
    $user = User::factory()->create(['email' => 'reset-me@example.com']);
    $token = Password::createToken($user);

    $response = $this->post('/reset-password', [
        'token' => $token,
        'email' => '  Reset-Me@Example.COM  ',
        'password' => 'NewPassword!123',
        'password_confirmation' => 'NewPassword!123',
    ]);

    $response->assertRedirect(route('login', absolute: false));
    expect(Hash::check('NewPassword!123', $user->fresh()->password))->toBeTrue();
});

test('an invalid token is rejected', function () {
    $user = User::factory()->create();

    $response = $this->post('/reset-password', [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'NewPassword!123',
        'password_confirmation' => 'NewPassword!123',
    ]);

    $response->assertSessionHasErrors('email');
    expect(Hash::check('NewPassword!123', $user->fresh()->password))->toBeFalse();
});

test('resetting the password invalidates every existing session for that user', function () {
    $user = User::factory()->create();
    $sessionCookieName = config('session.cookie');

    $loginResponse = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $activeSessionId = $loginResponse->getCookie($sessionCookieName)->getValue();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBeGreaterThan(0);

    $token = Password::createToken($user);

    // The test harness reuses one app container (and one session store instance)
    // across simulated requests, so both the guard resolved by /login and the
    // session attributes it wrote would otherwise stay in memory and get
    // stamped onto this unrelated guest request's own session row. Forgetting
    // the guard and flushing the store mirrors what a real, separate HTTP
    // request would see: no cookie for this request, so no authenticated user.
    Auth::forgetGuards();
    $this->app['session']->flush();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword!123',
        'password_confirmation' => 'NewPassword!123',
    ]);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect(DB::table('sessions')->where('id', $activeSessionId)->exists())->toBeFalse();
});

test('resetting the password rejects a weak new password', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $response = $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('password');
});
