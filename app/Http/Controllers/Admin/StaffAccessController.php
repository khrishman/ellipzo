<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStaffRoleRequest;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class StaffAccessController extends Controller
{
    /**
     * List every currently assignable role and every user who currently
     * holds one, plus - only for viewers who also hold audit.view - a
     * recent trail of role changes. Deliberately selects only id/name/
     * email: no password, OAuth identity, consent, or session data ever
     * reaches this page.
     */
    public function show(Request $request): Response
    {
        $staffUserIds = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->pluck('model_id');

        $staff = User::query()
            ->whereIn('id', $staffUserIds)
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roles->first()?->name,
            ]);

        $canViewAudit = $request->user()->can('audit.view');

        return Inertia::render('admin/staff-access', [
            'staff' => $staff,
            'roles' => Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name'),
            'canManage' => $request->user()->can('staff.manage'),
            'canViewAudit' => $canViewAudit,
            'recentAuditEvents' => $canViewAudit ? $this->recentRoleChanges() : [],
        ]);
    }

    /**
     * Changes exactly one user's staff role. The role change and its
     * audit event are written in a single transaction - either both
     * happen or neither does - and the session-authenticated actor,
     * never client input, is who gets recorded as having made the change.
     */
    public function store(UpdateStaffRoleRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $target = User::where('email', $request->validated('email'))->firstOrFail();
        $newRole = $request->validated('role');

        if ($target->is($actor)) {
            throw ValidationException::withMessages([
                'email' => ['You cannot change your own staff role.'],
            ]);
        }

        $currentRole = $target->roles()->pluck('name')->first();

        if ($currentRole === 'administrator' && $newRole !== 'administrator') {
            $remainingAdministrators = User::role('administrator')
                ->where('id', '!=', $target->id)
                ->count();

            if ($remainingAdministrators === 0) {
                throw ValidationException::withMessages([
                    'role' => ['This is the last Administrator. Assign another Administrator before removing this one.'],
                ]);
            }
        }

        $correlationId = (string) Str::uuid();

        DB::transaction(function () use ($target, $newRole, $currentRole, $actor, $request, $correlationId): void {
            $target->syncRoles($newRole !== null ? [$newRole] : []);

            AuditEvent::record(
                actor: $actor,
                action: 'staff.role_changed',
                entityType: 'user',
                entityId: $target->id,
                before: ['role' => $currentRole],
                after: ['role' => $newRole],
                reason: $request->validated('reason'),
                correlationId: $correlationId,
            );
        });

        return back()->with('status', 'staff-role-updated');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentRoleChanges(): array
    {
        return AuditEvent::query()
            ->where('action', 'staff.role_changed')
            ->with('actor:id,name')
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(function (AuditEvent $event) {
                $target = User::find($event->entity_id);

                return [
                    'actor' => $event->actor?->name,
                    'target' => $target?->name ?? 'Deleted user',
                    'beforeRole' => $event->before_state['role'] ?? null,
                    'afterRole' => $event->after_state['role'] ?? null,
                    'reason' => $event->reason,
                    'occurredAt' => $event->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }
}
