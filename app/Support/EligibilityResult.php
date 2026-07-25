<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EligibilityStatus;

/**
 * Always computed fresh by EligibilityAssessor, never persisted.
 */
final readonly class EligibilityResult
{
    /**
     * @param  array<int, MissingRequirement>  $missingRequirements
     */
    public function __construct(
        public EligibilityStatus $status,
        public array $missingRequirements = [],
    ) {}
}
