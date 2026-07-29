<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\InvalidAuditEventIdentifierException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * An append-only record of a sensitive staff action. Every column is
 * server-derived and never mass assignable; the only way to create a row
 * is record() below. before_state/after_state must already be allowlisted
 * by the caller - this model never accepts a raw model or request payload.
 */
#[Fillable([])]
class AuditEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * $entityKey is a generic string identifier (ULID, UUID, or another
     * future scheme) for an entity whose primary key is not a PHP int -
     * an additive, backward-compatible alternative to $entityId, added
     * last in the parameter list (after the existing optional
     * $correlationId) specifically so no required parameter follows an
     * optional one in the declaration - both existing call sites
     * (AccountStatusTransitioner, StaffAccessController) use exclusively
     * named arguments and are unaffected by its position either way.
     *
     * @param  array<string, mixed>  $before  Allowlisted safe fields only.
     * @param  array<string, mixed>  $after  Allowlisted safe fields only.
     */
    public static function record(
        User $actor,
        string $action,
        string $entityType,
        ?int $entityId,
        array $before,
        array $after,
        string $reason,
        ?string $correlationId = null,
        ?string $entityKey = null,
    ): self {
        $normalizedEntityKey = self::normalizeEntityKey($entityKey);

        if ($entityId !== null && $normalizedEntityKey !== null) {
            throw new InvalidAuditEventIdentifierException('At most one of entityId and entityKey may be supplied.');
        }

        return static::forceCreate([
            'actor_id' => $actor->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_key' => $normalizedEntityKey,
            'before_state' => $before,
            'after_state' => $after,
            'reason' => $reason,
            'correlation_id' => $correlationId ?? (string) Str::uuid(),
            'created_at' => Carbon::now('UTC'),
        ]);
    }

    /**
     * Null (supplying neither identifier remains allowed, for backward
     * compatibility with entity-less audit events) passes through
     * unchanged; a non-null value is trimmed, rejected if empty or over
     * the entity_key column width (191), and rejected if it contains any
     * control character.
     */
    private static function normalizeEntityKey(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > 191) {
            throw new InvalidAuditEventIdentifierException('Entity key length is out of bounds.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            throw new InvalidAuditEventIdentifierException('Entity key contains invalid characters.');
        }

        return $trimmed;
    }

    /**
     * Audit events are append-only: once written, a row is never changed
     * or removed by application code. No update/delete route exists for
     * this model; these guards make an accidental update()/delete() call
     * fail immediately rather than silently corrupting the trail.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('AuditEvent records are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('AuditEvent records are append-only and cannot be deleted.');
        });
    }
}
