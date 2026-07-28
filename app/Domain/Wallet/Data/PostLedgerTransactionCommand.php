<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Data;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\RelatedEntityType;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A fully validated, normalized instruction to post one balanced ledger
 * transaction. Immutable after construction - every invariant this class
 * can prove without touching the database (list shape, positivity,
 * balancing, metadata format) is proven here, before LedgerPostingEngine
 * ever opens a transaction. Reversal transactions are rejected outright:
 * Task 2.5 owns reverses_transaction_id and reversal semantics.
 */
final readonly class PostLedgerTransactionCommand
{
    public LedgerTransactionType $type;

    public string $businessReference;

    public string $correlationId;

    public string $description;

    public ?int $actorId;

    public ?RelatedEntityType $relatedEntityType;

    public ?string $relatedEntityId;

    /** @var list<PostLedgerEntryCommand> */
    public array $entries;

    public function __construct(
        LedgerTransactionType $type,
        mixed $businessReference,
        mixed $correlationId,
        mixed $description,
        ?User $actor,
        ?RelatedEntityType $relatedEntityType,
        mixed $relatedEntityId,
        mixed $entries,
    ) {
        if ($type === LedgerTransactionType::Reversal) {
            throw new LedgerInvariantViolationException('Reversal transactions cannot be posted through the generic posting engine.');
        }

        if (! is_string($businessReference)) {
            throw new LedgerInvariantViolationException('Business reference must be a string.');
        }

        if (! is_string($correlationId)) {
            throw new LedgerInvariantViolationException('Correlation ID must be a string.');
        }

        if (! is_string($description)) {
            throw new LedgerInvariantViolationException('Description must be a string.');
        }

        if ($actor !== null && (! $actor->exists || $actor->getKey() === null || $actor->getKey() <= 0)) {
            throw new LedgerInvariantViolationException('The actor must be a persisted user with a valid identifier.');
        }

        if (($relatedEntityType === null) !== ($relatedEntityId === null)) {
            throw new LedgerInvariantViolationException('Related entity type and ID must both be present or both be absent.');
        }

        if ($relatedEntityId !== null && ! is_string($relatedEntityId)) {
            throw new LedgerInvariantViolationException('Related entity ID must be a string.');
        }

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new LedgerInvariantViolationException('Entries must be a sequential list.');
        }

        if (count($entries) < 2) {
            throw new LedgerInvariantViolationException('At least two entries are required.');
        }

        foreach ($entries as $entry) {
            if (! $entry instanceof PostLedgerEntryCommand) {
                throw new LedgerInvariantViolationException('Every entry must be a PostLedgerEntryCommand.');
            }
        }

        $seenAccountIds = [];
        foreach ($entries as $entry) {
            if (in_array($entry->walletAccountId, $seenAccountIds, true)) {
                throw new LedgerInvariantViolationException('Duplicate wallet account within one posting is not permitted.');
            }
            $seenAccountIds[] = $entry->walletAccountId;
        }

        foreach ($entries as $entry) {
            if ($entry->amount->currency() !== Currency::USD) {
                throw new LedgerInvariantViolationException('Every entry must be denominated in USD.');
            }
        }

        $debitTotal = Money::zero(Currency::USD);
        $creditTotal = Money::zero(Currency::USD);

        foreach ($entries as $entry) {
            if ($entry->entryType === LedgerEntryType::Debit) {
                $debitTotal = $debitTotal->add($entry->amount);
            } else {
                $creditTotal = $creditTotal->add($entry->amount);
            }
        }

        if (! $debitTotal->equals($creditTotal)) {
            throw new LedgerInvariantViolationException('Debit and credit totals must be exactly equal.');
        }

        $this->type = $type;
        $this->businessReference = self::normalizeBusinessReference($businessReference, $type);
        $this->correlationId = self::normalizeCorrelationId($correlationId);
        $this->description = self::normalizeDescription($description);
        $this->actorId = $actor?->getKey();
        $this->relatedEntityType = $relatedEntityType;
        $this->relatedEntityId = $relatedEntityId !== null ? self::normalizeRelatedEntityId($relatedEntityId) : null;
        $this->entries = $entries;
    }

    private static function normalizeBusinessReference(string $value, LedgerTransactionType $type): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 191) {
            throw new LedgerInvariantViolationException('Business reference length is out of bounds.');
        }

        $normalized = strtolower($trimmed);

        if (preg_match('/^[a-z0-9:_-]+$/', $normalized) !== 1) {
            throw new LedgerInvariantViolationException('Business reference contains invalid characters.');
        }

        $expectedPrefix = $type->value.':';

        if (! str_starts_with($normalized, $expectedPrefix) || strlen($normalized) === strlen($expectedPrefix)) {
            throw new LedgerInvariantViolationException('Business reference must start with its transaction type prefix.');
        }

        return $normalized;
    }

    private static function normalizeCorrelationId(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 36) {
            throw new LedgerInvariantViolationException('Correlation ID length is out of bounds.');
        }

        if (Str::isUlid($trimmed)) {
            // HasUlids::newUniqueId() stores lowercase - confirmed from its
            // own source. Lowercase is the true canonical representation.
            return strtolower($trimmed);
        }

        if (Str::isUuid($trimmed)) {
            return strtolower($trimmed);
        }

        throw new LedgerInvariantViolationException('Correlation ID must be a canonical UUID or ULID.');
    }

    private static function normalizeDescription(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 255) {
            throw new LedgerInvariantViolationException('Description length is out of bounds.');
        }

        // Rejects every ASCII control character, including \n, \r, \t - a
        // single check covers both "no control characters" and "no
        // multiline content" at once.
        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            throw new LedgerInvariantViolationException('Description contains invalid characters.');
        }

        return $trimmed; // meaningful casing preserved deliberately
    }

    private static function normalizeRelatedEntityId(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 36) {
            throw new LedgerInvariantViolationException('Related entity ID length is out of bounds.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            throw new LedgerInvariantViolationException('Related entity ID contains invalid characters.');
        }

        if (Str::isUlid($trimmed)) {
            return strtolower($trimmed);
        }

        if (Str::isUuid($trimmed)) {
            return strtolower($trimmed);
        }

        // Not a UUID/ULID shape - a future related-entity ID scheme this
        // engine doesn't yet know about. Accepted as-is once trimmed and
        // proven free of control characters.
        return $trimmed;
    }
}
