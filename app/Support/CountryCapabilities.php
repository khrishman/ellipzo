<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CountryCapability;

/**
 * A typed, explicit snapshot of what a country is currently allowed to
 * do. Deliberately exposes named properties rather than a generic
 * get($column) accessor, so a capability can never be looked up by an
 * arbitrary (e.g. client-supplied) column name.
 */
final readonly class CountryCapabilities
{
    public function __construct(
        public bool $registrationEnabled,
        public bool $earningEnabled,
        public bool $advertisingEnabled,
        public bool $depositsEnabled,
        public bool $withdrawalsEnabled,
        public int $minimumAge,
    ) {}

    /**
     * The deny-by-default result for a null, unknown, or unconfigured
     * country - every capability off, regardless of why the country
     * couldn't be resolved.
     */
    public static function denied(): self
    {
        return new self(
            registrationEnabled: false,
            earningEnabled: false,
            advertisingEnabled: false,
            depositsEnabled: false,
            withdrawalsEnabled: false,
            minimumAge: 18,
        );
    }

    public static function fromModel(CountryCapability $capability): self
    {
        return new self(
            registrationEnabled: $capability->registration_enabled,
            earningEnabled: $capability->earning_enabled,
            advertisingEnabled: $capability->advertising_enabled,
            depositsEnabled: $capability->deposits_enabled,
            withdrawalsEnabled: $capability->withdrawals_enabled,
            minimumAge: $capability->minimum_age,
        );
    }
}
