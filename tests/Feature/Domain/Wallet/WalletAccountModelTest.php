<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Models\WalletAccount;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Carbon;

test('casts round-trip through their enum/scalar types', function () {
    $id = $this->insertRawWalletAccount([
        'scope_type' => 'platform', 'scope_key' => 'ellipzo', 'user_id' => null,
        'account_type' => 'platform_fee',
    ]);

    $account = WalletAccount::findOrFail($id);

    expect($account->scope_type)->toBe(WalletAccountScopeType::Platform);
    expect($account->account_type)->toBe(WalletAccountType::PlatformFee);
    expect($account->currency_code)->toBe(Currency::USD);
    expect($account->currency_scale)->toBe(6);
    expect($account->created_at)->toBeInstanceOf(Carbon::class);
});

test('an invalid raw scope_type throws on attribute access, never silently defaulting', function () {
    $id = $this->insertRawWalletAccount(['scope_type' => 'not-a-real-scope']);

    $account = WalletAccount::findOrFail($id);

    expect(fn () => $account->scope_type)->toThrow(ValueError::class);
});

test('an invalid raw account_type throws on attribute access', function () {
    $id = $this->insertRawWalletAccount(['account_type' => 'not-a-real-type']);

    $account = WalletAccount::findOrFail($id);

    expect(fn () => $account->account_type)->toThrow(ValueError::class);
});

test('an invalid raw currency_code throws on attribute access', function () {
    $id = $this->insertRawWalletAccount(['currency_code' => 'XYZ']);

    $account = WalletAccount::findOrFail($id);

    expect(fn () => $account->currency_code)->toThrow(ValueError::class);
});

test('none of a wallet account\'s columns are mass assignable', function () {
    expect(fn () => WalletAccount::create([
        'scope_type' => 'platform', 'scope_key' => 'ellipzo', 'account_type' => 'platform_fee',
        'currency_code' => 'USD', 'currency_scale' => 6,
    ]))->toThrow(MassAssignmentException::class);
});

test('a wallet account cannot be updated, even by direct property assignment', function () {
    $id = $this->insertRawWalletAccount();
    $account = WalletAccount::findOrFail($id);

    $account->currency_scale = 8;

    expect(fn () => $account->save())->toThrow(LogicException::class);
});

test('a wallet account cannot be deleted through application code', function () {
    $id = $this->insertRawWalletAccount();
    $account = WalletAccount::findOrFail($id);

    expect(fn () => $account->delete())->toThrow(LogicException::class);
});

test('WalletAccount does not expose factory behavior', function () {
    expect(method_exists(WalletAccount::class, 'factory'))->toBeFalse();
});
