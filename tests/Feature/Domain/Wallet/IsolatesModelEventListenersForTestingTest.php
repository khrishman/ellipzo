<?php

use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use Illuminate\Support\Str;

/**
 * Proves the withIsolatedCreatingListener() helper itself is safe - not
 * merely that the tests using it happen to pass. Global event-dispatcher
 * state is exactly the kind of thing that can silently leak between
 * tests, so each claim here is proven directly rather than inferred.
 */
test('the original dispatcher object is restored after successful execution', function () {
    $original = LedgerEntry::getEventDispatcher();

    $this->withIsolatedCreatingListener(LedgerEntry::class, function (): void {}, function (): void {
        // no-op run callback
    });

    expect(LedgerEntry::getEventDispatcher())->toBe($original);
});

test('the original dispatcher object is restored after the callback throws', function () {
    $original = LedgerEntry::getEventDispatcher();

    try {
        $this->withIsolatedCreatingListener(LedgerEntry::class, function (): void {}, function (): void {
            throw new RuntimeException('forced failure inside run()');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(LedgerEntry::getEventDispatcher())->toBe($original);
});

test('original model guards still run inside the isolated dispatcher', function () {
    // LedgerEntry's own creating() guard (LedgerWriteContext::isActive())
    // must still fire while the isolated dispatcher is in place - proving
    // isolation adds a listener, it does not replace or hide the existing
    // ones.
    $outcome = null;

    $this->withIsolatedCreatingListener(LedgerEntry::class, function (): void {}, function () use (&$outcome): void {
        $entry = new LedgerEntry;
        $entry->ledger_transaction_id = (string) Str::ulid();
        $entry->wallet_account_id = (string) Str::ulid();
        $entry->entry_type = LedgerEntryType::Credit;
        $entry->amount_atomic = 100;

        try {
            $entry->save();
            $outcome = 'did not throw';
        } catch (LedgerInvariantViolationException) {
            $outcome = 'blocked as expected';
        }
    });

    expect($outcome)->toBe('blocked as expected');
});

test('a temporary listener does not run after restoration', function () {
    $accounts = $this->provisionTestAccounts();
    $forcedCallCount = 0;

    $this->withIsolatedCreatingListener(
        LedgerEntry::class,
        function () use (&$forcedCallCount): void {
            $forcedCallCount++;
        },
        function (): void {
            // The temporary listener is registered but never exercised
            // inside this run() - the point is what happens afterward.
        },
    );

    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-isolation-no-leak');
    $engine = new LedgerPostingEngine;
    $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));

    expect($forcedCallCount)->toBe(0);
});

test('running two isolated-listener calls sequentially does not double-wrap or duplicate the original listener', function () {
    $eventName = 'eloquent.creating: '.LedgerEntry::class;
    $originalDispatcher = LedgerEntry::getEventDispatcher();
    $originalCount = count($originalDispatcher->getListeners($eventName));

    $this->withIsolatedCreatingListener(LedgerEntry::class, function (): void {}, function (): void {});
    $this->withIsolatedCreatingListener(LedgerEntry::class, function (): void {}, function (): void {});

    expect(LedgerEntry::getEventDispatcher())->toBe($originalDispatcher);
    expect(count(LedgerEntry::getEventDispatcher()->getListeners($eventName)))->toBe($originalCount);
});

test('many sequential isolated-listener operations, including ones that throw, leave the dispatcher and listener count stable', function () {
    $eventName = 'eloquent.creating: '.LedgerEntry::class;
    $originalDispatcher = LedgerEntry::getEventDispatcher();
    $originalCount = count($originalDispatcher->getListeners($eventName));

    for ($i = 0; $i < 5; $i++) {
        try {
            $this->withIsolatedCreatingListener(LedgerEntry::class, function (): void {}, function () use ($i): void {
                if ($i % 2 === 0) {
                    throw new RuntimeException('forced failure on iteration '.$i);
                }
            });
        } catch (RuntimeException) {
            // expected on even iterations
        }
    }

    expect(LedgerEntry::getEventDispatcher())->toBe($originalDispatcher);
    expect(count(LedgerEntry::getEventDispatcher()->getListeners($eventName)))->toBe($originalCount);
});

test('isolating one model\'s event name leaves a different model\'s event-name listener count unaffected', function () {
    // Illuminate\Database\Eloquent\Model declares $dispatcher once, shared
    // by every model class - LedgerEntry::getEventDispatcher() and
    // WalletAccount::getEventDispatcher() return the same object.
    // Isolation still only appends to the "eloquent.creating: LedgerEntry"
    // array key, so WalletAccount's own key is untouched throughout.
    $walletEventName = 'eloquent.creating: '.WalletAccount::class;
    $originalWalletListenerCount = count(
        WalletAccount::getEventDispatcher()->getListeners($walletEventName)
    );

    $this->withIsolatedCreatingListener(LedgerEntry::class, function (): void {}, function (): void {});

    expect(count(WalletAccount::getEventDispatcher()->getListeners($walletEventName)))
        ->toBe($originalWalletListenerCount);
});
