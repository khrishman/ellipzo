<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * Rejected is a reserved terminal state for a future staff review
 * workflow - no Task 2.5 production code path ever produces it. Approved
 * transitions (enforced by ReversalRequest's own model guard, not here):
 * pending -> applied, pending -> review_required, review_required ->
 * applied. Applied and Rejected are both terminal.
 */
enum ReversalRequestStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case ReviewRequired = 'review_required';
    case Rejected = 'rejected';
}
