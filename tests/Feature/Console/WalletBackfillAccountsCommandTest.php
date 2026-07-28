<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('dry-run writes nothing and reports the correct counts', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    Artisan::call('wallet:backfill-accounts');
    $output = Artisan::output();

    expect(WalletAccount::count())->toBe(0);
    expect($output)->toContain('Mode: dry-run');
    expect($output)->toContain('Inspected: 2');
    expect($output)->toContain('Already complete: 0');
    expect($output)->toContain('Would provision: 2');
    expect($output)->toContain('Failed: 0');
});

test('apply provisions only missing accounts and leaves already-complete users untouched', function () {
    $provisioner = new WalletAccountProvisioner;
    $alreadyProvisioned = User::factory()->create();
    $existingAccounts = $provisioner->provisionUserAccounts($alreadyProvisioned);

    $needsProvisioning = User::factory()->create();

    $exitCode = Artisan::call('wallet:backfill-accounts', ['--apply' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Mode: apply');
    expect($output)->toContain('Inspected: 2');
    expect($output)->toContain('Already complete: 1');
    expect($output)->toContain('Provisioned: 1');
    expect($output)->toContain('Failed: 0');

    expect(WalletAccount::where('user_id', $needsProvisioning->id)->count())->toBe(4);

    // The already-complete user's own four accounts are byte-identical
    // (same IDs) after the run - never touched, let alone recreated.
    $refetched = WalletAccount::where('user_id', $alreadyProvisioned->id)->get()->keyBy(fn (WalletAccount $a) => $a->account_type->value);
    expect($refetched->get(WalletAccountType::EarningAvailable->value)->id)->toBe($existingAccounts->earningAvailable->id);
    expect($refetched->get(WalletAccountType::EarningHeld->value)->id)->toBe($existingAccounts->earningHeld->id);
    expect($refetched->get(WalletAccountType::AdvertisingAvailable->value)->id)->toBe($existingAccounts->advertisingAvailable->id);
    expect($refetched->get(WalletAccountType::AdvertisingReserved->value)->id)->toBe($existingAccounts->advertisingReserved->id);
});

test('re-running apply is idempotent', function () {
    $user = User::factory()->create();

    Artisan::call('wallet:backfill-accounts', ['--apply' => true]);
    $firstRunIds = WalletAccount::where('user_id', $user->id)->pluck('id')->sort()->values()->all();

    Artisan::call('wallet:backfill-accounts', ['--apply' => true]);
    $secondOutput = Artisan::output();
    $secondRunIds = WalletAccount::where('user_id', $user->id)->pluck('id')->sort()->values()->all();

    expect($secondRunIds)->toBe($firstRunIds);
    expect(WalletAccount::where('user_id', $user->id)->count())->toBe(4);
    expect($secondOutput)->toContain('Already complete: 1');
    expect($secondOutput)->toContain('Provisioned: 0');
});

test('a corrupt or conflicting user fails without partial changes and does not affect others', function () {
    $goodUserBefore = User::factory()->create();
    $corruptUser = User::factory()->create();
    $goodUserAfter = User::factory()->create();

    // A malformed pre-existing row for the middle user - same fixture
    // technique already established in WalletAccountProvisionerTest.php.
    $malformed = new WalletAccount;
    $malformed->scope_type = WalletAccountScopeType::User;
    $malformed->scope_key = 'some-other-key';
    $malformed->user_id = $corruptUser->id;
    $malformed->account_type = WalletAccountType::EarningHeld;
    $malformed->currency_code = Currency::USD;
    $malformed->currency_scale = Currency::USD->scale();
    $malformed->save();

    $exitCode = Artisan::call('wallet:backfill-accounts', ['--apply' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('Inspected: 3');
    expect($output)->toContain('Provisioned: 2');
    expect($output)->toContain('Failed: 1');

    // The two valid users, processed before and after the corrupt one,
    // were still fully provisioned - one user's failure does not roll
    // back another user's already-committed transaction.
    expect(WalletAccount::where('user_id', $goodUserBefore->id)->count())->toBe(4);
    expect(WalletAccount::where('user_id', $goodUserAfter->id)->count())->toBe(4);

    // The corrupt user gained nothing beyond the one malformed row already
    // there - no partial repair, no silent overwrite.
    expect(WalletAccount::where('user_id', $corruptUser->id)->count())->toBe(1);
    expect(WalletAccount::query()
        ->where('scope_type', WalletAccountScopeType::User->value)
        ->where('scope_key', (string) $corruptUser->id)
        ->count())->toBe(0);
});

test('dry-run detects a corrupt user identically to apply mode, without writing anything', function () {
    $corruptUser = User::factory()->create();

    $malformed = new WalletAccount;
    $malformed->scope_type = WalletAccountScopeType::User;
    $malformed->scope_key = 'some-other-key';
    $malformed->user_id = $corruptUser->id;
    $malformed->account_type = WalletAccountType::EarningHeld;
    $malformed->currency_code = Currency::USD;
    $malformed->currency_scale = Currency::USD->scale();
    $malformed->save();

    $exitCode = Artisan::call('wallet:backfill-accounts');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('Failed: 1');
    expect(WalletAccount::where('user_id', $corruptUser->id)->count())->toBe(1);
});

test('command output contains no email, name, exception message, SQL, or stack trace', function () {
    $user = User::factory()->create([
        'name' => 'Distinctive Sentinel Name',
        'email' => 'distinctive-sentinel@example.com',
    ]);

    $malformed = new WalletAccount;
    $malformed->scope_type = WalletAccountScopeType::User;
    $malformed->scope_key = 'some-other-key';
    $malformed->user_id = $user->id;
    $malformed->account_type = WalletAccountType::EarningHeld;
    $malformed->currency_code = Currency::USD;
    $malformed->currency_scale = Currency::USD->scale();
    $malformed->save();

    Artisan::call('wallet:backfill-accounts', ['--apply' => true]);
    $output = Artisan::output();

    expect($output)->not->toContain('distinctive-sentinel@example.com');
    expect($output)->not->toContain('Distinctive Sentinel Name');
    expect($output)->not->toContain('WalletAccountInvariantException');
    expect($output)->not->toContain('SQLSTATE');
    expect($output)->not->toContain('#0 ');
    expect($output)->not->toContain(__DIR__);
});

test('dry-run restores the transaction level to its original value after processing several successful users', function () {
    User::factory()->count(3)->create();

    $before = DB::transactionLevel();

    Artisan::call('wallet:backfill-accounts');
    $output = Artisan::output();

    expect(DB::transactionLevel())->toBe($before);
    expect($output)->toContain('Inspected: 3');
    expect($output)->toContain('Would provision: 3');
    expect(WalletAccount::count())->toBe(0);
});

test('dry-run restores the transaction level to its original value after a user fails an invariant check', function () {
    $corruptUser = User::factory()->create();

    $malformed = new WalletAccount;
    $malformed->scope_type = WalletAccountScopeType::User;
    $malformed->scope_key = 'some-other-key';
    $malformed->user_id = $corruptUser->id;
    $malformed->account_type = WalletAccountType::EarningHeld;
    $malformed->currency_code = Currency::USD;
    $malformed->currency_scale = Currency::USD->scale();
    $malformed->save();

    $before = DB::transactionLevel();

    Artisan::call('wallet:backfill-accounts');
    $output = Artisan::output();

    expect(DB::transactionLevel())->toBe($before);
    expect($output)->toContain('Failed: 1');
});

test('dry-run restores the transaction level to its original value across a mixed sequence of complete, missing, and corrupt users, and writes nothing', function () {
    $provisioner = new WalletAccountProvisioner;
    $alreadyComplete = User::factory()->create();
    $provisioner->provisionUserAccounts($alreadyComplete);

    $needsProvisioning = User::factory()->create();

    $corruptUser = User::factory()->create();
    $malformed = new WalletAccount;
    $malformed->scope_type = WalletAccountScopeType::User;
    $malformed->scope_key = 'some-other-key';
    $malformed->user_id = $corruptUser->id;
    $malformed->account_type = WalletAccountType::EarningHeld;
    $malformed->currency_code = Currency::USD;
    $malformed->currency_scale = Currency::USD->scale();
    $malformed->save();

    $trailingUser = User::factory()->create();

    $before = DB::transactionLevel();

    Artisan::call('wallet:backfill-accounts');
    $output = Artisan::output();

    expect(DB::transactionLevel())->toBe($before);
    expect($output)->toContain('Inspected: 4');
    expect($output)->toContain('Already complete: 1');
    expect($output)->toContain('Would provision: 2');
    expect($output)->toContain('Failed: 1');

    // Dry-run never writes, including for the users that were reported as
    // "would provision" - the whole point of the mode.
    expect(WalletAccount::where('user_id', $needsProvisioning->id)->count())->toBe(0);
    expect(WalletAccount::where('user_id', $trailingUser->id)->count())->toBe(0);
    expect(WalletAccount::where('user_id', $corruptUser->id)->count())->toBe(1);
});

test('command output consists only of the five expected aggregate lines, proving no per-user data (including a bare user ID) ever appears', function () {
    $user = User::factory()->create();

    $malformed = new WalletAccount;
    $malformed->scope_type = WalletAccountScopeType::User;
    $malformed->scope_key = 'some-other-key';
    $malformed->user_id = $user->id;
    $malformed->account_type = WalletAccountType::EarningHeld;
    $malformed->currency_code = Currency::USD;
    $malformed->currency_scale = Currency::USD->scale();
    $malformed->save();

    Artisan::call('wallet:backfill-accounts', ['--apply' => true]);
    $output = Artisan::output();

    $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn (string $line) => $line !== ''));

    foreach ($lines as $line) {
        expect($line)->toMatch('/^(Mode: (dry-run|apply)|Inspected: \d+|Already complete: \d+|(Provisioned|Would provision): \d+|Failed: \d+)$/');
    }

    expect($lines)->toHaveCount(5);
});
