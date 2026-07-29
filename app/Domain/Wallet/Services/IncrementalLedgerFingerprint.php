<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Models\LedgerEntry;
use HashContext;

/**
 * A streaming SHA-256 fingerprint over one wallet account's ordered entry
 * set - never builds the entry list in PHP memory. update() is called once
 * per entry, in created_at ASC, id ASC order (the caller's responsibility;
 * this class has no ordering of its own to enforce). finalHex() may only
 * be called once, after every entry has been streamed through - PHP's own
 * hash_final() finalizes and invalidates the underlying context.
 *
 * Every record (the header, and each entry) is JSON-encoded with a fixed
 * key order, then explicitly length-framed (a 4-byte big-endian length
 * prefix via pack('N', ...)) before being fed to hash_update() - this
 * removes any need to reason about whether two different field values
 * could ever produce an identical concatenated byte stream when hashed
 * back-to-back with no separator. Mirrors the fixed-key-order,
 * JSON-then-hash shape ReversalRequestService::computeFingerprint()
 * already established for a single scalar payload, extended here to a
 * streamed, unbounded entry set.
 */
final class IncrementalLedgerFingerprint
{
    private const int VERSION = 1;

    private readonly HashContext $context;

    public function __construct(string $walletAccountId)
    {
        $this->context = hash_init('sha256');

        $this->feed(json_encode([
            'version' => self::VERSION,
            'wallet_account_id' => $walletAccountId,
        ], JSON_THROW_ON_ERROR));
    }

    public function update(LedgerEntry $entry): void
    {
        $this->feed(json_encode([
            'entry_id' => $entry->id,
            'transaction_id' => $entry->ledger_transaction_id,
            'wallet_account_id' => $entry->wallet_account_id,
            'entry_type' => $entry->entry_type->value,
            'amount_atomic' => (string) $entry->amount_atomic,
            // Fixed UTC representation at whole-second precision, matching
            // ledger_entries.created_at's own stored TIMESTAMP precision -
            // no fractional-second digits are ever invented here.
            'created_at' => $entry->created_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Lowercase 64-character hexadecimal SHA-256 digest. Consumes the
     * underlying hash context - calling this a second time is a logic
     * error, not a supported "peek" operation.
     */
    public function finalHex(): string
    {
        return hash_final($this->context);
    }

    private function feed(string $record): void
    {
        hash_update($this->context, pack('N', strlen($record)).$record);
    }
}
