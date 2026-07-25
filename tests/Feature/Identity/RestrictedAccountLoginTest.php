<?php

use App\Enums\AccountStatus;
use App\Models\User;
use App\Support\AccountStatusTransitioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('a suspended user can complete password login, but their next protected request reaches only the restricted experience', function () {
    $user = User::factory()->suspended()->create();

    $loginResponse = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    // Authentication itself succeeds - the login mechanism is not weakened.
    $this->assertAuthenticatedAs($user);

    $dashboardResponse = $this->get('/dashboard');
    $dashboardResponse->assertRedirect(route('account.restricted', absolute: false));
});

test('a closed user can complete password login, but their next protected request reaches only the restricted experience', function () {
    $user = User::factory()->closed()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $this->get('/dashboard')->assertRedirect(route('account.restricted', absolute: false));
});

test('a suspended user with an already-linked Google identity can complete Google login, but their next protected request is restricted', function () {
    $user = User::factory()->suspended()->create();
    $user->oauthIdentities()->create(['provider' => 'google', 'provider_user_id' => 'google-restricted-1']);

    $socialiteUser = SocialiteUser::fake([
        'id' => 'google-restricted-1',
        'name' => $user->name,
        'email' => $user->email,
        'email_verified' => true,
    ]);
    Socialite::shouldReceive('driver->user')->once()->andReturn($socialiteUser);

    $this->get('/auth/google/callback');

    $this->assertAuthenticatedAs($user);
    $this->get('/dashboard')->assertRedirect(route('account.restricted', absolute: false));
});

test('suspending an already logged-in user deletes their real database session row', function () {
    (new RolePermissionSeeder)->run();
    $user = User::factory()->create();
    $sessionCookieName = config('session.cookie');

    $loginResponse = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $sessionId = $loginResponse->getCookie($sessionCookieName)->getValue();

    expect(DB::table('sessions')->where('id', $sessionId)->where('user_id', $user->id)->exists())->toBeTrue();

    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    app(AccountStatusTransitioner::class)->transition(
        $admin,
        $user,
        AccountStatus::Suspended,
        'Suspending an already-logged-in user.',
    );

    expect(DB::table('sessions')->where('id', $sessionId)->exists())->toBeFalse();

    // Without this, the in-memory Auth guard from the earlier real login
    // call above persists across sequential test calls regardless of
    // cookies - a real, separate HTTP request would never see this
    // leftover state. Forgetting the guard and flushing the session store
    // mirrors what a genuinely new request would see: an established
    // precedent from PasswordResetTest.php's own equivalent scenario.
    Auth::forgetGuards();
    $this->app['session']->flush();

    // A follow-up request carrying that now-deleted session cookie is
    // simply unauthenticated, like any other expired session - not
    // specifically routed to the restriction page, since there is no
    // session left to identify them by at all.
    $this->withCookie($sessionCookieName, $sessionId)->get('/dashboard')
        ->assertRedirect(route('login', absolute: false));
});
