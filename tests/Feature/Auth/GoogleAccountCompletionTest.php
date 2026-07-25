<?php

use App\Enums\AccountStatus;
use App\Models\OAuthIdentity;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('legal.documents.terms', ['title' => 'Terms of Service', 'version' => 'test-terms-v1', 'published' => true]);
    Config::set('legal.documents.privacy', ['title' => 'Privacy Policy', 'version' => 'test-privacy-v1', 'published' => true]);
});

function seedPendingGoogleIdentity(array $overrides = []): void
{
    session()->put('oauth_pending', array_merge([
        'provider' => 'google',
        'provider_user_id' => 'google-123',
        'email' => 'ada@example.com',
        'name' => 'Ada Lovelace',
        'expires_at' => now('UTC')->addMinutes(10)->timestamp,
    ], $overrides));
}

test('the completion screen redirects to login when there is no pending identity', function () {
    $response = $this->get('/auth/google/complete');

    $response->assertRedirect(route('login', absolute: false));
});

test('the completion screen renders for a valid pending identity', function () {
    $this->withSession([])->get('/'); // establish a session
    seedPendingGoogleIdentity();

    $response = $this->get('/auth/google/complete');

    $response->assertStatus(200);
    $response->assertInertia(
        fn ($page) => $page->component('auth/google-complete')
            ->where('name', 'Ada Lovelace')
            ->where('email', 'ada@example.com')
            ->where('documentsPublished', true)
    );
});

test('submitting without accepting terms is rejected and creates nothing', function () {
    seedPendingGoogleIdentity();

    $response = $this->post('/auth/google/complete', ['terms' => false]);

    $response->assertSessionHasErrors('terms');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('a published, accepted completion atomically creates the user, identity, and two consent records', function () {
    seedPendingGoogleIdentity();

    $response = $this->post('/auth/google/complete', ['terms' => true]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->name)->toBe('Ada Lovelace');
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->password)->toBeNull();

    $identity = OAuthIdentity::where('user_id', $user->id)->first();
    expect($identity)->not->toBeNull();
    expect($identity->provider)->toBe('google');
    expect($identity->provider_user_id)->toBe('google-123');

    expect(UserConsent::where('user_id', $user->id)->count())->toBe(2);
    expect(UserConsent::where('user_id', $user->id)->where('document', 'terms')->where('method', 'google_oauth')->exists())->toBeTrue();
    expect(UserConsent::where('user_id', $user->id)->where('document', 'privacy')->where('method', 'google_oauth')->exists())->toBeTrue();
});

test('a Google-completed account defaults to an active account status', function () {
    seedPendingGoogleIdentity();

    $this->post('/auth/google/complete', ['terms' => true]);

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->account_status)->toBe(AccountStatus::Active);
});

test('a successful completion regenerates the session and clears the pending identity', function () {
    $this->withSession([])->get('/');
    seedPendingGoogleIdentity();
    $idBeforeCompletion = $this->app['session']->getId();

    $this->post('/auth/google/complete', ['terms' => true]);

    expect($this->app['session']->getId())->not->toBe($idBeforeCompletion);
    expect(session('oauth_pending'))->toBeNull();
});

test('unpublished required documents prevent account creation, even with terms accepted', function () {
    Config::set('legal.documents.terms.published', false);
    seedPendingGoogleIdentity();

    $response = $this->post('/auth/google/complete', ['terms' => true]);

    $response->assertSessionHasErrors('terms');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('client-supplied identity, email, and version fields in the completion payload are ignored', function () {
    seedPendingGoogleIdentity();

    $this->post('/auth/google/complete', [
        'terms' => true,
        'email' => 'attacker@example.com',
        'provider_user_id' => 'attacker-supplied-id',
        'name' => 'Attacker Name',
        'version' => 'attacker-version',
    ]);

    $user = User::where('email', 'ada@example.com')->first();
    expect($user)->not->toBeNull();
    expect(User::where('email', 'attacker@example.com')->exists())->toBeFalse();

    $identity = OAuthIdentity::where('user_id', $user->id)->first();
    expect($identity->provider_user_id)->toBe('google-123');
});

test('a forced identity failure rolls back the user, identity, and all consent rows, and never authenticates', function () {
    seedPendingGoogleIdentity();

    OAuthIdentity::creating(function () {
        throw new RuntimeException('Simulated identity failure.');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/auth/google/complete', ['terms' => true]))
        ->toThrow(RuntimeException::class);

    $this->assertGuest();
    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
    expect(UserConsent::count())->toBe(0);
    expect(OAuthIdentity::count())->toBe(0);
});

test('a forced consent failure rolls back the user and identity too, and never authenticates', function () {
    seedPendingGoogleIdentity();

    UserConsent::creating(function (UserConsent $consent) {
        if ($consent->document === 'privacy') {
            throw new RuntimeException('Simulated consent failure.');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/auth/google/complete', ['terms' => true]))
        ->toThrow(RuntimeException::class);

    $this->assertGuest();
    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
    expect(OAuthIdentity::count())->toBe(0);
    expect(UserConsent::count())->toBe(0);
});

test('an expired pending identity is rejected and cleared, and creates no account', function () {
    seedPendingGoogleIdentity(['expires_at' => now('UTC')->subMinute()->timestamp]);

    $response = $this->post('/auth/google/complete', ['terms' => true]);

    $response->assertRedirect(route('login', absolute: false));
    $this->assertGuest();
    expect(User::count())->toBe(0);
    expect(session('oauth_pending'))->toBeNull();
});

test('a completed pending identity cannot be replayed into a second account', function () {
    seedPendingGoogleIdentity();

    $this->post('/auth/google/complete', ['terms' => true]);
    expect(User::count())->toBe(1);

    // Simulate the browser resubmitting the same completed form (e.g. via
    // back-button). The pending session data is already gone, and the
    // completion routes are guest-only, so the now-authenticated user is
    // simply redirected to their own dashboard rather than anything
    // being replayed - either way, no second account is created.
    $replay = $this->post('/auth/google/complete', ['terms' => true]);

    $replay->assertRedirect(route('dashboard', absolute: false));
    expect(User::count())->toBe(1);
});
