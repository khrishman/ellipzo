<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;

// Deliberately self-contained (not a reuse of StaffAccessTest.php's
// similarly-shaped helper) so this file's tests behave identically
// whether run alone or as part of the full suite.
function createAdministrator(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    return $admin;
}

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    // Laravel already boots a brand-new application (and therefore a
    // brand-new empty array cache) for every test method, so limiter
    // buckets cannot naturally leak between tests. This flush is an
    // explicit, correct extra guarantee that doesn't depend on that
    // internal behavior - unlike RateLimiter::clear(), which would need
    // the exact hashed cache key ThrottleRequests computes internally.
    Cache::flush();
    // All requests within a single test must land in the same rolling
    // minute, regardless of how long the test actually takes to run.
    $this->freezeTime();
});

test('an authorized administrator can perform a normal role change without being throttled', function () {
    $admin = createAdministrator();
    $target = User::factory()->create();

    $response = $this->actingAs($admin)->post('/admin/staff-access', [
        'email' => $target->email,
        'role' => 'moderator',
        'reason' => 'Normal usage well under the rate limit.',
    ]);

    expect($response->status())->not->toBe(429);
});

test('the first ten requests from the same administrator in one minute are never throttled', function () {
    $admin = createAdministrator();
    $targets = User::factory()->count(10)->create();

    foreach ($targets as $target) {
        $response = $this->actingAs($admin)->post('/admin/staff-access', [
            'email' => $target->email,
            'role' => 'moderator',
            'reason' => 'Exercising the allowance up to the limit.',
        ]);

        expect($response->status())->not->toBe(429);
    }
});

test('the eleventh request within the same minute is throttled with 429', function () {
    $admin = createAdministrator();
    $targets = User::factory()->count(11)->create();

    foreach ($targets->take(10) as $target) {
        $this->actingAs($admin)->post('/admin/staff-access', [
            'email' => $target->email,
            'role' => 'moderator',
            'reason' => 'Exercising the allowance up to the limit.',
        ]);
    }

    $eleventh = $this->actingAs($admin)->post('/admin/staff-access', [
        'email' => $targets->last()->email,
        'role' => 'moderator',
        'reason' => 'This one should be rejected by the rate limiter.',
    ]);

    $eleventh->assertStatus(429);
});

test('a throttled response carries the standard rate-limit headers', function () {
    $admin = createAdministrator();
    $targets = User::factory()->count(11)->create();

    foreach ($targets->take(10) as $target) {
        $this->actingAs($admin)->post('/admin/staff-access', [
            'email' => $target->email,
            'role' => 'moderator',
            'reason' => 'Exercising the allowance up to the limit.',
        ]);
    }

    $eleventh = $this->actingAs($admin)->post('/admin/staff-access', [
        'email' => $targets->last()->email,
        'role' => 'moderator',
        'reason' => 'This one should be rejected by the rate limiter.',
    ]);

    $eleventh->assertStatus(429);
    $eleventh->assertHeader('X-RateLimit-Limit', '10');
    $eleventh->assertHeader('X-RateLimit-Remaining', '0');
    expect($eleventh->headers->has('Retry-After'))->toBeTrue();
    expect($eleventh->headers->has('X-RateLimit-Reset'))->toBeTrue();
});

test('GET /admin/staff-access is never throttled', function () {
    $admin = createAdministrator();

    for ($i = 0; $i < 15; $i++) {
        $response = $this->actingAs($admin)->get('/admin/staff-access');
        expect($response->status())->not->toBe(429);
    }
});

test('one administrator exhausting their allowance does not throttle a different administrator', function () {
    $adminA = createAdministrator();
    $adminB = createAdministrator();
    $targetsForA = User::factory()->count(11)->create();

    foreach ($targetsForA->take(10) as $target) {
        $this->actingAs($adminA)->post('/admin/staff-access', [
            'email' => $target->email,
            'role' => 'moderator',
            'reason' => 'Exercising administrator A allowance.',
        ]);
    }

    $eleventhForA = $this->actingAs($adminA)->post('/admin/staff-access', [
        'email' => $targetsForA->last()->email,
        'role' => 'moderator',
        'reason' => 'This one should be rejected for A.',
    ]);
    $eleventhForA->assertStatus(429);

    $targetForB = User::factory()->create();
    $responseForB = $this->actingAs($adminB)->post('/admin/staff-access', [
        'email' => $targetForB->email,
        'role' => 'moderator',
        'reason' => 'Administrator B has an independent allowance.',
    ]);

    expect($responseForB->status())->not->toBe(429);
});

test('a guest attempting the role-change route is redirected to login, never throttled or authorized', function () {
    $target = User::factory()->create();

    $response = $this->post('/admin/staff-access', [
        'email' => $target->email,
        'role' => 'moderator',
        'reason' => 'Guest attempt.',
    ]);

    $response->assertRedirect(route('login', absolute: false));
});

test('a normal authenticated user without staff.manage is always forbidden, never throttled', function () {
    $normalUser = User::factory()->create();
    $target = User::factory()->create();

    for ($i = 0; $i < 12; $i++) {
        $response = $this->actingAs($normalUser)->post('/admin/staff-access', [
            'email' => $target->email,
            'role' => 'moderator',
            'reason' => 'Repeated unauthorized attempt.',
        ]);

        $response->assertForbidden();
    }
});
