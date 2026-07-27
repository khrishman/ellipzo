<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A single balance-holding account under one scope (user/platform/provider).
 * Every column is server-derived; WalletAccountProvisioner is the only
 * write path, using explicit property assignment + save() rather than
 * create()/forceCreate() - see its own documentation for the exact
 * mechanism and the cross-column invariants it enforces (none of which
 * are database-enforced).
 */
#[Fillable([])]
class WalletAccount extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'scope_type' => WalletAccountScopeType::class,
            'account_type' => WalletAccountType::class,
            'currency_code' => Currency::class,
            'currency_scale' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Append-only: once written, a row is never changed or removed by
     * application code. No update/delete API exists anywhere for this
     * model - these guards make an accidental call fail immediately
     * rather than silently corrupting a financial record. This is a
     * model-layer guard only, not a database guarantee - see
     * WalletAccountProvisioner's own documentation.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('WalletAccount records cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('WalletAccount records cannot be deleted.');
        });
    }
}
