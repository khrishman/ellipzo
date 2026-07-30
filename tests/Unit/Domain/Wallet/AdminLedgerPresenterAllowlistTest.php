<?php

use App\Domain\Wallet\Services\AdminLedgerPresenter;

/**
 * Direct proof that AdminLedgerPresenter::filterByAllowlist() is a
 * genuinely recursive allowlist filter, not a flat top-level key check -
 * exercised here with a synthetic spec/payload (AdministrativeAdjustment's
 * own real payload is flat, so this is the only way to prove the
 * mechanism correctly preserves a legitimately *nested* approved field
 * while still stripping every canary, without fabricating nested fields
 * into the real production ledger-audit contract). Reflection against a
 * private pure static function mirrors this codebase's own established
 * precedent (Task 2.5's fingerprint tests), not framework internals.
 */
function invokeFilterByAllowlist(array $data, array $spec): array
{
    $method = new ReflectionMethod(AdminLedgerPresenter::class, 'filterByAllowlist');
    $method->setAccessible(true);

    return $method->invoke(null, $data, $spec);
}

test('approved scalar and list-of-scalars fields survive unchanged', function () {
    $spec = [
        'label' => 'scalar',
        'count' => 'scalar',
        'tags' => 'list-of-scalars',
    ];
    $data = [
        'label' => 'approved-scalar-value',
        'count' => 42,
        'tags' => ['alpha', 'beta'],
    ];

    expect(invokeFilterByAllowlist($data, $spec))->toBe($data);
});

test('a legitimately nested approved field is recursively filtered and survives', function () {
    $spec = [
        'label' => 'scalar',
        'metadata' => [
            'note' => 'scalar',
            'tags' => 'list-of-scalars',
        ],
    ];
    $data = [
        'label' => 'approved-scalar-value',
        'metadata' => [
            'note' => 'approved-nested-scalar',
            'tags' => ['ok-1', 'ok-2'],
        ],
    ];

    expect(invokeFilterByAllowlist($data, $spec))->toBe($data);
});

test('an unapproved top-level canary key is dropped entirely', function () {
    $spec = ['label' => 'scalar'];
    $data = [
        'label' => 'approved-scalar-value',
        'internal_staff_note' => 'CANARY_TOP_LEVEL_SHOULD_NEVER_SURVIVE',
    ];

    $result = invokeFilterByAllowlist($data, $spec);

    expect($result)->toBe(['label' => 'approved-scalar-value']);
    expect($result)->not->toHaveKey('internal_staff_note');
});

test('an unexpected nested canary under an approved-but-mis-specified key is dropped', function () {
    // "label" is declared scalar, but the data supplies a nested array
    // under that same key name - a type-mismatch smuggling attempt. The
    // key name matching the allowlist must not be enough to trust the
    // value's shape.
    $spec = ['label' => 'scalar'];
    $data = [
        'label' => ['smuggled' => 'CANARY_NESTED_UNDER_APPROVED_KEY'],
    ];

    expect(invokeFilterByAllowlist($data, $spec))->toBe([]);
});

test('a canary hidden inside an unapproved array-valued key never survives', function () {
    $spec = ['label' => 'scalar'];
    $data = [
        'label' => 'approved-scalar-value',
        'related_ids' => ['ok-1', 'CANARY_ARRAY_ELEMENT_SHOULD_NEVER_SURVIVE'],
    ];

    $result = invokeFilterByAllowlist($data, $spec);

    expect($result)->toBe(['label' => 'approved-scalar-value']);
    expect($result)->not->toHaveKey('related_ids');
});

test('a non-scalar element inside an approved list-of-scalars field is dropped, not the whole list', function () {
    $spec = ['tags' => 'list-of-scalars'];
    $data = [
        'tags' => ['ok-1', ['nested' => 'CANARY_INSIDE_LIST_ELEMENT'], 'ok-2'],
    ];

    expect(invokeFilterByAllowlist($data, $spec))->toBe(['tags' => ['ok-1', 'ok-2']]);
});

test('an empty allowlist spec always yields an empty array regardless of input', function () {
    $data = ['anything' => 'CANARY_SHOULD_NEVER_SURVIVE', 'nested' => ['x' => 'y']];

    expect(invokeFilterByAllowlist($data, []))->toBe([]);
});
