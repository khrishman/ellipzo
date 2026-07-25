<?php

use App\Enums\AccountStatus;
use App\Enums\EligibilityRequirement;
use App\Enums\EligibilityStatus;
use App\Models\Country;
use App\Models\CountryCapability;
use App\Models\User;
use App\Models\UserConsent;
use App\Support\EligibilityAssessor;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('legal.documents.terms', ['title' => 'Terms of Service', 'version' => 'test-terms-v1', 'published' => true]);
    Config::set('legal.documents.privacy', ['title' => 'Privacy Policy', 'version' => 'test-privacy-v1', 'published' => true]);
    Config::set('legal.required_documents', ['terms', 'privacy']);
});

function seedEnabledCountry(string $code = 'US', int $minimumAge = 18): void
{
    Country::create(['code' => $code, 'name' => 'Test Country']);
    CountryCapability::create([
        'country_code' => $code,
        'registration_enabled' => true,
        'earning_enabled' => false,
        'advertising_enabled' => false,
        'deposits_enabled' => false,
        'withdrawals_enabled' => false,
        'minimum_age' => $minimumAge,
    ]);
}

function acceptAllRequiredConsent(User $user): void
{
    UserConsent::recordAcceptance($user, 'terms', 'test');
    UserConsent::recordAcceptance($user, 'privacy', 'test');
}

function fullyEligibleUser(): User
{
    seedEnabledCountry();
    $user = User::factory()->create();
    $user->profile()->create(['date_of_birth' => now()->subYears(30)->toDateString(), 'country_code' => 'US']);
    acceptAllRequiredConsent($user);

    return $user;
}

test('a fully satisfied user is eligible', function () {
    $user = fullyEligibleUser();

    $result = app(EligibilityAssessor::class)->assess($user);

    expect($result->status)->toBe(EligibilityStatus::Eligible);
    expect($result->missingRequirements)->toBe([]);
});

test('active account status does not automatically mean eligible', function () {
    $user = User::factory()->create(); // active, but no profile/consent at all

    $result = app(EligibilityAssessor::class)->assess($user);

    expect($user->account_status)->toBe(AccountStatus::Active);
    expect($result->status)->not->toBe(EligibilityStatus::Eligible);
});

test('a non-active account status is ineligible regardless of everything else being satisfied', function (AccountStatus $status) {
    $user = fullyEligibleUser();
    $user->forceFill(['account_status' => $status])->save();

    $result = app(EligibilityAssessor::class)->assess($user->fresh());

    expect($result->status)->toBe(EligibilityStatus::Ineligible);
    expect($result->missingRequirements[0]->code)->toBe(EligibilityRequirement::AccountNotActive);
})->with([AccountStatus::Limited, AccountStatus::Suspended, AccountStatus::Closed]);

test('an unverified email is a pending requirement', function () {
    $user = fullyEligibleUser();
    $user->forceFill(['email_verified_at' => null])->save();

    $result = app(EligibilityAssessor::class)->assess($user->fresh());

    expect($result->status)->toBe(EligibilityStatus::Pending);
    expect(array_map(fn ($r) => $r->code, $result->missingRequirements))->toContain(EligibilityRequirement::EmailNotVerified);
});

test('a missing date of birth is a pending requirement, never ineligible', function () {
    seedEnabledCountry();
    $user = User::factory()->create();
    $user->profile()->create(['date_of_birth' => null, 'country_code' => 'US']);
    acceptAllRequiredConsent($user);

    $result = app(EligibilityAssessor::class)->assess($user);

    expect($result->status)->toBe(EligibilityStatus::Pending);
    expect(array_map(fn ($r) => $r->code, $result->missingRequirements))->toContain(EligibilityRequirement::ProfileDateOfBirthMissing);
});

test('a missing stored country is a pending requirement, never eligible and never ineligible on its own', function () {
    $user = User::factory()->create();
    $user->profile()->create(['date_of_birth' => now()->subYears(30)->toDateString(), 'country_code' => null]);
    acceptAllRequiredConsent($user);

    $result = app(EligibilityAssessor::class)->assess($user);

    expect($result->status)->toBe(EligibilityStatus::Pending);
    expect(array_map(fn ($r) => $r->code, $result->missingRequirements))->toContain(EligibilityRequirement::ProfileCountryMissing);
});

test('an unaccepted required legal document is a pending requirement listing the specific missing slug', function () {
    seedEnabledCountry();
    $user = User::factory()->create();
    $user->profile()->create(['date_of_birth' => now()->subYears(30)->toDateString(), 'country_code' => 'US']);
    UserConsent::recordAcceptance($user, 'terms', 'test'); // privacy left unaccepted

    $result = app(EligibilityAssessor::class)->assess($user);

    expect($result->status)->toBe(EligibilityStatus::Pending);
    $consentRequirement = collect($result->missingRequirements)->firstWhere('code', EligibilityRequirement::LegalConsentRequired);
    expect($consentRequirement)->not->toBeNull();
    expect($consentRequirement->context['documents'])->toBe(['privacy']);
});

test('an unpublished required document never gates eligibility even when unaccepted', function () {
    Config::set('legal.documents.privacy.published', false);
    seedEnabledCountry();
    $user = User::factory()->create();
    $user->profile()->create(['date_of_birth' => now()->subYears(30)->toDateString(), 'country_code' => 'US']);
    UserConsent::recordAcceptance($user, 'terms', 'test');

    $result = app(EligibilityAssessor::class)->assess($user);

    expect($result->status)->toBe(EligibilityStatus::Eligible);
});

test('a published document that is not in the required list never gates eligibility', function () {
    Config::set('legal.documents.cookies', ['title' => 'Cookie Policy', 'version' => 'v1', 'published' => true]);
    // Deliberately not added to legal.required_documents.
    $user = fullyEligibleUser();

    $result = app(EligibilityAssessor::class)->assess($user);

    expect($result->status)->toBe(EligibilityStatus::Eligible);
});

test('a newer required consent version reverts an already-accepted user to pending', function () {
    $user = fullyEligibleUser();
    expect(app(EligibilityAssessor::class)->assess($user)->status)->toBe(EligibilityStatus::Eligible);

    Config::set('legal.documents.terms.version', 'test-terms-v2');

    $result = app(EligibilityAssessor::class)->assess($user);

    expect($result->status)->toBe(EligibilityStatus::Pending);
    $consentRequirement = collect($result->missingRequirements)->firstWhere('code', EligibilityRequirement::LegalConsentRequired);
    expect($consentRequirement->context['documents'])->toBe(['terms']);
});

test('a newer required consent version never modifies the historical consent row', function () {
    $user = fullyEligibleUser();
    $originalConsent = UserConsent::where('user_id', $user->id)->where('document', 'terms')->firstOrFail();

    Config::set('legal.documents.terms.version', 'test-terms-v2');
    app(EligibilityAssessor::class)->assess($user);

    expect(UserConsent::where('user_id', $user->id)->where('document', 'terms')->count())->toBe(1);
    expect($originalConsent->fresh()->version)->toBe('test-terms-v1');
});

test('an unknown or unconfigured stored country is ineligible via the deny-by-default resolver', function () {
    $user = User::factory()->create();
    $user->profile()->create(['date_of_birth' => now()->subYears(30)->toDateString(), 'country_code' => 'ZZ']);
    acceptAllRequiredConsent($user);

    $result = app(EligibilityAssessor::class)->assess($user);

    expect($result->status)->toBe(EligibilityStatus::Ineligible);
    expect(array_map(fn ($r) => $r->code, $result->missingRequirements))->toContain(EligibilityRequirement::CountryNotEnabled);
});

test('below the configured minimum age is ineligible', function () {
    seedEnabledCountry('US', 18);
    $user = User::factory()->create();
    $user->profile()->create(['date_of_birth' => now()->subYears(17)->toDateString(), 'country_code' => 'US']);
    acceptAllRequiredConsent($user);

    $result = app(EligibilityAssessor::class)->assess($user);

    expect($result->status)->toBe(EligibilityStatus::Ineligible);
    expect(array_map(fn ($r) => $r->code, $result->missingRequirements))->toContain(EligibilityRequirement::CountryBelowMinimumAge);
});

test('turning the minimum age exactly today is not ineligible on age grounds', function () {
    seedEnabledCountry('US', 18);
    $user = User::factory()->create();
    $user->profile()->create(['date_of_birth' => now()->subYears(18)->toDateString(), 'country_code' => 'US']);
    acceptAllRequiredConsent($user);

    $result = app(EligibilityAssessor::class)->assess($user);

    expect(array_map(fn ($r) => $r->code, $result->missingRequirements))->not->toContain(EligibilityRequirement::CountryBelowMinimumAge);
});

test('capabilities are isolated between countries', function () {
    seedEnabledCountry('US', 18);
    Country::create(['code' => 'ZZ', 'name' => 'Unconfigured Land']);
    // ZZ has no CountryCapability row at all.

    $eligibleUser = User::factory()->create();
    $eligibleUser->profile()->create(['date_of_birth' => now()->subYears(30)->toDateString(), 'country_code' => 'US']);
    acceptAllRequiredConsent($eligibleUser);

    $ineligibleUser = User::factory()->create();
    $ineligibleUser->profile()->create(['date_of_birth' => now()->subYears(30)->toDateString(), 'country_code' => 'ZZ']);
    acceptAllRequiredConsent($ineligibleUser);

    $assessor = app(EligibilityAssessor::class);
    expect($assessor->assess($eligibleUser)->status)->toBe(EligibilityStatus::Eligible);
    expect($assessor->assess($ineligibleUser)->status)->toBe(EligibilityStatus::Ineligible);
});

test('ineligible takes precedence over pending when both kinds of requirement are missing', function () {
    // Country below minimum age (ineligible-class) AND unverified email
    // (pending-class) at the same time.
    seedEnabledCountry('US', 18);
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => null])->save();
    $user->profile()->create(['date_of_birth' => now()->subYears(10)->toDateString(), 'country_code' => 'US']);
    acceptAllRequiredConsent($user);

    $result = app(EligibilityAssessor::class)->assess($user->fresh());

    expect($result->status)->toBe(EligibilityStatus::Ineligible);
    $codes = array_map(fn ($r) => $r->code, $result->missingRequirements);
    expect($codes)->toContain(EligibilityRequirement::CountryBelowMinimumAge);
    expect($codes)->toContain(EligibilityRequirement::EmailNotVerified);
});

test('no capability flag other than registration_enabled and minimum age feeds eligibility', function () {
    // A country enabled for registration but with every other capability
    // false must still resolve as eligible - earning/advertising/deposit/
    // withdrawal flags are feature-specific, not general-eligibility gates.
    Country::create(['code' => 'US', 'name' => 'United States']);
    CountryCapability::create([
        'country_code' => 'US',
        'registration_enabled' => true,
        'earning_enabled' => false,
        'advertising_enabled' => false,
        'deposits_enabled' => false,
        'withdrawals_enabled' => false,
        'minimum_age' => 18,
    ]);
    $user = User::factory()->create();
    $user->profile()->create(['date_of_birth' => now()->subYears(30)->toDateString(), 'country_code' => 'US']);
    acceptAllRequiredConsent($user);

    $result = app(EligibilityAssessor::class)->assess($user);

    expect($result->status)->toBe(EligibilityStatus::Eligible);
});
