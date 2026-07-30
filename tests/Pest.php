<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Domain\Wallet\Concerns\BuildsAdministrativeAdjustmentFixtures;
use Tests\Feature\Domain\Wallet\Concerns\BuildsLedgerPostingFixtures;
use Tests\Feature\Domain\Wallet\Concerns\BuildsReversalRequestFixtures;
use Tests\Feature\Domain\Wallet\Concerns\InsertsRawLedgerRowsForTesting;
use Tests\Feature\Domain\Wallet\Concerns\IsolatesModelEventListenersForTesting;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(
    InsertsRawLedgerRowsForTesting::class,
    BuildsLedgerPostingFixtures::class,
    BuildsReversalRequestFixtures::class,
    BuildsAdministrativeAdjustmentFixtures::class,
    IsolatesModelEventListenersForTesting::class,
)->in('Feature/Domain/Wallet');
uses(
    InsertsRawLedgerRowsForTesting::class,
    BuildsLedgerPostingFixtures::class,
    BuildsReversalRequestFixtures::class,
    BuildsAdministrativeAdjustmentFixtures::class,
)->in('Feature/Console');
uses(
    InsertsRawLedgerRowsForTesting::class,
    BuildsLedgerPostingFixtures::class,
    BuildsReversalRequestFixtures::class,
    BuildsAdministrativeAdjustmentFixtures::class,
)->in('Feature/Wallet');
uses(
    InsertsRawLedgerRowsForTesting::class,
    BuildsLedgerPostingFixtures::class,
    BuildsReversalRequestFixtures::class,
    BuildsAdministrativeAdjustmentFixtures::class,
)->in('Feature/Admin');
