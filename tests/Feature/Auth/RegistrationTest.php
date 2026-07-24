<?php

use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    // Real registration requires the legal documents to actually be
    // published; most tests here are about other things (email format,
    // password strength, ...) so they get a published test fixture by
    // default. The dedicated "unpublished" test below overrides this back.
    Config::set('legal.documents.terms', [
        'title' => 'Terms of Service',
        'version' => 'test-terms-v1',
        'published' => true,
    ]);
    Config::set('legal.documents.privacy', [
        'title' => 'Privacy Policy',
        'version' => 'test-privacy-v1',
        'published' => true,
    ]);
});

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
        'terms' => true,
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
        'terms' => true,
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
        'terms' => true,
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
        'terms' => true,
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
        'terms' => true,
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
        'terms' => true,
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
        'terms' => true,
    ])->assertRedirect(route('dashboard', absolute: false));
});

test('registration is rejected when the legal terms checkbox is not accepted', function () {
    $response = $this->post('/register', [
        'name' => 'Someone',
        'email' => 'no-terms@example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
        'terms' => false,
    ]);

    $response->assertSessionHasErrors('terms');
    $this->assertGuest();
    expect(User::where('email', 'no-terms@example.com')->exists())->toBeFalse();
});

test('registration is rejected when the terms field is missing entirely', function () {
    $response = $this->post('/register', [
        'name' => 'Someone',
        'email' => 'missing-terms@example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ]);

    $response->assertSessionHasErrors('terms');
    $this->assertGuest();
});

test('registration is blocked while a required legal document is unpublished, and creates no user', function () {
    Config::set('legal.documents.terms.published', false);

    $response = $this->post('/register', [
        'name' => 'Someone',
        'email' => 'blocked@example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
        'terms' => true,
    ]);

    $response->assertSessionHasErrors('terms');
    $this->assertGuest();
    expect(User::where('email', 'blocked@example.com')->exists())->toBeFalse();
    expect(UserConsent::count())->toBe(0);
});

test('a successful registration atomically creates exactly two consent records with the correct version, timestamp, and method', function () {
    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada-consent@example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
        'terms' => true,
    ]);

    $user = User::where('email', 'ada-consent@example.com')->firstOrFail();

    expect(UserConsent::where('user_id', $user->id)->count())->toBe(2);

    $terms = UserConsent::where('user_id', $user->id)->where('document', 'terms')->firstOrFail();
    expect($terms->version)->toBe('test-terms-v1');
    expect($terms->method)->toBe('registration_checkbox');
    // The accepted_at column stores whole-second precision, so it can be
    // truncated to a moment fractionally earlier than a microsecond-precision
    // $before captured in PHP within the very same wall-clock second - a
    // tolerance check is the robust way to assert "just happened" here.
    expect($terms->accepted_at->diffInSeconds(now('UTC'), true))->toBeLessThan(5);
    expect($terms->accepted_at->timezone->getName())->toBe('UTC');

    $privacy = UserConsent::where('user_id', $user->id)->where('document', 'privacy')->firstOrFail();
    expect($privacy->version)->toBe('test-privacy-v1');
    expect($privacy->method)->toBe('registration_checkbox');
});

test('client-supplied consent version, method, or timestamp values in the registration payload are ignored', function () {
    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada-tamper@example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
        'terms' => true,
        // None of these are real fields the request accepts - proving
        // they have no effect is the point of this test.
        'version' => 'client-supplied-version',
        'method' => 'client-supplied-method',
        'accepted_at' => '2000-01-01 00:00:00',
    ]);

    $user = User::where('email', 'ada-tamper@example.com')->firstOrFail();
    $terms = UserConsent::where('user_id', $user->id)->where('document', 'terms')->firstOrFail();

    expect($terms->version)->toBe('test-terms-v1');
    expect($terms->method)->toBe('registration_checkbox');
    expect($terms->accepted_at->year)->toBe((int) now('UTC')->format('Y'));
});

test('a forced consent failure rolls back the user and all consent rows, and never authenticates', function () {
    UserConsent::creating(function (UserConsent $consent) {
        if ($consent->document === 'privacy') {
            throw new RuntimeException('Simulated consent failure.');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada-rollback@example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
        'terms' => true,
    ]))->toThrow(RuntimeException::class);

    $this->assertGuest();
    expect(User::where('email', 'ada-rollback@example.com')->exists())->toBeFalse();
    expect(UserConsent::count())->toBe(0);
});
