<?php

use App\Models\Country;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // country_code is now validated against the real countries table
    // (Task 7). These fixtures cover every code used across this file's
    // pre-existing tests, independent of the country-capability seeder.
    Country::create(['code' => 'US', 'name' => 'United States']);
    Country::create(['code' => 'NP', 'name' => 'Nepal']);
});

test('the profile settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/settings/profile');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('settings/profile'));
});

test('a guest is redirected away from the profile settings page', function () {
    $response = $this->get('/settings/profile');

    $response->assertRedirect('/login');
});

test('an unverified user is redirected to the verification prompt instead of reaching profile settings', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/settings/profile');

    $response->assertRedirect(route('verification.notice'));
});

test('a user can update their own profile, and it is created on first save', function () {
    $user = User::factory()->create();
    expect($user->profile)->toBeNull();

    $response = $this->actingAs($user)->patch('/settings/profile', [
        'username' => 'AdaLovelace1815',
        'date_of_birth' => '1990-05-14',
        'country_code' => 'us',
        'locale' => 'en-US',
        'timezone' => 'America/New_York',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'profile-updated');

    $user->refresh();
    expect($user->profile)->not->toBeNull();
    expect($user->profile->date_of_birth->toDateString())->toBe('1990-05-14');
    expect($user->profile->country_code)->toBe('US');
    expect($user->profile->locale)->toBe('en-US');
    expect($user->profile->timezone)->toBe('America/New_York');
});

test('updating a profile never touches another user\'s profile row', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    UserProfile::factory()->for($userB)->create(['username' => 'existing-b']);

    $this->actingAs($userA)->patch('/settings/profile', [
        'timezone' => 'Europe/London',
    ]);

    $userB->refresh();
    expect($userB->profile->username)->toBe('existing-b');
    expect($userB->profile->timezone)->not->toBe('Europe/London');
});

test('submitting a user_id in the payload has no effect - mass assignment is blocked', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user)->patch('/settings/profile', [
        'user_id' => $otherUser->id,
        'timezone' => 'UTC',
    ]);

    $user->refresh();
    expect($user->profile->user_id)->toBe($user->id);
    expect($otherUser->fresh()->profile)->toBeNull();
});

test('username casing is preserved for display but "JohnDoe" and "johndoe" cannot belong to different users, even under SQLite', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA)->patch('/settings/profile', ['username' => 'JohnDoe']);

    $userA->refresh();
    expect($userA->profile->username)->toBe('JohnDoe');
    expect($userA->profile->username_normalized)->toBe('johndoe');

    $response = $this->actingAs($userB)->patch('/settings/profile', ['username' => 'johndoe']);

    $response->assertSessionHasErrors('username');
    expect($userB->fresh()->profile)->toBeNull();
});

test('the database unique index on username_normalized is the final race-condition guard', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // Created through the relation (as the controller does), not a bare
    // UserProfile::create(), since user_id is deliberately not fillable.
    $userA->profile()->create(['username' => 'racer']);

    expect(fn () => DB::table('user_profiles')->insert([
        'user_id' => $userB->id,
        'username' => 'RACER',
        'username_normalized' => 'racer',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a username claimed by another request between validation and persistence is reported as a normal validation error, not a 500', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    UserProfile::factory()->for($userB)->create(['username' => 'OldName']);

    // Simulates the exact race the controller has to close: at the moment
    // User B's request passed Form Request validation, nobody held
    // "racer" yet, so the pre-emptive check let it through. Right before
    // User B's own row is updated, User A's request "wins" and takes it
    // first - reproduced deterministically via Eloquent's `updating`
    // event rather than real concurrency, which a synchronous test can't
    // otherwise construct.
    //
    // The update path is used deliberately, not create: updateOrCreate()'s
    // create path wraps its insert in its own savepoint (via
    // withSavepointIfNeeded), and RefreshDatabase already wraps this whole
    // test in an outer transaction - so an insert made inside a `creating`
    // listener would be nested inside that savepoint and get rolled back
    // together with the failed insert, silently erasing the very
    // collision being simulated. A plain update (User B already has a
    // profile row) isn't savepoint-wrapped, so User A's simulated insert
    // survives to be seen by the controller's recovery query, exactly as
    // a real, separately-committed concurrent request's row would.
    UserProfile::updating(function (UserProfile $profile) use ($userA, $userB) {
        if ((int) $profile->getAttribute('user_id') === $userB->id) {
            DB::table('user_profiles')->insert([
                'user_id' => $userA->id,
                'username' => 'Racer',
                'username_normalized' => 'racer',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $response = $this->actingAs($userB)->patch('/settings/profile', ['username' => 'Racer']);

    $response->assertStatus(302);
    $response->assertSessionHasErrors('username');

    expect(UserProfile::find($userA->id)?->username)->toBe('Racer');
    expect(UserProfile::find($userB->id)?->username)->toBe('OldName');
});

test('a unique-constraint violation unrelated to username is rethrown, not misreported as a username error', function () {
    $user = User::factory()->create();

    // A different race: something else already created this exact user's
    // profile row (same user_id, a primary-key collision) between
    // validation and this request's own write. This has nothing to do
    // with the submitted username, so it must not be swallowed and
    // reported as a username error.
    UserProfile::creating(function (UserProfile $profile) use ($user) {
        if ((int) $profile->getAttribute('user_id') === $user->id) {
            DB::table('user_profiles')->insert([
                'user_id' => $user->id,
                'username' => null,
                'username_normalized' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->patch('/settings/profile', ['username' => 'freshname']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a username can be cleared back to null', function () {
    $user = User::factory()->create();
    UserProfile::factory()->for($user)->create(['username' => 'has-a-name']);

    $this->actingAs($user)->patch('/settings/profile', ['username' => null]);

    $user->refresh();
    expect($user->profile->username)->toBeNull();
    expect($user->profile->username_normalized)->toBeNull();
});

test('a username with disallowed characters is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/settings/profile', ['username' => 'not valid!']);

    $response->assertSessionHasErrors('username');
});

test('a username shorter than the minimum length is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/settings/profile', ['username' => 'ab']);

    $response->assertSessionHasErrors('username');
});

test('a future date of birth is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/settings/profile', [
        'date_of_birth' => now()->addDay()->toDateString(),
    ]);

    $response->assertSessionHasErrors('date_of_birth');
});

test('a date of birth before the year 1900 is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/settings/profile', [
        'date_of_birth' => '1899-12-31',
    ]);

    $response->assertSessionHasErrors('date_of_birth');
});

test('a date of birth in a non-Y-m-d format is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/settings/profile', [
        'date_of_birth' => '05/14/1990',
    ]);

    $response->assertSessionHasErrors('date_of_birth');
});

test('a malformed country code is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/settings/profile', ['country_code' => 'USA']);

    $response->assertSessionHasErrors('country_code');
});

test('a well-formatted but unseeded/unknown country code is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/settings/profile', ['country_code' => 'ZZ']);

    $response->assertSessionHasErrors('country_code');
});

test('a lowercase country code is normalized to uppercase and accepted', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/settings/profile', ['country_code' => 'np']);

    $response->assertSessionDoesntHaveErrors('country_code');
    expect($user->fresh()->profile->country_code)->toBe('NP');
});

test('a malformed locale is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/settings/profile', ['locale' => 'not-a-locale-code']);

    $response->assertSessionHasErrors('locale');
});

test('an invalid timezone string is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/settings/profile', ['timezone' => 'Mars/Cydonia']);

    $response->assertSessionHasErrors('timezone');
});

test('a real IANA timezone identifier is accepted', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch('/settings/profile', ['timezone' => 'Asia/Kathmandu']);

    $response->assertSessionDoesntHaveErrors('timezone');
    expect($user->fresh()->profile->timezone)->toBe('Asia/Kathmandu');
});
