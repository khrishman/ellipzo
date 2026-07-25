<?php

use App\Models\Country;
use App\Models\CountryCapability;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('legal.documents.terms', ['title' => 'Terms of Service', 'version' => 'test-terms-v1', 'published' => true]);
    Config::set('legal.documents.privacy', ['title' => 'Privacy Policy', 'version' => 'test-privacy-v1', 'published' => true]);
    Config::set('legal.required_documents', ['terms', 'privacy']);
});

test('the dashboard reports pending eligibility with the specific missing requirements for a brand new user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(
        fn ($page) => $page->component('dashboard/index')
            ->where('eligibility.status', 'pending')
            ->has('eligibility.missingRequirements'),
    );
});

test('the dashboard reports eligible with no missing requirements when everything is satisfied', function () {
    Country::create(['code' => 'US', 'name' => 'United States']);
    CountryCapability::create([
        'country_code' => 'US', 'registration_enabled' => true, 'earning_enabled' => false,
        'advertising_enabled' => false, 'deposits_enabled' => false, 'withdrawals_enabled' => false, 'minimum_age' => 18,
    ]);
    $user = User::factory()->create();
    $user->profile()->create(['date_of_birth' => now()->subYears(30)->toDateString(), 'country_code' => 'US']);
    UserConsent::recordAcceptance($user, 'terms', 'test');
    UserConsent::recordAcceptance($user, 'privacy', 'test');

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(
        fn ($page) => $page->component('dashboard/index')
            ->where('eligibility.status', 'eligible')
            ->where('eligibility.missingRequirements', []),
    );
});

test('a limited user sees an ineligible dashboard result', function () {
    $user = User::factory()->limited()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(
        fn ($page) => $page->component('dashboard/index')
            ->where('eligibility.status', 'ineligible'),
    );
});

test('the dashboard response contains no sensitive internal data', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/dashboard');
    $content = $response->getContent();

    expect($content)->not->toContain('correlation_id');
    expect($content)->not->toContain('audit_events');
    expect($content)->not->toContain('remember_token');
    expect($content)->not->toContain($user->getAuthPassword());
});
