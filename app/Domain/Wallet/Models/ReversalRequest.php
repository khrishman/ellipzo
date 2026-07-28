<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Wallet\Enums\ReversalFailureCode;
use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Services\ReversalRequestWriteContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The durable record of a reversal request's lifecycle, independent of
 * whether the ledger execution that fulfils it has happened yet.
 * ReversalRequestService is the only intended write path - its own
 * creating()/updating() guards below enforce this at the model layer,
 * mirroring LedgerTransaction's own boundary. No factory exists; a raw
 * DB::table()->insert() test helper bypasses Eloquent for corruption
 * fixtures only.
 */
#[Fillable([])]
class ReversalRequest extends Model
{
    use HasUlids;

    /**
     * Set once at creation and never changed afterward - the identity and
     * intent of a reversal request are fixed the moment it is accepted.
     */
    private const array IMMUTABLE_FIELDS = [
        'original_ledger_transaction_id',
        'idempotency_key',
        'fingerprint',
        'actor_id',
        'reason',
        'correlation_id',
        'created_at',
    ];

    /**
     * rejected has no outgoing edge in Task 2.5: it is a reserved terminal
     * state for a future staff workflow that this production code path
     * never produces or transitions out of.
     */
    private const array ALLOWED_TRANSITIONS = [
        'pending' => ['applied', 'review_required'],
        'review_required' => ['applied'],
        'applied' => [],
        'rejected' => [],
    ];

    protected function casts(): array
    {
        return [
            'status' => ReversalRequestStatus::class,
            'failure_code' => ReversalFailureCode::class,
            'applied_at' => 'datetime',
            'review_required_at' => 'datetime',
        ];
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'original_ledger_transaction_id');
    }

    public function reversalTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'reversal_transaction_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::creating(function (ReversalRequest $model): void {
            if (! ReversalRequestWriteContext::isActive()) {
                throw new LedgerInvariantViolationException('ReversalRequest records can only be created through ReversalRequestService.');
            }

            self::assertStateConsistency($model);
        });

        static::updating(function (ReversalRequest $model): void {
            if (! ReversalRequestWriteContext::isActive()) {
                throw new LedgerInvariantViolationException('ReversalRequest records can only be updated through ReversalRequestService.');
            }

            $dirty = array_keys($model->getDirty());

            foreach ($dirty as $field) {
                if (in_array($field, self::IMMUTABLE_FIELDS, true)) {
                    throw new LedgerInvariantViolationException('This field on a reversal request cannot be changed.');
                }
            }

            if (in_array('status', $dirty, true)) {
                $from = $model->getOriginal('status');
                $allowed = self::ALLOWED_TRANSITIONS[$from->value] ?? [];

                if (! in_array($model->status->value, $allowed, true)) {
                    throw new LedgerInvariantViolationException('This reversal-request status transition is not permitted.');
                }
            }

            self::assertStateConsistency($model);
        });

        static::deleting(function (): void {
            throw new LogicException('ReversalRequest records cannot be deleted.');
        });
    }

    /**
     * Confirms the resulting record's outcome fields agree with its
     * status, independent of which specific field changed - a pending
     * request can never carry an outcome, a review-required request
     * always carries its failure classification and first-seen timestamp,
     * and an applied request always carries its linked reversal
     * transaction while its current failure code is cleared.
     */
    private static function assertStateConsistency(ReversalRequest $model): void
    {
        if ($model->status === ReversalRequestStatus::Pending) {
            if ($model->reversal_transaction_id !== null
                || $model->failure_code !== null
                || $model->applied_at !== null
                || $model->review_required_at !== null) {
                throw new LedgerInvariantViolationException('A pending reversal request cannot carry any outcome fields.');
            }
        }

        if ($model->status === ReversalRequestStatus::ReviewRequired) {
            if ($model->failure_code !== ReversalFailureCode::InsufficientBalance
                || $model->review_required_at === null
                || $model->reversal_transaction_id !== null
                || $model->applied_at !== null) {
                throw new LedgerInvariantViolationException('A review-required reversal request has an inconsistent outcome state.');
            }
        }

        if ($model->status === ReversalRequestStatus::Applied) {
            if ($model->reversal_transaction_id === null
                || $model->applied_at === null
                || $model->failure_code !== null) {
                throw new LedgerInvariantViolationException('An applied reversal request has an inconsistent outcome state.');
            }
        }
    }
}
