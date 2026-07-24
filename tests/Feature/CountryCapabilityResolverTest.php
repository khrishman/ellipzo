<?php

use App\Models\Country;
use App\Models\CountryCapability;
use App\Support\CountryCapabilityResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

test('a null country code resolves to fully denied capabilities', function () {
    $resolver = new CountryCapabilityResolver;

    $capabilities = $resolver->resolve(null);

    expect($capabilities->registrationEnabled)->toBeFalse();
    expect($capabilities->earningEnabled)->toBeFalse();
    expect($capabilities->advertisingEnabled)->toBeFalse();
    expect($capabilities->depositsEnabled)->toBeFalse();
    expect($capabilities->withdrawalsEnabled)->toBeFalse();
});

test('an unknown country code (never seeded) resolves to fully denied capabilities', function () {
    $resolver = new CountryCapabilityResolver;

    $capabilities = $resolver->resolve('ZZ');

    expect($capabilities->registrationEnabled)->toBeFalse();
    expect($capabilities->earningEnabled)->toBeFalse();
});

test('a seeded country with no capability row resolves to fully denied capabilities', function () {
    Country::create(['code' => 'US', 'name' => 'United States']);

    $capabilities = (new CountryCapabilityResolver)->resolve('US');

    expect($capabilities->registrationEnabled)->toBeFalse();
});

test('a configured country resolves its real, non-default capability values', function () {
    Country::create(['code' => 'US', 'name' => 'United States']);
    CountryCapability::create([
        'country_code' => 'US',
        'registration_enabled' => true,
        'earning_enabled' => true,
        'advertising_enabled' => false,
        'deposits_enabled' => false,
        'withdrawals_enabled' => false,
        'minimum_age' => 21,
    ]);

    $capabilities = (new CountryCapabilityResolver)->resolve('us');

    expect($capabilities->registrationEnabled)->toBeTrue();
    expect($capabilities->earningEnabled)->toBeTrue();
    expect($capabilities->advertisingEnabled)->toBeFalse();
    expect($capabilities->minimumAge)->toBe(21);
});

test('enabling a capability for one country does not affect another', function () {
    Country::create(['code' => 'US', 'name' => 'United States']);
    Country::create(['code' => 'NP', 'name' => 'Nepal']);
    CountryCapability::create([
        'country_code' => 'US', 'registration_enabled' => true, 'earning_enabled' => true,
        'advertising_enabled' => true, 'deposits_enabled' => false, 'withdrawals_enabled' => false, 'minimum_age' => 18,
    ]);
    CountryCapability::create([
        'country_code' => 'NP', 'registration_enabled' => false, 'earning_enabled' => false,
        'advertising_enabled' => false, 'deposits_enabled' => false, 'withdrawals_enabled' => false, 'minimum_age' => 18,
    ]);

    $resolver = new CountryCapabilityResolver;

    expect($resolver->resolve('US')->registrationEnabled)->toBeTrue();
    expect($resolver->resolve('NP')->registrationEnabled)->toBeFalse();
});

test('meetsMinimumAge is false when the date of birth is null', function () {
    Country::create(['code' => 'US', 'name' => 'United States']);
    CountryCapability::create([
        'country_code' => 'US', 'registration_enabled' => true, 'earning_enabled' => true,
        'advertising_enabled' => false, 'deposits_enabled' => false, 'withdrawals_enabled' => false, 'minimum_age' => 18,
    ]);

    expect((new CountryCapabilityResolver)->meetsMinimumAge('US', null))->toBeFalse();
});

test('meetsMinimumAge is false when the country is null', function () {
    $dateOfBirth = Carbon::now()->subYears(30);

    expect((new CountryCapabilityResolver)->meetsMinimumAge(null, $dateOfBirth))->toBeFalse();
});

test('meetsMinimumAge is false when the country has no capability configuration at all', function () {
    Country::create(['code' => 'US', 'name' => 'United States']);
    $dateOfBirth = Carbon::now()->subYears(30);

    expect((new CountryCapabilityResolver)->meetsMinimumAge('US', $dateOfBirth))->toBeFalse();
});

test('a user who turned the minimum age exactly today is eligible', function () {
    Country::create(['code' => 'US', 'name' => 'United States']);
    CountryCapability::create([
        'country_code' => 'US', 'registration_enabled' => true, 'earning_enabled' => true,
        'advertising_enabled' => false, 'deposits_enabled' => false, 'withdrawals_enabled' => false, 'minimum_age' => 18,
    ]);

    $dateOfBirth = Carbon::now()->subYears(18);

    expect((new CountryCapabilityResolver)->meetsMinimumAge('US', $dateOfBirth))->toBeTrue();
});

test('a user who turns the minimum age tomorrow is not yet eligible today - correct calendar-date comparison, not a naive year subtraction', function () {
    Country::create(['code' => 'US', 'name' => 'United States']);
    CountryCapability::create([
        'country_code' => 'US', 'registration_enabled' => true, 'earning_enabled' => true,
        'advertising_enabled' => false, 'deposits_enabled' => false, 'withdrawals_enabled' => false, 'minimum_age' => 18,
    ]);

    $dateOfBirth = Carbon::now()->subYears(18)->addDay();

    expect((new CountryCapabilityResolver)->meetsMinimumAge('US', $dateOfBirth))->toBeFalse();
});

test('a user who turned the minimum age yesterday remains eligible today', function () {
    Country::create(['code' => 'US', 'name' => 'United States']);
    CountryCapability::create([
        'country_code' => 'US', 'registration_enabled' => true, 'earning_enabled' => true,
        'advertising_enabled' => false, 'deposits_enabled' => false, 'withdrawals_enabled' => false, 'minimum_age' => 18,
    ]);

    $dateOfBirth = Carbon::now()->subYears(18)->subDay();

    expect((new CountryCapabilityResolver)->meetsMinimumAge('US', $dateOfBirth))->toBeTrue();
});

test('the minimum age is per-country configurable', function () {
    Country::create(['code' => 'US', 'name' => 'United States']);
    CountryCapability::create([
        'country_code' => 'US', 'registration_enabled' => true, 'earning_enabled' => true,
        'advertising_enabled' => false, 'deposits_enabled' => false, 'withdrawals_enabled' => false, 'minimum_age' => 21,
    ]);

    $dateOfBirth = Carbon::now()->subYears(19);

    expect((new CountryCapabilityResolver)->meetsMinimumAge('US', $dateOfBirth))->toBeFalse();
});

test('deleting a country with a capability row is blocked by the restrictive foreign key', function () {
    Country::create(['code' => 'US', 'name' => 'United States']);
    CountryCapability::create([
        'country_code' => 'US', 'registration_enabled' => false, 'earning_enabled' => false,
        'advertising_enabled' => false, 'deposits_enabled' => false, 'withdrawals_enabled' => false, 'minimum_age' => 18,
    ]);

    $country = Country::findOrFail('US');

    expect(fn () => $country->delete())->toThrow(QueryException::class);
    expect(Country::find('US'))->not->toBeNull();
});
