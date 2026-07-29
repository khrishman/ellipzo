<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Models\BalanceSnapshot;
use App\Domain\Wallet\Services\BalanceSnapshotWriteContext;
use Illuminate\Support\Str;

function makeValidSnapshotFields(string $walletAccountId): array
{
    return [
        'wallet_account_id' => $walletAccountId,
        'balance_atomic' => 0,
        'currency_code' => Currency::USD,
        'currency_scale' => Currency::USD->scale(),
        'cutoff_ledger_entry_id' => null,
        'cutoff_entry_created_at' => null,
        'entry_count' => 0,
        'fingerprint_version' => 1,
        'fingerprint' => str_repeat('a', 64),
        // created_at deliberately omitted - Eloquent's own automatic
        // timestamp handling sets it on save(), exactly like every other
        // model in this domain (LedgerTransaction, LedgerEntry,
        // WalletAccount never assign it explicitly either).
    ];
}

test('a snapshot cannot be created outside BalanceSnapshotWriteContext', function () {
    $accounts = $this->provisionTestAccounts();

    expect(function () use ($accounts) {
        $snapshot = new BalanceSnapshot;
        foreach (makeValidSnapshotFields($accounts->earningAvailable->id) as $key => $value) {
            $snapshot->{$key} = $value;
        }
        $snapshot->save();
    })->toThrow(LedgerInvariantViolationException::class);

    expect(BalanceSnapshot::count())->toBe(0);
});

test('a valid snapshot can be created inside the write context', function () {
    $accounts = $this->provisionTestAccounts();

    $snapshot = BalanceSnapshotWriteContext::run(function () use ($accounts) {
        $snapshot = new BalanceSnapshot;
        foreach (makeValidSnapshotFields($accounts->earningAvailable->id) as $key => $value) {
            $snapshot->{$key} = $value;
        }
        $snapshot->save();

        return $snapshot;
    });

    expect($snapshot->exists)->toBeTrue();
    expect(BalanceSnapshot::count())->toBe(1);
});

test('multiple snapshots for the same account are allowed', function () {
    $accounts = $this->provisionTestAccounts();

    BalanceSnapshotWriteContext::run(function () use ($accounts): void {
        foreach (range(1, 3) as $_) {
            $snapshot = new BalanceSnapshot;
            foreach (makeValidSnapshotFields($accounts->earningAvailable->id) as $key => $value) {
                $snapshot->{$key} = $value;
            }
            $snapshot->save();
        }
    });

    expect(BalanceSnapshot::where('wallet_account_id', $accounts->earningAvailable->id)->count())->toBe(3);
});

test('a snapshot can never be updated, even inside the write context', function () {
    $accounts = $this->provisionTestAccounts();

    $snapshot = BalanceSnapshotWriteContext::run(function () use ($accounts) {
        $snapshot = new BalanceSnapshot;
        foreach (makeValidSnapshotFields($accounts->earningAvailable->id) as $key => $value) {
            $snapshot->{$key} = $value;
        }
        $snapshot->save();

        return $snapshot;
    });

    expect(function () use ($snapshot): void {
        BalanceSnapshotWriteContext::run(function () use ($snapshot): void {
            $snapshot->balance_atomic = 999;
            $snapshot->save();
        });
    })->toThrow(LogicException::class);
});

test('a snapshot can never be deleted', function () {
    $accounts = $this->provisionTestAccounts();

    $snapshot = BalanceSnapshotWriteContext::run(function () use ($accounts) {
        $snapshot = new BalanceSnapshot;
        foreach (makeValidSnapshotFields($accounts->earningAvailable->id) as $key => $value) {
            $snapshot->{$key} = $value;
        }
        $snapshot->save();

        return $snapshot;
    });

    expect(fn () => $snapshot->delete())->toThrow(LogicException::class);
    expect(BalanceSnapshot::count())->toBe(1);
});

test('mismatched null cutoff ID and timestamp are rejected', function () {
    $accounts = $this->provisionTestAccounts();

    expect(function () use ($accounts): void {
        BalanceSnapshotWriteContext::run(function () use ($accounts): void {
            $snapshot = new BalanceSnapshot;
            foreach (makeValidSnapshotFields($accounts->earningAvailable->id) as $key => $value) {
                $snapshot->{$key} = $value;
            }
            $snapshot->cutoff_ledger_entry_id = strtolower((string) Str::ulid());
            // cutoff_entry_created_at deliberately left null.
            $snapshot->save();
        });
    })->toThrow(LedgerInvariantViolationException::class);
});

test('a null cutoff with a non-zero entry count or balance is rejected', function () {
    $accounts = $this->provisionTestAccounts();

    expect(function () use ($accounts): void {
        BalanceSnapshotWriteContext::run(function () use ($accounts): void {
            $snapshot = new BalanceSnapshot;
            foreach (makeValidSnapshotFields($accounts->earningAvailable->id) as $key => $value) {
                $snapshot->{$key} = $value;
            }
            $snapshot->entry_count = 3;
            $snapshot->save();
        });
    })->toThrow(LedgerInvariantViolationException::class);
});

test('a non-USD currency or scale is rejected', function () {
    $accounts = $this->provisionTestAccounts();

    expect(function () use ($accounts): void {
        BalanceSnapshotWriteContext::run(function () use ($accounts): void {
            $snapshot = new BalanceSnapshot;
            foreach (makeValidSnapshotFields($accounts->earningAvailable->id) as $key => $value) {
                $snapshot->{$key} = $value;
            }
            $snapshot->currency_scale = 8;
            $snapshot->save();
        });
    })->toThrow(LedgerInvariantViolationException::class);
});

test('the write context resets to inactive even when a model-layer invariant violation throws inside it', function () {
    $accounts = $this->provisionTestAccounts();

    expect(BalanceSnapshotWriteContext::isActive())->toBeFalse();

    try {
        BalanceSnapshotWriteContext::run(function () use ($accounts): void {
            $snapshot = new BalanceSnapshot;
            foreach (makeValidSnapshotFields($accounts->earningAvailable->id) as $key => $value) {
                $snapshot->{$key} = $value;
            }
            $snapshot->currency_scale = 8; // forces the model's own invariant guard to throw
            $snapshot->save();
        });
    } catch (LedgerInvariantViolationException) {
        // expected
    }

    expect(BalanceSnapshotWriteContext::isActive())->toBeFalse();
    expect(BalanceSnapshot::count())->toBe(0);
});
