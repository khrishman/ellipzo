<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A fixed, closed set of machine-readable eligibility requirement codes -
 * never a free-form or client-supplied string. `LegalConsentRequired` is
 * deliberately one stable code covering any number of required documents
 * (see App\Support\MissingRequirement's context array for which specific
 * document slugs are missing), not one enum case per document, so adding
 * a third required document later needs no enum change.
 */
enum EligibilityRequirement: string
{
    case AccountNotActive = 'account_not_active';
    case EmailNotVerified = 'email_not_verified';
    case ProfileDateOfBirthMissing = 'profile_date_of_birth_missing';
    case ProfileCountryMissing = 'profile_country_missing';
    case LegalConsentRequired = 'legal_consent_required';
    case CountryBelowMinimumAge = 'country_below_minimum_age';
    case CountryNotEnabled = 'country_not_enabled';

    /**
     * True for requirements that are a hard denial (ineligible-class):
     * not fixable by the user simply providing more information right
     * now. False for requirements that are simply not yet satisfied
     * (pending-class). Precedence when aggregating a result: any blocking
     * requirement makes the overall result INELIGIBLE regardless of how
     * many pending-class requirements are also present.
     */
    public function isBlocking(): bool
    {
        return match ($this) {
            self::AccountNotActive, self::CountryBelowMinimumAge, self::CountryNotEnabled => true,
            self::EmailNotVerified, self::ProfileDateOfBirthMissing,
            self::ProfileCountryMissing, self::LegalConsentRequired => false,
        };
    }
}
