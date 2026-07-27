<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Domain\Wallet\Concerns\InsertsRawLedgerRowsForTesting;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(InsertsRawLedgerRowsForTesting::class)->in('Feature/Domain/Wallet');
