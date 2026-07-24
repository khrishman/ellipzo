<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An append-only record of a user accepting one version of one legal
 * document. Every column here - user_id, document, version, accepted_at,
 * method - is server-derived and never mass assignable; the only way to
 * create a row is recordAcceptance() below, which never reads any of
 * these values from client input.
 */
#[Fillable([])]
class UserConsent extends Model
{
    /** @use HasFactory<UserConsentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record that the given user has accepted the current configured
     * version of the given legal document. The version always comes from
     * config('legal.documents'), never from a caller-supplied argument -
     * so there is no path by which client input can influence what
     * version ends up on the row.
     */
    public static function recordAcceptance(User $user, string $document, string $method): self
    {
        $version = config("legal.documents.{$document}.version");

        return static::forceCreate([
            'user_id' => $user->id,
            'document' => $document,
            'version' => $version,
            'accepted_at' => Carbon::now('UTC'),
            'method' => $method,
        ]);
    }

    /**
     * Consent records are append-only: once written, a row is never
     * changed or removed by application code. These guards make an
     * accidental update()/delete() call fail immediately and clearly,
     * rather than silently succeeding and quietly corrupting a legal
     * record.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('UserConsent records are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('UserConsent records are append-only and cannot be deleted.');
        });
    }
}
