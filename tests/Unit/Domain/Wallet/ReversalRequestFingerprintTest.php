<?php

use App\Domain\Wallet\Services\ReversalRequestService;

/**
 * computeFingerprint() is private - a pure, deterministic static function
 * with no side effects, so invoking it via ReflectionMethod here is
 * ordinary unit-test access, not a reflection-against-framework-internals
 * shortcut (the kind of ReflectionProperty use against Dispatcher storage
 * that is permanently forbidden elsewhere in this project).
 */
function computeReversalFingerprint(string $originalTransactionId, ?int $actorId, string $reason): string
{
    $method = new ReflectionMethod(ReversalRequestService::class, 'computeFingerprint');

    return $method->invoke(null, $originalTransactionId, $actorId, $reason);
}

test('the fingerprint is a versioned canonical JSON payload hashed with sha256, in the exact given key order', function () {
    $originalId = '01hzzzzzzzzzzzzzzzzzzzzzzz';
    $actorId = 7;
    $reason = 'duplicate charge';

    $expectedJson = '{"version":1,"original_transaction_id":"01hzzzzzzzzzzzzzzzzzzzzzzz","actor_id":7,"reason":"duplicate charge"}';
    $expectedDigest = hash('sha256', $expectedJson);

    $fingerprint = computeReversalFingerprint($originalId, $actorId, $reason);

    expect($fingerprint)->toBe($expectedDigest);
    expect($fingerprint)->toHaveLength(64);
    expect($fingerprint)->toBe(strtolower($fingerprint));
});

test('a null actor is encoded as a literal JSON null, not an omitted key', function () {
    $originalId = '01hzzzzzzzzzzzzzzzzzzzzzzz';
    $reason = 'refund requested';

    $expectedJson = '{"version":1,"original_transaction_id":"01hzzzzzzzzzzzzzzzzzzzzzzz","actor_id":null,"reason":"refund requested"}';

    expect(computeReversalFingerprint($originalId, null, $reason))->toBe(hash('sha256', $expectedJson));
});

test('a naive concatenation of a null actor and reason that would collide with a real actor ID prefix produces different fingerprints', function () {
    $originalId = '01hzzzzzzzzzzzzzzzzzzzzzzz';

    // A naive "{$actorId}{$reason}" join (empty string standing in for a
    // null actor) would collide here: '' . '7hello' === '7' . 'hello'.
    // The canonical, versioned, keyed JSON encoding must not.
    $fingerprintA = computeReversalFingerprint($originalId, null, '7hello');
    $fingerprintB = computeReversalFingerprint($originalId, 7, 'hello');

    expect($fingerprintA)->not->toBe($fingerprintB);
});

test('a colon embedded in the reason cannot be confused with a naive field-boundary delimiter', function () {
    $originalId = '01hzzzzzzzzzzzzzzzzzzzzzzz';

    $fingerprintA = computeReversalFingerprint($originalId, 3, 'part-one:part-two');
    $fingerprintB = computeReversalFingerprint($originalId, 3, 'part-one');

    expect($fingerprintA)->not->toBe($fingerprintB);
});

test('unicode content in the reason hashes deterministically', function () {
    $originalId = '01hzzzzzzzzzzzzzzzzzzzzzzz';
    $reason = 'café refund — 已退款 🙂';

    $first = computeReversalFingerprint($originalId, null, $reason);
    $second = computeReversalFingerprint($originalId, null, $reason);

    expect($first)->toBe($second);
    expect($first)->toHaveLength(64);
});

test('two reasons differing only by unicode content never collide', function () {
    $originalId = '01hzzzzzzzzzzzzzzzzzzzzzzz';

    $fingerprintA = computeReversalFingerprint($originalId, null, 'café refund');
    $fingerprintB = computeReversalFingerprint($originalId, null, 'cafe refund');

    expect($fingerprintA)->not->toBe($fingerprintB);
});

test('a different original transaction ID always changes the fingerprint even with identical actor and reason', function () {
    $fingerprintA = computeReversalFingerprint('01haaaaaaaaaaaaaaaaaaaaaaa', 5, 'same reason');
    $fingerprintB = computeReversalFingerprint('01hbbbbbbbbbbbbbbbbbbbbbbb', 5, 'same reason');

    expect($fingerprintA)->not->toBe($fingerprintB);
});

test('a different actor always changes the fingerprint even with identical original and reason', function () {
    $originalId = '01hzzzzzzzzzzzzzzzzzzzzzzz';

    $fingerprintA = computeReversalFingerprint($originalId, 1, 'same reason');
    $fingerprintB = computeReversalFingerprint($originalId, 2, 'same reason');

    expect($fingerprintA)->not->toBe($fingerprintB);
});
