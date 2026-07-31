<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

use Illuminate\Support\Str;

/**
 * Every scenario run gets one of these. Every fixture value it produces
 * (username, business reference, idempotency key) embeds the run's own
 * ULID, so cleanup can prove ownership of a row before deleting it -
 * never a broad timestamp-range or table-wide delete.
 */
final readonly class ConcurrencyRunNamespace
{
    public string $runId;

    public function __construct(public string $scenario, ?string $runId = null)
    {
        $this->runId = $runId ?? strtolower((string) Str::ulid());
    }

    /**
     * Fits the wallet-domain username format (^[A-Za-z0-9_]+$, 3-30 chars):
     * "cc" + 26-char lowercase ULID = 28 characters.
     */
    public function username(): string
    {
        return 'cc'.$this->runId;
    }

    /**
     * Fits ledger_transactions.business_reference
     * (^[a-z0-9:_-]+$, <=191 chars, must start with "{type}:").
     */
    public function businessReference(string $transactionTypeValue, string $suffix = ''): string
    {
        $slug = $suffix === '' ? $this->slug() : $this->slug().'-'.$suffix;

        return "{$transactionTypeValue}:{$slug}";
    }

    /**
     * Fits both reversal_requests.idempotency_key (<=191) and
     * SubmitAdministrativeAdjustmentCommand's idempotency key (<=165).
     */
    public function idempotencyKey(string $suffix = ''): string
    {
        return $suffix === '' ? $this->slug() : $this->slug().'-'.$suffix;
    }

    /**
     * Always a fresh canonical ULID - RequestLedgerReversalCommand/
     * PostLedgerTransactionCommand/SubmitAdministrativeAdjustmentCommand
     * all require a genuine UUID/ULID shape here, never a namespaced slug.
     */
    public function correlationId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * True only for a value this exact run produced - the sole ownership
     * check cleanup uses before deleting any row.
     */
    public function owns(?string $value): bool
    {
        return $value !== null && str_contains($value, $this->runId);
    }

    private function slug(): string
    {
        return "cc-{$this->scenario}-{$this->runId}";
    }
}
