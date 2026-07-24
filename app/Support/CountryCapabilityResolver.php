<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CountryCapability;
use Illuminate\Support\Carbon;

/**
 * The single place that resolves what a country is currently allowed to
 * do. Every other part of the app must go through this resolver rather
 * than querying country_capabilities directly, so "deny by default" for
 * a null, unknown, or unconfigured country can never be bypassed by a
 * new call site that forgets the fallback.
 */
class CountryCapabilityResolver
{
    public function resolve(?string $countryCode): CountryCapabilities
    {
        $capability = $this->findCapability($countryCode);

        return $capability
            ? CountryCapabilities::fromModel($capability)
            : CountryCapabilities::denied();
    }

    /**
     * Correct calendar-date age comparison (accounts for whether the
     * birthday has occurred yet this year, not a naive year subtraction)
     * against the country's configured minimum age. A missing country, a
     * missing date of birth, or a country with no capability
     * configuration at all are all treated as not eligible - there is no
     * identity-verification step here, only a comparison against
     * ordinary profile data the user already provided.
     */
    public function meetsMinimumAge(?string $countryCode, ?Carbon $dateOfBirth): bool
    {
        if ($dateOfBirth === null) {
            return false;
        }

        $capability = $this->findCapability($countryCode);

        if ($capability === null) {
            return false;
        }

        return $dateOfBirth->age >= $capability->minimum_age;
    }

    private function findCapability(?string $countryCode): ?CountryCapability
    {
        if ($countryCode === null) {
            return null;
        }

        return CountryCapability::find(mb_strtoupper($countryCode));
    }
}
