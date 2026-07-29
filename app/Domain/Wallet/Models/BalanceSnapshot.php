<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Services\BalanceSnapshotWriteContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An append-only, non-authoritative point-in-time record of one wallet
 * account's derived balance. BalanceSnapshotService is the only intended
 * write path - its own creating() guard below enforces this at the model
 * layer, mirroring every other financial model in this domain. A snapshot
 * is never read by LedgerPostingEngine and never substitutes for deriving
 * a balance from the ledger itself; it exists purely for
 * wallet:reconcile's later drift detection. Fully immutable once
 * created - unlike ReversalRequest, no field of a snapshot is ever
 * expected to change after capture, so update() is unconditionally
 * blocked rather than gated by an allowed-transition table.
 */
#[Fillable([])]
class BalanceSnapshot extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'currency_code' => Currency::class,
            'currency_scale' => 'integer',
            'cutoff_entry_created_at' => 'datetime',
            'entry_count' => 'integer',
            'fingerprint_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function walletAccount(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class);
    }

    public function cutoffEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'cutoff_ledger_entry_id');
    }

    protected static function booted(): void
    {
        static::creating(function (BalanceSnapshot $model): void {
            if (! BalanceSnapshotWriteContext::isActive()) {
                throw new LedgerInvariantViolationException('BalanceSnapshot records can only be created through BalanceSnapshotService.');
            }

            self::assertInvariants($model);
        });

        static::updating(function (): void {
            throw new LogicException('BalanceSnapshot records cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('BalanceSnapshot records cannot be deleted.');
        });
    }

    /**
     * Every check here is a pure in-memory field consistency check - "does
     * this cutoff entry genuinely belong to this account" is deliberately
     * NOT re-queried here (that would mean every snapshot insert pays for
     * an extra database round trip); BalanceSnapshotService guarantees it
     * by construction (its cutoff always comes from an entry stream
     * already filtered by this exact account), and wallet:reconcile is
     * the mechanism that later catches anything which bypassed that
     * guarantee (e.g. a raw insert), exactly the same honest split this
     * codebase already uses for every other "model-layer guard only, not
     * a database guarantee" boundary.
     */
    private static function assertInvariants(BalanceSnapshot $model): void
    {
        if (($model->cutoff_ledger_entry_id === null) !== ($model->cutoff_entry_created_at === null)) {
            throw new LedgerInvariantViolationException('Cutoff entry ID and timestamp must both be present or both be absent.');
        }

        if ($model->cutoff_ledger_entry_id === null) {
            if ($model->entry_count !== 0 || $model->balance_atomic !== 0) {
                throw new LedgerInvariantViolationException('A snapshot with no cutoff entry must have zero entries and a zero balance.');
            }
        }

        if ($model->currency_code !== Currency::USD || $model->currency_scale !== Currency::USD->scale()) {
            throw new LedgerInvariantViolationException('A balance snapshot must use the canonical USD currency and scale.');
        }
    }
}
