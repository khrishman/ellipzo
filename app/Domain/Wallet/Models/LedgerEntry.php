<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Wallet\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A single debit or credit line within one ledger_transaction. Nothing
 * constructs this model yet - Task 2.4's posting engine is the first and
 * only intended write path. No factory exists; any Task 2.3 test that
 * needs a row inserts one directly, clearly test-only.
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
        static::updating(function (): void {
            throw new LogicException('LedgerEntry records cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('LedgerEntry records cannot be deleted.');
        });
    }
}
