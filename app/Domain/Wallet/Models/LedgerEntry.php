<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Services\LedgerWriteContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A single debit or credit line within one ledger_transaction.
 * LedgerPostingEngine is the only intended write path - see
 * LedgerTransaction's own docblock for the exact protection and its
 * honest limitation. No factory exists; a raw-insertion test helper
 * bypasses Eloquent for schema-only tests, clearly test-only.
 */
#[Fillable([])]
class LedgerEntry extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'entry_type' => LedgerEntryType::class,
            'amount_atomic' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    public function walletAccount(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class);
    }

    /**
     * Append-only: once written, a row is never changed or removed by
     * application code. This is a model-layer guard only, not a database
     * guarantee.
     */
    protected static function booted(): void
    {
        static::creating(function (): void {
            if (! LedgerWriteContext::isActive()) {
                throw new LedgerInvariantViolationException('LedgerEntry records can only be created through LedgerPostingEngine.');
            }
        });

        static::updating(function (): void {
            throw new LogicException('LedgerEntry records cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('LedgerEntry records cannot be deleted.');
        });
    }
}
