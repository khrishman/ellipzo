<?php

use App\Models\OAuthIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    // Registration also happens through this same completion flow, so
    // the legal documents need to be published for the success-path
    // tests, matching Task 6's own test convention.
    Config::set('legal.documents.terms', ['title' => 'Terms of Service', 'version' => 'test-terms-v1', 'published' => true]);
    Config::set('legal.documents.privacy', ['title' => 'Privacy Policy', 'version' => 'test-privacy-v1', 'published' => true]);
});

test('the redirect route uses the Google driver with the default session-bound flow, not stateless', function () {
    $response = $this->get('/auth/google/redirect');

    $response->assertRedirect();
    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://accounts.google.com/');
    expect($location)->toContain('state=');
    // Session-bound flow stores the state server-side to compare against
    // the callback; a stateless flow never does this.
    expect(session('state'))->not->toBeNull();
});

test('an already-linked Google identity logs the user in', function () {
    $user = User::factory()->create();
    $user->oauthIdentities()->create(['provider' => 'google', 'provider_user_id' => 'google-123']);

    $socialiteUser = SocialiteUser::fake([
        'id' => 'google-123',
        'name' => $user->name,
        'email' => $user->email,
        'email_verified' => true,
    ]);
    Socialite::shouldReceive('driver->user')->once()->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('a successful Google login regenerates the session', function () {
    $user = User::factory()->create();
    $user->oauthIdentities()->create(['provider' => 'google', 'provider_user_id' => 'google-123']);

    $this->get('/login');
    $idBeforeLogin = $this->app['session']->getId();

    $socialiteUser = SocialiteUser::fake([
        'id' => 'google-123',
        'name' => $user->name,
        'email' => $user->email,
        'email_verified' => true,
    ]);
    Socialite::shouldReceive('driver->user')->once()->andReturn($socialiteUser);

    $this->get('/auth/google/callback');

    expect($this->app['session']->getId())->not->toBe($idBeforeLogin);
});

test('a missing Google email is rejected and creates no pending identity or account', function () {
    $socialiteUser = SocialiteUser::fake([
        'id' => 'google-999',
        'name' => 'No Email',
        'email' => null,
        'email_verified' => true,
    ]);
    Socialite::shouldReceive('driver->user')->once()->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('login', absolute: false));
    $this->assertGuest();
    expect(User::count())->toBe(0);
    expect(session('oauth_pending'))->toBeNull();
});

test('an unverified Google email is rejected and creates no pending identity or account', function () {
    $socialiteUser = SocialiteUser::fake([
        'id' => 'google-999',
        'name' => 'Unverified',
        'email' => 'unverified@example.com',
        'email_verified' => false,
    ]);
    Socialite::shouldReceive('driver->user')->once()->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('login', absolute: false));
    $this->assertGuest();
    expect(User::where('email', 'unverified@example.com')->exists())->toBeFalse();
    expect(session('oauth_pending'))->toBeNull();
});

test('a Google email matching an existing password account is not automatically linked or logged in', function () {
    $existing = User::factory()->create(['email' => 'ada@example.com']);

    $socialiteUser = SocialiteUser::fake([
        'id' => 'google-777',
        'name' => 'Ada Impersonator',
        'email' => 'ada@example.com',
        'email_verified' => true,
    ]);
    Socialite::shouldReceive('driver->user')->once()->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('login', absolute: false));
    $this->assertGuest();
    expect(OAuthIdentity::where('provider_user_id', 'google-777')->exists())->toBeFalse();
    expect($existing->fresh()->oauthIdentities)->toHaveCount(0);
    expect(session('oauth_pending'))->toBeNull();
});

test('a brand new verified Google identity creates a pending identity but no user yet', function () {
    $socialiteUser = SocialiteUser::fake([
        'id' => 'google-555',
        'name' => 'Grace Hopper',
        'email' => '  Grace@Example.COM  ',
        'email_verified' => true,
    ]);
    Socialite::shouldReceive('driver->user')->once()->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('auth.google.complete', absolute: false));
    $this->assertGuest();
    expect(User::count())->toBe(0);

    $pending = session('oauth_pending');
    expect($pending)->not->toBeNull();
    expect($pending['provider'])->toBe('google');
    expect($pending['provider_user_id'])->toBe('google-555');
    // Normalized the same way every other email entry point in this
    // project is normalized.
    expect($pending['email'])->toBe('grace@example.com');
    expect($pending['name'])->toBe('Grace Hopper');
});

test('a cancelled or failed Google authorization is handled safely and logs only the exception class', function () {
    Socialite::shouldReceive('driver->user')->once()->andThrow(new Exception('access_denied'));

    Log::shouldReceive('warning')
        ->once()
        ->with('Google sign-in failed.', ['exception' => Exception::class]);

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('login', absolute: false));
    $this->assertGuest();
});

test('an invalid OAuth state is handled safely, the same as any other provider failure', function () {
    Socialite::shouldReceive('driver->user')->once()->andThrow(new InvalidStateException);

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('login', absolute: false));
    $this->assertGuest();
});

test('the OAuth identity table never stores tokens or raw provider payloads', function () {
    $columns = Schema::getColumnListing('oauth_identities');

    expect($columns)->toEqualCanonicalizing(['id', 'user_id', 'provider', 'provider_user_id', 'created_at', 'updated_at']);
});

test('a duplicate provider identity is rejected by the database', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $userA->oauthIdentities()->create(['provider' => 'google', 'provider_user_id' => 'dup-1']);

    expect(fn () => $userB->oauthIdentities()->create(['provider' => 'google', 'provider_user_id' => 'dup-1']))
        ->toThrow(QueryException::class);
});

test('a single user cannot hold two identities for the same provider', function () {
    $user = User::factory()->create();
    $user->oauthIdentities()->create(['provider' => 'google', 'provider_user_id' => 'first-id']);

    expect(fn () => $user->oauthIdentities()->create(['provider' => 'google', 'provider_user_id' => 'second-id']))
        ->toThrow(QueryException::class);
});
