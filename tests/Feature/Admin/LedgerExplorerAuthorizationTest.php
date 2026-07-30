<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

/**
 * A standalone-function-safe fixture builder - deliberately does not rely
 * on $this (the trait helpers bound to the Pest test case), since a
 * top-level function called from a test closure still executes outside
 * that object context.
 *
 * @return array{0: User, 1: string} the actor and a real, existing transaction ID
 */
function makeStaffWithRealTransaction(?string $role): array
{
    $provisioner = new WalletAccountProvisioner;
    $target = User::factory()->create();
    $accounts = $provisioner->provisionUserAccounts($target);
    $clearing = $provisioner->providerClearingAccount('ledger-explorer-auth-clearing-'.strtolower((string) Str::ulid()));

    $transaction = (new LedgerPostingEngine)->post(new PostLedgerTransactionCommand(
        LedgerTransactionType::DepositCredit,
        'deposit_credit:ledger-explorer-auth-'.strtolower((string) Str::ulid()),
        (string) Str::uuid(),
        'Test posting',
        null,
        null,
        null,
        [
            new PostLedgerEntryCommand($clearing->id, LedgerEntryType::Debit, Money::fromAtomic(100_000, Currency::USD)),
            new PostLedgerEntryCommand($accounts->earningAvailable->id, LedgerEntryType::Credit, Money::fromAtomic(100_000, Currency::USD)),
        ],
    ))->transaction;

    $actor = User::factory()->create();
    if ($role !== null) {
        $actor->assignRole($role);
    }

    return [$actor, $transaction->id];
}

test('a guest is redirected to login for both ledger routes', function () {
    [, $transactionId] = makeStaffWithRealTransaction(null);

    $this->get('/admin/ledger')->assertRedirect(route('login', absolute: false));
    $this->get("/admin/ledger/{$transactionId}")->assertRedirect(route('login', absolute: false));
});

test('an unverified staff member with ledger.view is redirected to email verification', function () {
    $user = User::factory()->unverified()->create();
    $user->assignRole('administrator');

    $response = $this->actingAs($user)->get('/admin/ledger');

    $response->assertRedirect(route('verification.notice', absolute: false));
});

test('a suspended staff member is redirected to the restricted page even with ledger.view', function () {
    $user = User::factory()->suspended()->create();
    $user->assignRole('administrator');

    $this->actingAs($user)->get('/admin/ledger')->assertRedirect(route('account.restricted', absolute: false));
});

test('a closed staff member is redirected to the restricted page even with ledger.view', function () {
    $user = User::factory()->closed()->create();
    $user->assignRole('administrator');

    $this->actingAs($user)->get('/admin/ledger')->assertRedirect(route('account.restricted', absolute: false));
});

test('a normal user without any staff role receives 403 for both routes', function () {
    [$user, $transactionId] = makeStaffWithRealTransaction(null);

    $this->actingAs($user)->get('/admin/ledger')->assertForbidden();
    $this->actingAs($user)->get("/admin/ledger/{$transactionId}")->assertForbidden();
});

test('a moderator receives 403 for both routes', function () {
    [$user, $transactionId] = makeStaffWithRealTransaction('moderator');

    $this->actingAs($user)->get('/admin/ledger')->assertForbidden();
    $this->actingAs($user)->get("/admin/ledger/{$transactionId}")->assertForbidden();
});

test('a support agent receives 403 for both routes', function () {
    [$user, $transactionId] = makeStaffWithRealTransaction('support-agent');

    $this->actingAs($user)->get('/admin/ledger')->assertForbidden();
    $this->actingAs($user)->get("/admin/ledger/{$transactionId}")->assertForbidden();
});

test('a finance operator can reach both routes', function () {
    [$user, $transactionId] = makeStaffWithRealTransaction('finance-operator');

    $this->actingAs($user)->get('/admin/ledger')->assertOk();
    $this->actingAs($user)->get("/admin/ledger/{$transactionId}")->assertOk();
});

test('an administrator can reach both routes', function () {
    [$user, $transactionId] = makeStaffWithRealTransaction('administrator');

    $this->actingAs($user)->get('/admin/ledger')->assertOk();
    $this->actingAs($user)->get("/admin/ledger/{$transactionId}")->assertOk();
});

test('an unauthorized user gets an identical 403 for an existing and a nonexistent transaction ID', function () {
    [$user, $existingId] = makeStaffWithRealTransaction(null);
    $nonexistentId = strtolower((string) Str::ulid());

    $existingResponse = $this->actingAs($user)->get("/admin/ledger/{$existingId}");
    $nonexistentResponse = $this->actingAs($user)->get("/admin/ledger/{$nonexistentId}");

    $existingResponse->assertForbidden();
    $nonexistentResponse->assertForbidden();
    // Identical status proves the response gives an unauthorized viewer no
    // way to distinguish "this ID exists" from "this ID does not exist" -
    // permission middleware rejects before route-model binding ever runs.
    expect($existingResponse->getStatusCode())->toBe($nonexistentResponse->getStatusCode());
});

test('an authorized staff member gets 404 for a nonexistent transaction ID', function () {
    [$user] = makeStaffWithRealTransaction('administrator');
    $nonexistentId = strtolower((string) Str::ulid());

    $this->actingAs($user)->get("/admin/ledger/{$nonexistentId}")->assertNotFound();
});

test('an authorized staff member successfully renders a known transaction', function () {
    [$user, $transactionId] = makeStaffWithRealTransaction('administrator');

    $response = $this->actingAs($user)->get("/admin/ledger/{$transactionId}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('admin/ledger/show')->where('transaction.id', $transactionId));
});

test('permissions do not leak between staff accounts on the ledger routes', function () {
    [$moderator, $transactionId] = makeStaffWithRealTransaction('moderator');
    [$supportAgent] = makeStaffWithRealTransaction('support-agent');

    expect($moderator->can('ledger.view'))->toBeFalse();
    expect($supportAgent->can('ledger.view'))->toBeFalse();

    $this->actingAs($moderator)->get("/admin/ledger/{$transactionId}")->assertForbidden();
    $this->actingAs($supportAgent)->get("/admin/ledger/{$transactionId}")->assertForbidden();
});

test('a POST to either ledger URL is rejected - no mutation route exists', function () {
    [$user, $transactionId] = makeStaffWithRealTransaction('administrator');

    $this->actingAs($user)->post('/admin/ledger')->assertStatus(405);
    $this->actingAs($user)->post("/admin/ledger/{$transactionId}")->assertStatus(405);
});
