<?php

use App\Exceptions\InvalidAuditEventIdentifierException;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('record() remains backward compatible with existing integer-entity named-argument callers', function () {
    $actor = User::factory()->create();

    $event = AuditEvent::record(
        actor: $actor,
        action: 'account.status_changed',
        entityType: 'user',
        entityId: $actor->id,
        before: ['status' => 'active'],
        after: ['status' => 'suspended'],
        reason: 'Test reason',
    );

    expect($event->entity_id)->toBe($actor->id);
    expect($event->entity_key)->toBeNull();
});

test('record() allows entityId and entityKey to both be null, unchanged from prior behavior', function () {
    $actor = User::factory()->create();

    $event = AuditEvent::record(
        actor: $actor,
        action: 'test.entityless_action',
        entityType: 'test',
        entityId: null,
        before: [],
        after: [],
        reason: 'test',
    );

    expect($event->entity_id)->toBeNull();
    expect($event->entity_key)->toBeNull();
});

test('record() rejects both entityId and entityKey being supplied together', function () {
    $actor = User::factory()->create();

    expect(fn () => AuditEvent::record(
        actor: $actor,
        action: 'test.both_identifiers',
        entityType: 'test',
        entityId: 1,
        before: [],
        after: [],
        reason: 'test',
        entityKey: 'some-key',
    ))->toThrow(InvalidAuditEventIdentifierException::class);

    expect(AuditEvent::count())->toBe(0);
});

test('record() trims entityKey and rejects an empty string after trimming', function () {
    $actor = User::factory()->create();

    $event = AuditEvent::record(
        actor: $actor,
        action: 'test.trim',
        entityType: 'test',
        entityId: null,
        before: [],
        after: [],
        reason: 'test',
        entityKey: '  some-key  ',
    );

    expect($event->entity_key)->toBe('some-key');

    expect(fn () => AuditEvent::record(
        actor: $actor,
        action: 'test.blank_key',
        entityType: 'test',
        entityId: null,
        before: [],
        after: [],
        reason: 'test',
        entityKey: '   ',
    ))->toThrow(InvalidAuditEventIdentifierException::class);
});

test('record() rejects an entityKey containing a control character', function () {
    $actor = User::factory()->create();

    expect(fn () => AuditEvent::record(
        actor: $actor,
        action: 'test.control_char',
        entityType: 'test',
        entityId: null,
        before: [],
        after: [],
        reason: 'test',
        entityKey: "bad\nkey",
    ))->toThrow(InvalidAuditEventIdentifierException::class);
});

test('record() rejects an entityKey longer than 191 characters and accepts exactly 191', function () {
    $actor = User::factory()->create();

    expect(fn () => AuditEvent::record(
        actor: $actor,
        action: 'test.too_long',
        entityType: 'test',
        entityId: null,
        before: [],
        after: [],
        reason: 'test',
        entityKey: str_repeat('a', 192),
    ))->toThrow(InvalidAuditEventIdentifierException::class);

    $event = AuditEvent::record(
        actor: $actor,
        action: 'test.exact_boundary',
        entityType: 'test',
        entityId: null,
        before: [],
        after: [],
        reason: 'test',
        entityKey: str_repeat('a', 191),
    );

    expect(strlen($event->entity_key))->toBe(191);
});

test('the database enforces exact-once uniqueness on (entity_type, entity_key, action)', function () {
    $this->insertRawAuditEvent([
        'entity_type' => 'ledger_transaction',
        'entity_key' => 'shared-key',
        'action' => 'ledger.administrative_adjustment',
    ]);

    expect(fn () => $this->insertRawAuditEvent([
        'entity_type' => 'ledger_transaction',
        'entity_key' => 'shared-key',
        'action' => 'ledger.administrative_adjustment',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('two audit_events rows with a null entity_key never collide, matching existing rows unaffected by the new constraint', function () {
    $this->insertRawAuditEvent(['entity_type' => 'user', 'entity_key' => null, 'action' => 'account.status_changed']);
    $this->insertRawAuditEvent(['entity_type' => 'user', 'entity_key' => null, 'action' => 'account.status_changed']);

    expect(AuditEvent::where('entity_type', 'user')->where('entity_key', null)->count())->toBe(2);
});

test('a different action for the same entity_type and entity_key is not blocked by the unique index', function () {
    $this->insertRawAuditEvent([
        'entity_type' => 'ledger_transaction',
        'entity_key' => 'shared-key-different-action',
        'action' => 'ledger.administrative_adjustment',
    ]);

    $this->insertRawAuditEvent([
        'entity_type' => 'ledger_transaction',
        'entity_key' => 'shared-key-different-action',
        'action' => 'ledger.administrative_adjustment.reviewed',
    ]);

    expect(AuditEvent::where('entity_key', 'shared-key-different-action')->count())->toBe(2);
});
