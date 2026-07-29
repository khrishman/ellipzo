<?php

use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Services\IncrementalLedgerFingerprint;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * uses(TestCase::class): see LedgerBalanceCalculatorTest.php's own
 * identical explanation - constructing a cast Eloquent property requires
 * the app's connection resolver to exist, even with zero database
 * queries.
 */
uses(TestCase::class);

/**
 * No database access: every LedgerEntry here is in-memory only, never
 * saved.
 */
function makeFingerprintTestEntry(
    string $id,
    string $transactionId,
    string $walletAccountId,
    LedgerEntryType $type,
    int $amountAtomic,
    string $createdAt,
): LedgerEntry {
    $entry = new LedgerEntry;
    $entry->id = $id;
    $entry->ledger_transaction_id = $transactionId;
    $entry->wallet_account_id = $walletAccountId;
    $entry->entry_type = $type;
    $entry->amount_atomic = $amountAtomic;
    $entry->created_at = Carbon::parse($createdAt, 'UTC');

    return $entry;
}

test('an empty account produces a deterministic fingerprint', function () {
    $first = (new IncrementalLedgerFingerprint('account-a'))->finalHex();
    $second = (new IncrementalLedgerFingerprint('account-a'))->finalHex();

    expect($first)->toBe($second);
    expect($first)->toMatch('/^[0-9a-f]{64}$/');
});

test('different wallet account IDs produce different empty fingerprints', function () {
    $a = (new IncrementalLedgerFingerprint('account-a'))->finalHex();
    $b = (new IncrementalLedgerFingerprint('account-b'))->finalHex();

    expect($a)->not->toBe($b);
});

test('repeated computation over the identical entry stream is deterministic', function () {
    $entry = makeFingerprintTestEntry('entry-1', 'txn-1', 'account-a', LedgerEntryType::Credit, 500, '2026-07-29 10:00:00');

    $first = new IncrementalLedgerFingerprint('account-a');
    $first->update($entry);

    $second = new IncrementalLedgerFingerprint('account-a');
    $second->update($entry);

    expect($first->finalHex())->toBe($second->finalHex());
});

test('changing the amount changes the fingerprint', function () {
    $fp1 = new IncrementalLedgerFingerprint('account-a');
    $fp1->update(makeFingerprintTestEntry('entry-1', 'txn-1', 'account-a', LedgerEntryType::Credit, 500, '2026-07-29 10:00:00'));

    $fp2 = new IncrementalLedgerFingerprint('account-a');
    $fp2->update(makeFingerprintTestEntry('entry-1', 'txn-1', 'account-a', LedgerEntryType::Credit, 501, '2026-07-29 10:00:00'));

    expect($fp1->finalHex())->not->toBe($fp2->finalHex());
});

test('changing the entry type changes the fingerprint', function () {
    $fp1 = new IncrementalLedgerFingerprint('account-a');
    $fp1->update(makeFingerprintTestEntry('entry-1', 'txn-1', 'account-a', LedgerEntryType::Credit, 500, '2026-07-29 10:00:00'));

    $fp2 = new IncrementalLedgerFingerprint('account-a');
    $fp2->update(makeFingerprintTestEntry('entry-1', 'txn-1', 'account-a', LedgerEntryType::Debit, 500, '2026-07-29 10:00:00'));

    expect($fp1->finalHex())->not->toBe($fp2->finalHex());
});

test('changing the timestamp changes the fingerprint', function () {
    $fp1 = new IncrementalLedgerFingerprint('account-a');
    $fp1->update(makeFingerprintTestEntry('entry-1', 'txn-1', 'account-a', LedgerEntryType::Credit, 500, '2026-07-29 10:00:00'));

    $fp2 = new IncrementalLedgerFingerprint('account-a');
    $fp2->update(makeFingerprintTestEntry('entry-1', 'txn-1', 'account-a', LedgerEntryType::Credit, 500, '2026-07-29 10:00:01'));

    expect($fp1->finalHex())->not->toBe($fp2->finalHex());
});

test('changing the entry ID or transaction ID changes the fingerprint', function () {
    $fp1 = new IncrementalLedgerFingerprint('account-a');
    $fp1->update(makeFingerprintTestEntry('entry-1', 'txn-1', 'account-a', LedgerEntryType::Credit, 500, '2026-07-29 10:00:00'));

    $fp2 = new IncrementalLedgerFingerprint('account-a');
    $fp2->update(makeFingerprintTestEntry('entry-2', 'txn-1', 'account-a', LedgerEntryType::Credit, 500, '2026-07-29 10:00:00'));

    $fp3 = new IncrementalLedgerFingerprint('account-a');
    $fp3->update(makeFingerprintTestEntry('entry-1', 'txn-2', 'account-a', LedgerEntryType::Credit, 500, '2026-07-29 10:00:00'));

    // finalHex() finalizes and invalidates its context - captured once
    // each, never called twice on the same instance.
    $baseline = $fp1->finalHex();

    expect($baseline)->not->toBe($fp2->finalHex());
    expect($baseline)->not->toBe($fp3->finalHex());
});

test('changing the order of two otherwise-identical entries changes the fingerprint', function () {
    $a = makeFingerprintTestEntry('entry-a', 'txn-1', 'account-x', LedgerEntryType::Credit, 100, '2026-07-29 10:00:00');
    $b = makeFingerprintTestEntry('entry-b', 'txn-2', 'account-x', LedgerEntryType::Debit, 50, '2026-07-29 10:00:01');

    $forward = new IncrementalLedgerFingerprint('account-x');
    $forward->update($a);
    $forward->update($b);

    $reversed = new IncrementalLedgerFingerprint('account-x');
    $reversed->update($b);
    $reversed->update($a);

    expect($forward->finalHex())->not->toBe($reversed->finalHex());
});

test('two entries whose concatenated fields could naively collide without length framing still produce distinct fingerprints', function () {
    // entry_id "ab" + transaction_id "cd" vs entry_id "a" + transaction_id "bcd" -
    // a naive delimiter-free concatenation could collide; length framing must not.
    $fp1 = new IncrementalLedgerFingerprint('account-a');
    $fp1->update(makeFingerprintTestEntry('ab', 'cd', 'account-a', LedgerEntryType::Credit, 500, '2026-07-29 10:00:00'));

    $fp2 = new IncrementalLedgerFingerprint('account-a');
    $fp2->update(makeFingerprintTestEntry('a', 'bcd', 'account-a', LedgerEntryType::Credit, 500, '2026-07-29 10:00:00'));

    expect($fp1->finalHex())->not->toBe($fp2->finalHex());
});
