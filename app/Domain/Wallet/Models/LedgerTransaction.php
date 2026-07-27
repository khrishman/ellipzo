<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\RelatedEntityType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A single financial event, made up of two or more balanced ledger_entries
 * rows. Nothing constructs this model yet - Task 2.4's posting engine is
 * the first and only intended write path. No factory exists; any
 * Task 2.3 test that needs a row inserts one directly, clearly test-only.
 */
#[Fillable([])]
class LedgerTransaction extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => LedgerTransactionType::class,
            'currency_code' => Currency::class,
            'currency_scale' => 'integer',
            'related_entity_type' => RelatedEntityType::class,
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /**
     * Append-only: once written, a row is never changed or removed by
     * application code. This is a model-layer guard only, not a database
     * guarantee.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('LedgerTransaction records cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('LedgerTransaction records cannot be deleted.');
        });
    }
}
