<?php

use App\Enums\AccountStatus;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\AccountStatusTransitioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function statusAdministrator(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    return $admin;
}

test('every allowed transition succeeds', function (string $from, string $to) {
    $admin = statusAdministrator();
    $target = User::factory()->create(['account_status' => AccountStatus::from($from)]);

    app(AccountStatusTransitioner::class)->transition($admin, $target, AccountStatus::from($to), 'Routine test transition.');

    expect($target->fresh()->account_status)->toBe(AccountStatus::from($to));
})->with([
    ['active', 'limited'],
    ['active', 'suspended'],
    ['active', 'closed'],
    ['limited', 'active'],
    ['limited', 'suspended'],
    ['limited', 'closed'],
    ['suspended', 'active'],
    ['suspended', 'limited'],
    ['suspended', 'closed'],
    ['closed', 'active'],
]);

test('forbidden transitions are rejected and change nothing', function (string $from, string $to) {
    $admin = statusAdministrator();
    $target = User::factory()->create(['account_status' => AccountStatus::from($from)]);

    expect(fn () => app(AccountStatusTransitioner::class)->transition($admin, $target, AccountStatus::from($to), 'Attempting a forbidden transition.'))
        ->toThrow(ValidationException::class);

    expect($target->fresh()->account_status)->toBe(AccountStatus::from($from));
    expect(AuditEvent::count())->toBe(0);
})->with([
    ['closed', 'limited'],
    ['closed', 'suspended'],
]);

test('same-status transitions are rejected', function () {
    $admin = statusAdministrator();
    $target = User::factory()->create(['account_status' => AccountStatus::Active]);

    expect(fn () => app(AccountStatusTransitioner::class)->transition($admin, $target, AccountStatus::Active, 'No-op attempt.'))
        ->toThrow(ValidationException::class);

    expect(AuditEvent::count())->toBe(0);
});

test('staff cannot change their own account status', function () {
    $admin = statusAdministrator();

    expect(fn () => app(AccountStatusTransitioner::class)->transition($admin, $admin, AccountStatus::Suspended, 'Trying to suspend myself.'))
        ->toThrow(ValidationException::class);

    expect($admin->fresh()->account_status)->toBe(AccountStatus::Active);
    expect(AuditEvent::count())->toBe(0);
});

test('a user without users.status.manage cannot transition anyone', function () {
    $normalUser = User::factory()->create();
    $target = User::factory()->create();

    expect(fn () => app(AccountStatusTransitioner::class)->transition($normalUser, $target, AccountStatus::Suspended, 'Unauthorized attempt.'))
        ->toThrow(UnauthorizedException::class);

    expect($target->fresh()->account_status)->toBe(AccountStatus::Active);
    expect(AuditEvent::count())->toBe(0);
});

test('a staff member holding only users.limit cannot transition status', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');
    $target = User::factory()->create();

    expect(fn () => app(AccountStatusTransitioner::class)->transition($moderator, $target, AccountStatus::Suspended, 'Moderator attempt.'))
        ->toThrow(UnauthorizedException::class);
});

test('an empty or whitespace-only reason is rejected', function (string $reason) {
    $admin = statusAdministrator();
    $target = User::factory()->create();

    expect(fn () => app(AccountStatusTransitioner::class)->transition($admin, $target, AccountStatus::Suspended, $reason))
        ->toThrow(ValidationException::class);

    expect($target->fresh()->account_status)->toBe(AccountStatus::Active);
})->with(['', '   ']);

test('entering suspended deletes every existing session for the target', function () {
    $admin = statusAdministrator();
    $target = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'session-1', 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time(),
    ]);
    DB::table('sessions')->insert([
        'id' => 'session-2', 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time(),
    ]);

    app(AccountStatusTransitioner::class)->transition($admin, $target, AccountStatus::Suspended, 'Suspending for review.');

    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);
});

test('entering closed deletes every existing session for the target', function () {
    $admin = statusAdministrator();
    $target = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'session-3', 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time(),
    ]);

    app(AccountStatusTransitioner::class)->transition($admin, $target, AccountStatus::Closed, 'Closing the account.');

    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);
});

test('entering limited does not delete existing sessions', function () {
    $admin = statusAdministrator();
    $target = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'session-4', 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time(),
    ]);

    app(AccountStatusTransitioner::class)->transition($admin, $target, AccountStatus::Limited, 'Limiting the account.');

    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(1);
});

test('a successful transition creates exactly one audit event with only allowlisted fields', function () {
    $admin = statusAdministrator();
    $target = User::factory()->create();

    app(AccountStatusTransitioner::class)->transition($admin, $target, AccountStatus::Limited, 'Policy violation review.');

    expect(AuditEvent::count())->toBe(1);

    $event = AuditEvent::sole();
    expect($event->actor_id)->toBe($admin->id);
    expect($event->action)->toBe('account.status_changed');
    expect($event->entity_type)->toBe('user');
    expect($event->entity_id)->toBe($target->id);
    expect($event->reason)->toBe('Policy violation review.');
    expect($event->correlation_id)->not->toBeNull();
    expect($event->before_state)->toBe(['status' => 'active']);
    expect($event->after_state)->toBe(['status' => 'limited']);
    expect(array_keys($event->before_state))->toBe(['status']);
    expect(array_keys($event->after_state))->toBe(['status']);
});

test('a forced audit failure rolls back the status change and any session deletion', function () {
    $admin = statusAdministrator();
    $target = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'session-5', 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time(),
    ]);

    AuditEvent::creating(function (): void {
        throw new RuntimeException('Simulated audit failure.');
    });

    expect(fn () => app(AccountStatusTransitioner::class)->transition($admin, $target, AccountStatus::Suspended, 'Forcing a failure.'))
        ->toThrow(RuntimeException::class);

    expect($target->fresh()->account_status)->toBe(AccountStatus::Active);
    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(1);
    expect(AuditEvent::count())->toBe(0);
});

test('the before-state always reflects the true locked row, never a stale in-memory value', function () {
    $admin = statusAdministrator();
    $target = User::factory()->create(['account_status' => AccountStatus::Active]);

    // Simulate another process having already changed the row between
    // when this caller first loaded $target and when transition() runs -
    // $target's own in-memory attributes still say "active".
    DB::table('users')->where('id', $target->id)->update(['account_status' => 'limited']);

    app(AccountStatusTransitioner::class)->transition($admin, $target, AccountStatus::Suspended, 'Acting on possibly-stale data.');

    $event = AuditEvent::sole();
    expect($event->before_state)->toBe(['status' => 'limited']);
    expect($event->after_state)->toBe(['status' => 'suspended']);
});

test('a transition that becomes invalid due to a concurrent change is rejected using the true current state', function () {
    $admin = statusAdministrator();
    $target = User::factory()->create(['account_status' => AccountStatus::Active]);

    // The caller's in-memory $target still says "active" (active->closed
    // would be valid), but the row was actually already closed by
    // another process - closed only allows a transition back to active.
    DB::table('users')->where('id', $target->id)->update(['account_status' => 'closed']);

    expect(fn () => app(AccountStatusTransitioner::class)->transition($admin, $target, AccountStatus::Suspended, 'Stale assumption.'))
        ->toThrow(ValidationException::class);

    expect($target->fresh()->account_status)->toBe(AccountStatus::Closed);
    expect(AuditEvent::count())->toBe(0);
});
