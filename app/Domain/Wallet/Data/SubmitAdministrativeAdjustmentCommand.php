<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Data;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Enums\AdministrativeAdjustmentDirection;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\InvalidAdministrativeAdjustmentTargetException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A fully validated, normalized instruction to submit one administrative
 * adjustment. Immutable after construction - every invariant provable
 * without touching the database (actor/target persistence, target-type
 * allowlist, amount positivity/currency, string shapes) is proven here,
 * before AdministrativeAdjustmentService ever opens a transaction.
 *
 * The caller never supplies an entry list or a raw wallet_account_id -
 * only a target user and an allowlisted account type. The service alone
 * resolves the actual WalletAccount rows and builds the two posting
 * entries, so it is structurally impossible to submit arbitrary entries
 * or move value between two different users through this command.
 */
final readonly class SubmitAdministrativeAdjustmentCommand
{
    /** Prefix every inner ledger business reference carries. */
    public const string BUSINESS_REFERENCE_PREFIX = 'administrative_adjustment:';

    /**
     * ledger_transactions.business_reference is string(191); the prefix
     * ("administrative_adjustment:", 26 characters) consumes the rest.
     */
    private const int MAX_IDEMPOTENCY_KEY_LENGTH = 165;

    public const string DEFAULT_USER_VISIBLE_DESCRIPTION = 'Administrative balance adjustment';

    /** @var list<WalletAccountType> */
    private const array ALLOWED_TARGET_TYPES = [
        WalletAccountType::EarningAvailable,
        WalletAccountType::AdvertisingAvailable,
    ];

    public User $actor;

    public User $targetUser;

    public WalletAccountType $targetAccountType;

    public AdministrativeAdjustmentDirection $direction;

    public Money $amount;

    public string $internalReason;

    public string $userVisibleDescription;

    public string $idempotencyKey;

    public string $correlationId;

    public function __construct(
        User $actor,
        User $targetUser,
        WalletAccountType $targetAccountType,
        AdministrativeAdjustmentDirection $direction,
        Money $amount,
        mixed $internalReason,
        mixed $userVisibleDescription,
        mixed $idempotencyKey,
        mixed $correlationId,
    ) {
        if (! $actor->exists || $actor->getKey() === null) {
            throw new LedgerInvariantViolationException('The actor must be a persisted user.');
        }

        if (! $targetUser->exists || $targetUser->getKey() === null) {
            throw new LedgerInvariantViolationException('The target user must be a persisted user.');
        }

        if (! in_array($targetAccountType, self::ALLOWED_TARGET_TYPES, true)) {
            throw new InvalidAdministrativeAdjustmentTargetException('Administrative adjustments may only target earning_available or advertising_available.');
        }

        // Defensive forward-compatibility only: with Currency::USD as the
        // sole enum case, no genuine Money instance of any other currency
        // can currently exist, so this branch is presently unreachable and
        // untested by construction - not silently assumed to work, mirrors
        // Money's own documented stance on CurrencyMismatchException.
        if ($amount->currency() !== Currency::USD) {
            throw new LedgerInvariantViolationException('Amount must be denominated in USD.');
        }

        $this->actor = $actor;
        $this->targetUser = $targetUser;
        $this->targetAccountType = $targetAccountType;
        $this->direction = $direction;
        $this->amount = $amount->ensurePositive();
        $this->internalReason = self::normalizeInternalReason($internalReason);
        $this->userVisibleDescription = self::normalizeUserVisibleDescription($userVisibleDescription);
        $this->idempotencyKey = self::normalizeIdempotencyKey($idempotencyKey);
        $this->correlationId = self::normalizeCorrelationId($correlationId);
    }

    public function businessReference(): string
    {
        return self::BUSINESS_REFERENCE_PREFIX.$this->idempotencyKey;
    }

    private static function normalizeInternalReason(mixed $value): string
    {
        if (! is_string($value)) {
            throw new LedgerInvariantViolationException('Internal reason must be a string.');
        }

        $trimmed = trim($value);

        if (strlen($trimmed) < 10 || strlen($trimmed) > 1000) {
            throw new LedgerInvariantViolationException('Internal reason length is out of bounds.');
        }

        // Rejects every ASCII control character, including \n, \r, \t - a
        // single check covers both "no control characters" and "no
        // multiline content" at once, mirroring
        // PostLedgerTransactionCommand::normalizeDescription().
        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            throw new LedgerInvariantViolationException('Internal reason contains invalid characters.');
        }

        return $trimmed;
    }

    /**
     * Null falls back to a fixed, service-controlled default - never
     * derived from the internal reason, so there is no code path by which
     * a staff-only reason can leak into the user-visible description.
     */
    private static function normalizeUserVisibleDescription(mixed $value): string
    {
        if ($value === null) {
            return self::DEFAULT_USER_VISIBLE_DESCRIPTION;
        }

        if (! is_string($value)) {
            throw new LedgerInvariantViolationException('User-visible description must be a string.');
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 255) {
            throw new LedgerInvariantViolationException('User-visible description length is out of bounds.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            throw new LedgerInvariantViolationException('User-visible description contains invalid characters.');
        }

        return $trimmed;
    }

    private static function normalizeIdempotencyKey(mixed $value): string
    {
        if (! is_string($value)) {
            throw new LedgerInvariantViolationException('Idempotency key must be a string.');
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw new LedgerInvariantViolationException('Idempotency key length is out of bounds.');
        }

        $normalized = strtolower($trimmed);

        if (preg_match('/^[a-z0-9:_-]+$/', $normalized) !== 1) {
            throw new LedgerInvariantViolationException('Idempotency key contains invalid characters.');
        }

        return $normalized;
    }

    private static function normalizeCorrelationId(mixed $value): string
    {
        if (! is_string($value)) {
            throw new LedgerInvariantViolationException('Correlation ID must be a string.');
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 36) {
            throw new LedgerInvariantViolationException('Correlation ID length is out of bounds.');
        }

        if (Str::isUlid($trimmed) || Str::isUuid($trimmed)) {
            // HasUlids::newUniqueId() stores lowercase - confirmed from its
            // own source in the wallet domain's existing DTOs. Lowercase is
            // the true canonical representation.
            return strtolower($trimmed);
        }

        throw new LedgerInvariantViolationException('Correlation ID must be a canonical UUID or ULID.');
    }
}
