<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Concerns\AssertsConcurrencyEnvironment;
use Tests\Concurrency\Support\ConcurrencyDatabaseIdentityGuard as Guard;
use Tests\TestCase;

uses(TestCase::class, AssertsConcurrencyEnvironment::class);

beforeEach(function (): void {
    $this->ensureConcurrencyEnvironmentReady('identity-guard-integration');
});

afterEach(function (): void {
    $this->resetConcurrencyDefaultConnection();
});

test('the real, correctly-configured mysql_concurrency connection passes the live guard', function (): void {
    // ensureConcurrencyEnvironmentReady() above already calls
    // verifyRuntimeIdentity() and skips this test if it fails - reaching
    // this line at all is itself half the proof. Re-run explicitly here
    // for a direct, visible assertion rather than relying only on the
    // absence of a skip.
    $result = Guard::verifyRuntimeIdentity(app());

    expect($result->ok)->toBeTrue();
    expect($result->reason)->toBe(Guard::REASON_OK);
    expect(DB::connection('mysql_concurrency')->getDatabaseName())->toBe('ellipzo_concurrency_test');
});
