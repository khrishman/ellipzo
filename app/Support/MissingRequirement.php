<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EligibilityRequirement;

/**
 * One missing/unsatisfied eligibility requirement. `context` carries only
 * safe, already-user-facing structured data (e.g. legal document slugs
 * that already have their own public /legal/{document} page) - never a
 * raw model, database row, or arbitrary config text.
 */
final readonly class MissingRequirement
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public EligibilityRequirement $code,
        public array $context = [],
    ) {}
}
