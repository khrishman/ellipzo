<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AccountStatus;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;

/**
 * The only application write path for User::$account_status. Every check
 * - permission, self-targeting, reason, transition validity - is
 * re-verified against a freshly locked row inside one transaction, so a
 * caller can never authorize a write against stale data. The "before"
 * value recorded in the audit trail always comes from that locked row,
 * never from a client-supplied or caller-assumed value.
 */
class AccountStatusTransitioner
{
    /**
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'active' => ['limited', 'suspended', 'closed'],
        'limited' => ['active', 'suspended', 'closed'],
        'suspended' => ['active', 'limited', 'closed'],
        'closed' => ['active'],
    ];

    public function transition(User $actor, User $target, AccountStatus $to, string $reason): void
    {
        DB::transaction(function () use ($actor, $target, $to, $reason): void {
            $lockedTarget = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $currentStatus = $lockedTarget->account_status;

            if ($actor->cannot('users.status.manage')) {
                throw UnauthorizedException::forPermissions(['users.status.manage']);
            }

            if ($lockedTarget->is($actor)) {
                throw ValidationException::withMessages([
                    'email' => ['You cannot change your own account status.'],
                ]);
            }

            if (trim($reason) === '') {
                throw ValidationException::withMessages([
                    'reason' => ['A reason is required to change an account status.'],
                ]);
            }

            if ($currentStatus === $to) {
                throw ValidationException::withMessages([
                    'status' => ['The account is already in this status.'],
                ]);
            }

            $allowed = self::ALLOWED_TRANSITIONS[$currentStatus->value] ?? [];

            if (! in_array($to->value, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot change status from {$currentStatus->value} to {$to->value}."],
                ]);
            }

            $lockedTarget->forceFill(['account_status' => $to])->save();

            if ($to->blocksProtectedRoutes()) {
                DB::table('sessions')->where('user_id', $lockedTarget->id)->delete();
            }

            AuditEvent::record(
                actor: $actor,
                action: 'account.status_changed',
                entityType: 'user',
                entityId: $lockedTarget->id,
                before: ['status' => $currentStatus->value],
                after: ['status' => $to->value],
                reason: $reason,
            );
        });
    }
}
