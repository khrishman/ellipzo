<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

test('the login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('auth/login'));
});

test('users can authenticate with correct credentials', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('login normalizes the email address before checking credentials', function () {
    $user = User::factory()->create(['email' => 'norm@example.com']);

    $response = $this->post('/login', [
        'email' => '  Norm@Example.COM  ',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('the session ID is regenerated after a successful login, preventing session fixation', function () {
    $user = User::factory()->create();

    $this->get('/login');
    $idBeforeLogin = $this->app['session']->getId();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $idAfterLogin = $this->app['session']->getId();

    expect($idAfterLogin)->not->toBe($idBeforeLogin);
});

test('login fails with a wrong password and shows a generic message that does not confirm the account exists', function () {
    $user = User::factory()->create();

    $response = $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toBe('These credentials do not match our records.');
    $this->assertGuest();
});

test('login fails for a nonexistent email with the exact same generic message as a wrong password', function () {
    $response = $this->from('/login')->post('/login', [
        'email' => 'nobody-at-all@example.com',
        'password' => 'whatever-password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toBe('These credentials do not match our records.');
    $this->assertGuest();
});

test('repeated failed login attempts for one account are rate limited by email and IP', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toContain('Too many login attempts');
    $this->assertGuest();
});

test('a successful login clears the rate limiter for that account', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $throttleKey = mb_strtolower($user->email).'|127.0.0.1';
    expect(RateLimiter::attempts($throttleKey))->toBe(0);
});

test('remember me issues a persistent remember token and remember cookie on login', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $this->assertAuthenticatedAs($user);

    $user->refresh();
    expect($user->remember_token)->not->toBeNull();

    $rememberCookieName = Auth::guard('web')->getRecallerName();
    $response->assertCookie($rememberCookieName);
});

test('without remember me, no remember cookie is issued', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);

    $rememberCookieName = Auth::guard('web')->getRecallerName();
    $response->assertCookieMissing($rememberCookieName);
});

test('an authenticated user cannot view or submit the login screen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/login')->assertRedirect(route('dashboard', absolute: false));
});

test('users can log out', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('the session is invalidated and the CSRF token rotated after logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard');
    $sessionIdBeforeLogout = $this->app['session']->getId();
    $tokenBeforeLogout = $this->app['session']->token();

    $this->post('/logout');

    expect($this->app['session']->getId())->not->toBe($sessionIdBeforeLogout);
    expect($this->app['session']->token())->not->toBe($tokenBeforeLogout);
});

test('logout deletes the database session row rather than merely clearing the guard', function () {
    $user = User::factory()->create();
    $sessionCookieName = config('session.cookie');

    $loginResponse = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $sessionIdBeforeLogout = $loginResponse->getCookie($sessionCookieName)->getValue();

    expect(DB::table('sessions')->where('id', $sessionIdBeforeLogout)->where('user_id', $user->id)->exists())->toBeTrue();

    $this->withCookie($sessionCookieName, $sessionIdBeforeLogout)->post('/logout');

    expect(DB::table('sessions')->where('id', $sessionIdBeforeLogout)->exists())->toBeFalse();
});

test('a guest cannot log out', function () {
    $response = $this->post('/logout');

    $response->assertRedirect('/login');
});

test('guests are redirected away from the protected dashboard', function () {
    $response = $this->get('/dashboard');

    $response->assertRedirect('/login');
});
