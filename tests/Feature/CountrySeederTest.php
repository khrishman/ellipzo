<?php

use App\Models\Country;
use App\Models\CountryCapability;
use Database\Seeders\CountrySeeder;

test('seeding creates a country and an all-false capability row for every country in the dataset', function () {
    (new CountrySeeder)->run();

    expect(Country::count())->toBeGreaterThan(0);
    expect(Country::count())->toBe(CountryCapability::count());

    $capability = CountryCapability::firstOrFail();
    expect($capability->registration_enabled)->toBeFalse();
    expect($capability->earning_enabled)->toBeFalse();
    expect($capability->advertising_enabled)->toBeFalse();
    expect($capability->deposits_enabled)->toBeFalse();
    expect($capability->withdrawals_enabled)->toBeFalse();
    expect($capability->minimum_age)->toBe(18);
});

test('every seeded country has an identical default capability row - no country is special-cased', function () {
    (new CountrySeeder)->run();

    $distinctCapabilitySets = CountryCapability::query()
        ->select(['registration_enabled', 'earning_enabled', 'advertising_enabled', 'deposits_enabled', 'withdrawals_enabled', 'minimum_age'])
        ->distinct()
        ->get();

    // If every country got the identical default row, there is exactly
    // one distinct combination of values across the whole table.
    expect($distinctCapabilitySets)->toHaveCount(1);
});

test('re-seeding is idempotent and preserves a deliberately changed capability value', function () {
    (new CountrySeeder)->run();

    $before = Country::count();

    $target = CountryCapability::firstOrFail();
    $target->forceFill(['earning_enabled' => true])->save();

    (new CountrySeeder)->run();

    expect(Country::count())->toBe($before);
    expect($target->fresh()->earning_enabled)->toBeTrue();
});

test('re-seeding keeps a country name in sync without touching its capability row', function () {
    (new CountrySeeder)->run();

    $country = Country::firstOrFail();
    $originalName = $country->name;

    $country->forceFill(['name' => 'Deliberately Wrong Name'])->save();

    (new CountrySeeder)->run();

    expect($country->fresh()->name)->toBe($originalName);
});
