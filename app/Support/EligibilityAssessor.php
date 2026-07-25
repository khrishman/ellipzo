<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AccountStatus;
use App\Enums\EligibilityRequirement;
use App\Enums\EligibilityStatus;
use App\Models\User;
use App\Models\UserConsent;

/**
 * Computes eligibility fresh on every call - never persisted. Account
 * status and eligibility are deliberately separate concepts: an ACTIVE
 * account is not automatically ELIGIBLE for anything.
 */
class EligibilityAssessor
{
    public function __construct(
        private readonly CountryCapabilityResolver $countryCapabilityResolver,
    ) {}

    public function assess(User $user): EligibilityResult
    {
        if ($user->account_status !== AccountStatus::Active) {
            return new EligibilityResult(
                EligibilityStatus::Ineligible,
                [new MissingRequirement(EligibilityRequirement::AccountNotActive)],
            );
        }

        $missing = [];

        if ($user->email_verified_at === null) {
            $missing[] = new MissingRequirement(EligibilityRequirement::EmailNotVerified);
        }

        $profile = $user->profile;
        $dateOfBirth = $profile?->date_of_birth;
        $countryCode = $profile?->country_code;

        if ($dateOfBirth === null) {
            $missing[] = new MissingRequirement(EligibilityRequirement::ProfileDateOfBirthMissing);
        }

        if ($countryCode === null) {
            $missing[] = new MissingRequirement(EligibilityRequirement::ProfileCountryMissing);
        }

        $missingDocuments = $this->missingRequiredConsentDocuments($user);
        if ($missingDocuments !== []) {
            $missing[] = new MissingRequirement(
                EligibilityRequirement::LegalConsentRequired,
                ['documents' => $missingDocuments],
            );
        }

        // Country-dependent checks only run once a country is actually
        // stored - a missing country is a pending requirement above, not
        // grounds to also evaluate capabilities against null.
        if ($countryCode !== null) {
            $capabilities = $this->countryCapabilityResolver->resolve($countryCode);

            if (! $capabilities->registrationEnabled) {
                $missing[] = new MissingRequirement(EligibilityRequirement::CountryNotEnabled);
            }

            if ($dateOfBirth !== null && ! $this->countryCapabilityResolver->meetsMinimumAge($countryCode, $dateOfBirth)) {
                $missing[] = new MissingRequirement(EligibilityRequirement::CountryBelowMinimumAge);
            }
        }

        return new EligibilityResult($this->aggregateStatus($missing), $missing);
    }

    /**
     * @return array<int, string>
     */
    private function missingRequiredConsentDocuments(User $user): array
    {
        $required = config('legal.required_documents');
        $missingSlugs = [];

        foreach ($required as $document) {
            if (! (bool) config("legal.documents.{$document}.published")) {
                continue;
            }

            $currentVersion = config("legal.documents.{$document}.version");

            $accepted = UserConsent::query()
                ->where('user_id', $user->id)
                ->where('document', $document)
                ->where('version', $currentVersion)
                ->exists();

            if (! $accepted) {
                $missingSlugs[] = $document;
            }
        }

        return $missingSlugs;
    }

    /**
     * @param  array<int, MissingRequirement>  $missing
     */
    private function aggregateStatus(array $missing): EligibilityStatus
    {
        if ($missing === []) {
            return EligibilityStatus::Eligible;
        }

        foreach ($missing as $requirement) {
            if ($requirement->code->isBlocking()) {
                return EligibilityStatus::Ineligible;
            }
        }

        return EligibilityStatus::Pending;
    }
}
