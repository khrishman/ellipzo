<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Data;

use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use Illuminate\Support\Carbon;

/**
 * A validated, normalized set of staff ledger-explorer filters.
 * AdminLedgerFilterRequest already enforces every one of these invariants -
 * the checks below are defense-in-depth against any other caller
 * constructing this DTO directly, mirroring TransactionHistoryFilters'
 * own re-validation style.
 *
 * $userId and $username are two genuinely distinct request fields, never
 * one overloaded field with numeric-vs-username inference - a numeric
 * username is a real, possible value in this application (UserProfile's
 * own username format, `^[A-Za-z0-9_]+$`, has no requirement that a
 * username contain a letter), so guessing "looks numeric" would silently
 * misinterpret a real username as a user ID. AdminLedgerFilterRequest
 * enforces they are never both present at once (`prohibits`). $username
 * never holds an email-shaped value - full email addresses are rejected
 * before this DTO is ever constructed (see docs/memory.md's Task 2.8
 * privacy correction).
 *
 * $dateFrom/$dateTo are UTC calendar-day boundaries
 * (00:00:00..23:59:59 UTC on their respective dates), never a bare date
 * with an ambiguous timezone.
 */
final readonly class AdminLedgerFilters
{
    public function __construct(
        public ?string $transactionId = null,
        public ?LedgerTransactionType $transactionType = null,
        public ?WalletAccountType $accountType = null,
        public ?int $userId = null,
        public ?string $username = null,
        public ?string $businessReference = null,
        public ?string $correlationId = null,
        public ?Carbon $dateFrom = null,
        public ?Carbon $dateTo = null,
    ) {
        if ($this->userId !== null && $this->username !== null) {
            throw new LedgerInvariantViolationException('The user filter must be either a user ID or a username, never both.');
        }

        if ($this->username !== null && str_contains($this->username, '@')) {
            throw new LedgerInvariantViolationException('The user filter must never contain an email-shaped value.');
        }

        if ($this->dateFrom !== null && $this->dateTo !== null && $this->dateFrom->greaterThan($this->dateTo)) {
            throw new LedgerInvariantViolationException('The date-from filter must not be later than the date-to filter.');
        }
    }

    public function hasAnyFilter(): bool
    {
        return $this->transactionId !== null
            || $this->transactionType !== null
            || $this->accountType !== null
            || $this->userId !== null
            || $this->username !== null
            || $this->businessReference !== null
            || $this->correlationId !== null
            || $this->dateFrom !== null
            || $this->dateTo !== null;
    }
}
