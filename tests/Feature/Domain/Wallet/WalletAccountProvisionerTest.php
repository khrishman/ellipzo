<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Data\UserWalletAccounts;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\InvalidWalletAccountScopeException;
use App\Domain\Wallet\Exceptions\WalletAccountInvariantException;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('provisionUserAccounts creates all four accounts for a fresh user', function () {
    $user = User::factory()->create();
    $provisioner = new WalletAccountProvisioner;

    $accounts = $provisioner->provisionUserAccounts($user);

    expect($accounts)->toBeInstanceOf(UserWalletAccounts::class);
    expect(WalletAccount::where('user_id', $user->id)->count())->toBe(4);
    expect($accounts->earningAvailable->account_type)->toBe(WalletAccountType::EarningAvailable);
    expect($accounts->earningHeld->account_type)->toBe(WalletAccountType::EarningHeld);
    expect($accounts->advertisingAvailable->account_type)->toBe(WalletAccountType::AdvertisingAvailable);
    expect($accounts->advertisingReserved->account_type)->toBe(WalletAccountType::AdvertisingReserved);
});

test('repeated calls return identity-equal rows', function () {
    $user = User::factory()->create();
    $provisioner = new WalletAccountProvisioner;

    $first = $provisioner->provisionUserAccounts($user);
    $second = $provisioner->provisionUserAccounts($user);

    expect($second->earningAvailable->id)->toBe($first->earningAvailable->id);
    expect($second->earningHeld->id)->toBe($first->earningHeld->id);
    expect($second->advertisingAvailable->id)->toBe($first->advertisingAvailable->id);
    expect($second->advertisingReserved->id)->toBe($first->advertisingReserved->id);
    expect(WalletAccount::where('user_id', $user->id)->count())->toBe(4);
});

test('two different users receive two distinct, non-overlapping sets of accounts', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $provisioner = new WalletAccountProvisioner;

    $accountsA = $provisioner->provisionUserAccounts($userA);
    $accountsB = $provisioner->provisionUserAccounts($userB);

    expect($accountsA->earningAvailable->id)->not->toBe($accountsB->earningAvailable->id);
    expect(WalletAccount::where('user_id', $userA->id)->count())->toBe(4);
    expect(WalletAccount::where('user_id', $userB->id)->count())->toBe(4);
});

test('platformFeeAccount is a genuine singleton across repeated calls', function () {
    $provisioner = new WalletAccountProvisioner;

    $first = $provisioner->platformFeeAccount();
    $second = $provisioner->platformFeeAccount();

    expect($second->id)->toBe($first->id);
    expect(WalletAccount::where('scope_type', WalletAccountScopeType::Platform->value)->count())->toBe(1);
});

test('platformSuspenseAccount is a genuine singleton across repeated calls', function () {
    $provisioner = new WalletAccountProvisioner;

    $first = $provisioner->platformSuspenseAccount();
    $second = $provisioner->platformSuspenseAccount();

    expect($second->id)->toBe($first->id);
    expect($first->account_type)->toBe(WalletAccountType::PlatformSuspense);
    expect($first->scope_type)->toBe(WalletAccountScopeType::Platform);
    expect(WalletAccount::where('account_type', WalletAccountType::PlatformSuspense->value)->count())->toBe(1);
});

test('platformFeeAccount and platformSuspenseAccount share the same platform scope key but remain distinct rows', function () {
    $provisioner = new WalletAccountProvisioner;

    $fee = $provisioner->platformFeeAccount();
    $suspense = $provisioner->platformSuspenseAccount();

    expect($fee->id)->not->toBe($suspense->id);
    expect($fee->scope_key)->toBe($suspense->scope_key);
    expect(WalletAccount::where('scope_type', WalletAccountScopeType::Platform->value)->count())->toBe(2);
});

test('providerClearingAccount normalizes case and whitespace to the same account', function () {
    $provisioner = new WalletAccountProvisioner;

    $a = $provisioner->providerClearingAccount('BybitPay');
    $b = $provisioner->providerClearingAccount('bybitpay');
    $c = $provisioner->providerClearingAccount('  bybitpay  ');

    expect($b->id)->toBe($a->id);
    expect($c->id)->toBe($a->id);
    expect($a->scope_key)->toBe('bybitpay');
    expect(WalletAccount::where('scope_type', WalletAccountScopeType::Provider->value)->count())->toBe(1);
});

test('provider identifier non-string values are rejected', function (mixed $identifier) {
    $provisioner = new WalletAccountProvisioner;

    expect(fn () => $provisioner->providerClearingAccount($identifier))
        ->toThrow(InvalidWalletAccountScopeException::class);
})->with([
    'integer' => [123],
    'boolean' => [true],
    'float' => [1.5],
    'array' => [['bybit']],
    'null' => [null],
]);

test('provider identifier length boundaries are enforced at exactly 100 and 101', function () {
    $provisioner = new WalletAccountProvisioner;

    $exactly100 = str_repeat('a', 100);
    $exactly101 = str_repeat('a', 101);

    $account = $provisioner->providerClearingAccount($exactly100);
    expect($account->scope_key)->toBe($exactly100);

    expect(fn () => $provisioner->providerClearingAccount($exactly101))
        ->toThrow(InvalidWalletAccountScopeException::class);
});

test('a malformed provider identifier charset is rejected', function (string $identifier) {
    $provisioner = new WalletAccountProvisioner;

    expect(fn () => $provisioner->providerClearingAccount($identifier))
        ->toThrow(InvalidWalletAccountScopeException::class);
})->with([
    'empty string' => [''],
    'whitespace only' => ['   '],
    'contains space' => ['by bit'],
    'contains dot' => ['bybit.pay'],
    'contains slash' => ['bybit/pay'],
    'non-ascii' => ['bybít'],
]);

test('an unsaved new User cannot provision wallet accounts', function () {
    $provisioner = new WalletAccountProvisioner;
    $unsaved = new User;

    expect(fn () => $provisioner->provisionUserAccounts($unsaved))
        ->toThrow(InvalidWalletAccountScopeException::class);

    expect(WalletAccount::count())->toBe(0);
});

test('a stale user (exists=true in memory, row actually gone) leaves no partial accounts on FK failure', function () {
    $user = User::factory()->create();
    $staleUser = User::find($user->id);
    DB::table('users')->where('id', $user->id)->delete();

    $provisioner = new WalletAccountProvisioner;

    expect($staleUser->exists)->toBeTrue();
    expect(fn () => $provisioner->provisionUserAccounts($staleUser))->toThrow(QueryException::class);
    expect(WalletAccount::where('user_id', $user->id)->count())->toBe(0);
});

test('pre-existing valid partial state is reused, not recreated', function () {
    $user = User::factory()->create();
    $provisioner = new WalletAccountProvisioner;

    $preExisting = new WalletAccount;
    $preExisting->scope_type = WalletAccountScopeType::User;
    $preExisting->scope_key = (string) $user->id;
    $preExisting->user_id = $user->id;
    $preExisting->account_type = WalletAccountType::EarningHeld;
    $preExisting->currency_code = Currency::USD;
    $preExisting->currency_scale = Currency::USD->scale();
    $preExisting->save();

    $accounts = $provisioner->provisionUserAccounts($user);

    expect($accounts->earningHeld->id)->toBe($preExisting->id);
    expect(WalletAccount::where('user_id', $user->id)->count())->toBe(4);
});

test('pre-existing invalid partial state (wrong scope key, same user_id) is detected before any insert', function () {
    $user = User::factory()->create();
    $provisioner = new WalletAccountProvisioner;

    $malformed = new WalletAccount;
    $malformed->scope_type = WalletAccountScopeType::User;
    $malformed->scope_key = 'some-other-key';
    $malformed->user_id = $user->id;
    $malformed->account_type = WalletAccountType::EarningHeld;
    $malformed->currency_code = Currency::USD;
    $malformed->currency_scale = Currency::USD->scale();
    $malformed->save();

    expect(fn () => $provisioner->provisionUserAccounts($user))
        ->toThrow(WalletAccountInvariantException::class);

    // Zero accounts under the canonical user scope were created - the
    // malformed row was detected before any insert was attempted.
    expect(WalletAccount::where('user_id', $user->id)->count())->toBe(1);
    expect(WalletAccount::query()
        ->where('scope_type', WalletAccountScopeType::User->value)
        ->where('scope_key', (string) $user->id)
        ->count())->toBe(0);
});

test('pre-existing invalid partial state (canonical scope/key, different user_id) is detected before any insert', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $provisioner = new WalletAccountProvisioner;

    $malformed = new WalletAccount;
    $malformed->scope_type = WalletAccountScopeType::User;
    $malformed->scope_key = (string) $userA->id;
    $malformed->user_id = $userB->id;
    $malformed->account_type = WalletAccountType::EarningHeld;
    $malformed->currency_code = Currency::USD;
    $malformed->currency_scale = Currency::USD->scale();
    $malformed->save();

    expect(fn () => $provisioner->provisionUserAccounts($userA))
        ->toThrow(WalletAccountInvariantException::class);

    expect(WalletAccount::where('user_id', $userA->id)->count())->toBe(0);
});

test('a wrong currency scale on a pre-existing account is detected before any insert', function () {
    $user = User::factory()->create();
    $provisioner = new WalletAccountProvisioner;

    $malformed = new WalletAccount;
    $malformed->scope_type = WalletAccountScopeType::User;
    $malformed->scope_key = (string) $user->id;
    $malformed->user_id = $user->id;
    $malformed->account_type = WalletAccountType::EarningHeld;
    $malformed->currency_code = Currency::USD;
    $malformed->currency_scale = 8;
    $malformed->save();

    expect(fn () => $provisioner->provisionUserAccounts($user))
        ->toThrow(WalletAccountInvariantException::class);
});

// The provisioner's preflight also rejects a duplicate account_type
// within one user's candidate set (isset($existingByType[...])). A
// literal fixture for that exact branch can't be constructed here: the
// composite unique index (scope_type, scope_key, account_type,
// currency_code) already makes two such rows impossible to insert in the
// first place, so the branch is defensive belt-and-suspenders coverage
// for state this schema cannot currently produce, not a reachable case.

test('a malformed account type for user scope is detected before any insert', function () {
    $user = User::factory()->create();
    $provisioner = new WalletAccountProvisioner;

    $malformed = new WalletAccount;
    $malformed->scope_type = WalletAccountScopeType::User;
    $malformed->scope_key = (string) $user->id;
    $malformed->user_id = $user->id;
    $malformed->account_type = WalletAccountType::PlatformFee;
    $malformed->currency_code = Currency::USD;
    $malformed->currency_scale = Currency::USD->scale();
    $malformed->save();

    expect(fn () => $provisioner->provisionUserAccounts($user))
        ->toThrow(WalletAccountInvariantException::class);
});

test('unique violation with a missing canonical identity row rethrows the original exception unchanged', function () {
    $provisioner = new WalletAccountProvisioner;

    // Take a real, unrelated primary key so the forced insert below hits
    // a genuine PRIMARY KEY collision rather than the composite business
    // identity constraint.
    $unrelated = $provisioner->providerClearingAccount('unrelated-provider');

    expect(WalletAccount::query()
        ->where('scope_type', WalletAccountScopeType::Platform->value)
        ->where('scope_key', 'ellipzo')
        ->where('account_type', WalletAccountType::PlatformFee->value)
        ->exists())->toBeFalse();

    $this->withIsolatedCreatingListener(
        WalletAccount::class,
        function (WalletAccount $model) use ($unrelated): void {
            if ($model->account_type === WalletAccountType::PlatformFee) {
                $model->id = $unrelated->id;
            }
        },
        function () use ($provisioner): void {
            $caught = null;
            try {
                $provisioner->platformFeeAccount();
            } catch (Throwable $e) {
                $caught = $e;
            }

            expect($caught)->toBeInstanceOf(UniqueConstraintViolationException::class);
        },
    );

    expect(WalletAccount::query()
        ->where('scope_type', WalletAccountScopeType::Platform->value)
        ->where('scope_key', 'ellipzo')
        ->where('account_type', WalletAccountType::PlatformFee->value)
        ->exists())->toBeFalse();
});

test('a forced collision on the last of four accounts rolls back every newly-inserted row from that call', function () {
    $user = User::factory()->create();
    $provisioner = new WalletAccountProvisioner;

    // A real, unrelated primary key belonging to a different scope
    // entirely, so its existence has no bearing on this user's accounts.
    $unrelated = $provisioner->providerClearingAccount('another-unrelated-provider');

    $this->withIsolatedCreatingListener(
        WalletAccount::class,
        function (WalletAccount $model) use ($unrelated): void {
            if ($model->account_type === WalletAccountType::AdvertisingReserved
                && $model->scope_type === WalletAccountScopeType::User) {
                $model->id = $unrelated->id;
            }
        },
        function () use ($provisioner, $user): void {
            $caught = null;
            try {
                $provisioner->provisionUserAccounts($user);
            } catch (Throwable $e) {
                $caught = $e;
            }

            expect($caught)->toBeInstanceOf(UniqueConstraintViolationException::class);
        },
    );

    expect(WalletAccount::where('user_id', $user->id)->count())->toBe(0);
});

test('provisionUserAccountsWithinTransaction creates all four accounts when called inside an active transaction', function () {
    $user = User::factory()->create();
    $provisioner = new WalletAccountProvisioner;

    $accounts = DB::transaction(fn () => $provisioner->provisionUserAccountsWithinTransaction($user));

    expect($accounts)->toBeInstanceOf(UserWalletAccounts::class);
    expect(WalletAccount::where('user_id', $user->id)->count())->toBe(4);
});

test('provisionUserAccountsWithinTransaction and provisionUserAccounts resolve the exact same rows for an already-provisioned user', function () {
    $user = User::factory()->create();
    $provisioner = new WalletAccountProvisioner;

    $first = $provisioner->provisionUserAccounts($user);
    $second = DB::transaction(fn () => $provisioner->provisionUserAccountsWithinTransaction($user));

    expect($second->earningAvailable->id)->toBe($first->earningAvailable->id);
    expect($second->earningHeld->id)->toBe($first->earningHeld->id);
    expect($second->advertisingAvailable->id)->toBe($first->advertisingAvailable->id);
    expect($second->advertisingReserved->id)->toBe($first->advertisingReserved->id);
    expect(WalletAccount::where('user_id', $user->id)->count())->toBe(4);
});

test('WalletAccountProvisioner never uses create() or forceCreate() to construct a WalletAccount', function () {
    $source = file_get_contents(app_path('Domain/Wallet/Services/WalletAccountProvisioner.php'));

    expect($source)->not->toMatch('/WalletAccount::create\s*\(/');
    expect($source)->not->toMatch('/WalletAccount::forceCreate\s*\(/');
    expect($source)->not->toMatch('/::unguard\s*\(/');
});
