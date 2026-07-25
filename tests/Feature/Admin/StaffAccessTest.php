<?php

use App\Models\AuditEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function actingAdministrator(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    return $admin;
}

test('role assignment requires staff.manage', function () {
    $viewer = User::factory()->create();
    // No role with only staff.view exists in the seeded set, so simulate
    // a hypothetical viewer by giving moderator (no staff.manage) and
    // confirming the write route itself is inaccessible.
    $viewer->assignRole('moderator');
    $target = User::factory()->create();

    $response = $this->actingAs($viewer)->post('/admin/staff-access', [
        'email' => $target->email,
        'role' => 'moderator',
        'reason' => 'Attempting without staff.manage.',
    ]);

    $response->assertForbidden();
    expect($target->fresh()->roles)->toHaveCount(0);
});

test('role names are validated against the server-controlled role records', function () {
    $admin = actingAdministrator();
    $target = User::factory()->create();

    $response = $this->actingAs($admin)->post('/admin/staff-access', [
        'email' => $target->email,
        'role' => 'super-admin-made-up',
        'reason' => 'Trying an unknown role name.',
    ]);

    $response->assertSessionHasErrors('role');
    expect($target->fresh()->roles)->toHaveCount(0);
});

test('self-role modification is rejected', function () {
    $admin = actingAdministrator();

    $response = $this->actingAs($admin)->post('/admin/staff-access', [
        'email' => $admin->email,
        'role' => 'moderator',
        'reason' => 'Trying to change my own role.',
    ]);

    $response->assertSessionHasErrors('email');
    expect($admin->fresh()->hasRole('administrator'))->toBeTrue();
    expect(AuditEvent::count())->toBe(0);
});

test('removing the final active Administrator is rejected', function () {
    // Exactly one Administrator overall. Whoever performs a role change
    // must themselves hold staff.manage, and in normal operation only
    // the Administrator role grants that - meaning an actor who is
    // themselves an Administrator can never be "the one demoting the
    // last other Administrator" (they would be a second one). To
    // exercise the guard itself, the acting user here holds staff.manage
    // as a direct permission grant rather than through the Administrator
    // role - this is a test-only construction, never how the real UI
    // grants access (it only ever assigns predefined roles).
    $target = User::factory()->create();
    $target->assignRole('administrator');

    $actor = User::factory()->create();
    $actor->givePermissionTo('staff.manage');

    $response = $this->actingAs($actor)->post('/admin/staff-access', [
        'email' => $target->email,
        'role' => 'moderator',
        'reason' => 'Attempting to demote the only administrator.',
    ]);

    $response->assertSessionHasErrors('role');
    expect($target->fresh()->hasRole('administrator'))->toBeTrue();
});

test('demoting one of several administrators is allowed', function () {
    $admin = actingAdministrator();
    $target = User::factory()->create();
    $target->assignRole('administrator');

    $response = $this->actingAs($admin)->post('/admin/staff-access', [
        'email' => $target->email,
        'role' => 'moderator',
        'reason' => 'Another administrator remains, so this is safe.',
    ]);

    $response->assertRedirect();
    expect($target->fresh()->hasRole('administrator'))->toBeFalse();
    expect($target->fresh()->hasRole('moderator'))->toBeTrue();
});

test('a role change and its audit event are atomic - a forced audit failure rolls back the role change', function () {
    $admin = actingAdministrator();
    $target = User::factory()->create();

    AuditEvent::creating(function (): void {
        throw new RuntimeException('Simulated audit failure.');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($admin)->post('/admin/staff-access', [
        'email' => $target->email,
        'role' => 'moderator',
        'reason' => 'Forcing an audit failure to prove atomicity.',
    ]))->toThrow(RuntimeException::class);

    expect($target->fresh()->roles)->toHaveCount(0);
    expect(AuditEvent::count())->toBe(0);
});

test('unauthorized attempts create no audit event', function () {
    $normalUser = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($normalUser)->post('/admin/staff-access', [
        'email' => $target->email,
        'role' => 'moderator',
        'reason' => 'Should never be recorded.',
    ]);

    expect(AuditEvent::count())->toBe(0);
});

test('a successful role change creates exactly one audit event with only allowlisted fields', function () {
    $admin = actingAdministrator();
    $target = User::factory()->create();

    $this->actingAs($admin)->post('/admin/staff-access', [
        'email' => $target->email,
        'role' => 'moderator',
        'reason' => 'Promoting to moderator for content review.',
    ]);

    expect(AuditEvent::count())->toBe(1);

    $event = AuditEvent::sole();
    expect($event->actor_id)->toBe($admin->id);
    expect($event->action)->toBe('staff.role_changed');
    expect($event->entity_type)->toBe('user');
    expect($event->entity_id)->toBe($target->id);
    expect($event->reason)->toBe('Promoting to moderator for content review.');
    expect($event->correlation_id)->not->toBeNull();

    // Allowlisted fields only - no password, token, session, or raw model data.
    expect($event->before_state)->toBe(['role' => null]);
    expect($event->after_state)->toBe(['role' => 'moderator']);
    expect(array_keys($event->before_state))->toBe(['role']);
    expect(array_keys($event->after_state))->toBe(['role']);
});

test('assigning a role replaces any previous role and can also remove staff access entirely', function () {
    $admin = actingAdministrator();
    $target = User::factory()->create();
    $target->assignRole('support-agent');

    $this->actingAs($admin)->post('/admin/staff-access', [
        'email' => $target->email,
        'role' => null,
        'reason' => 'Removing staff access entirely.',
    ]);

    expect($target->fresh()->roles)->toHaveCount(0);
});

test('no earner or advertiser role exists', function () {
    expect(Role::whereIn('name', ['earner', 'advertiser'])->exists())->toBeFalse();
});
