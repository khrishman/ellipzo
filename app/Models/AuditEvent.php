<?php

declare(strict_types=1);

namespace App\Models;

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
    ): self {
        return static::forceCreate([
            'actor_id' => $actor->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_state' => $before,
            'after_state' => $after,
            'reason' => $reason,
            'correlation_id' => $correlationId ?? (string) Str::uuid(),
            'created_at' => Carbon::now('UTC'),
        ]);
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
