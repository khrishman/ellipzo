<?php

use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\OAuthIdentity;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Support\Facades\Config;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    Config::set('legal.documents.terms', ['title' => 'Terms of Service', 'version' => 'test-terms-v1', 'published' => true]);
    Config::set('legal.documents.privacy', ['title' => 'Privacy Policy', 'version' => 'test-privacy-v1', 'published' => true]);
});

function seedPendingGoogleIdentityForProvisioningTest(array $overrides = []): void
{
    session()->put('oauth_pending', array_merge([
        'provider' => 'google',
        'provider_user_id' => 'google-123',
        'email' => 'ada@example.com',
        'name' => 'Ada Lovelace',
        'expires_at' => now('UTC')->addMinutes(10)->timestamp,
    ], $overrides));
}

function assertExactlyFourCorrectUserWalletAccounts(int $userId): void
{
    $accounts = WalletAccount::where('user_id', $userId)->get();

    expect($accounts)->toHaveCount(4);

    $types = $accounts->pluck('account_type')->map(fn (WalletAccountType $t) => $t->value)->sort()->values()->all();
    expect($types)->toBe([
        WalletAccountType::AdvertisingAvailable->value,
        WalletAccountType::AdvertisingReserved->value,
        WalletAccountType::EarningAvailable->value,
        WalletAccountType::EarningHeld->value,
    ]);

    foreach ($accounts as $account) {
        expect($account->scope_type)->toBe(WalletAccountScopeType::User);
        expect($account->scope_key)->toBe((string) $userId);
    }
}

test('password registration provisions exactly the four user-scoped wallet accounts', function () {
    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
        'terms' => true,
    ]);

    $user = User::where('email', 'ada@example.com')->firstOrFail();

    assertExactlyFourCorrectUserWalletAccounts($user->id);
    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});

test('Google new-user completion provisions exactly the four user-scoped wallet accounts', function () {
    seedPendingGoogleIdentityForProvisioningTest();

    $this->post('/auth/google/complete', ['terms' => true]);

    $user = User::where('email', 'ada@example.com')->firstOrFail();

    assertExactlyFourCorrectUserWalletAccounts($user->id);
    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});

test('a forced provisioning failure during registration rolls back the user and consent records', function () {
    $provisioner = new WalletAccountProvisioner;
    // A real, unrelated wallet account whose ID we force one of the four
    // new user-scoped accounts to collide with - the exact technique
    // WalletAccountProvisionerTest.php already establishes for this class.
    $unrelated = $provisioner->providerClearingAccount('unrelated-registration-provider');

    $this->withIsolatedCreatingListener(
        WalletAccount::class,
        function (WalletAccount $model) use ($unrelated): void {
            if ($model->account_type === WalletAccountType::AdvertisingReserved
                && $model->scope_type === WalletAccountScopeType::User) {
                $model->id = $unrelated->id;
            }
        },
        function (): void {
            $this->post('/register', [
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'password' => 'Password!123',
                'password_confirmation' => 'Password!123',
                'terms' => true,
            ]);
        },
    );

    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
    expect(UserConsent::count())->toBe(0);
    expect(WalletAccount::where('scope_type', WalletAccountScopeType::User->value)->count())->toBe(0);
});

test('a forced provisioning failure during Google completion rolls back the user, OAuth identity, and consent records', function () {
    $provisioner = new WalletAccountProvisioner;
    $unrelated = $provisioner->providerClearingAccount('unrelated-google-provider');

    seedPendingGoogleIdentityForProvisioningTest();

    $this->withIsolatedCreatingListener(
        WalletAccount::class,
        function (WalletAccount $model) use ($unrelated): void {
            if ($model->account_type === WalletAccountType::AdvertisingReserved
                && $model->scope_type === WalletAccountScopeType::User) {
                $model->id = $unrelated->id;
            }
        },
        function (): void {
            $this->post('/auth/google/complete', ['terms' => true]);
        },
    );

    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
    expect(OAuthIdentity::where('provider_user_id', 'google-123')->exists())->toBeFalse();
    expect(UserConsent::count())->toBe(0);
    expect(WalletAccount::where('scope_type', WalletAccountScopeType::User->value)->count())->toBe(0);
});

test('an existing-user Google login does not provision wallet accounts', function () {
    $user = User::factory()->create();
    $user->oauthIdentities()->create(['provider' => 'google', 'provider_user_id' => 'google-existing-123']);

    $socialiteUser = SocialiteUser::fake([
        'id' => 'google-existing-123',
        'name' => $user->name,
        'email' => $user->email,
        'email_verified' => true,
    ]);
    Socialite::shouldReceive('driver->user')->once()->andReturn($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
    expect(WalletAccount::where('user_id', $user->id)->count())->toBe(0);
});
