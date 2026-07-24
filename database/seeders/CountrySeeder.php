<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;
use App\Models\CountryCapability;
use Illuminate\Database\Seeder;
use Symfony\Component\Intl\Countries;

/**
 * Reference data source: symfony/intl's Countries::getNames('en'), backed
 * by ICU/CLDR data bundled with the package - no hard-coded array, no
 * external download at runtime. Symfony's optional user-assigned code
 * range (e.g. "XK") is deliberately left disabled: this seeder never
 * calls Countries::withUserAssigned() and never sets
 * SYMFONY_INTL_WITH_USER_ASSIGNED, so getNames() returns only officially
 * assigned ISO 3166-1 alpha-2 codes.
 *
 * Idempotent and safe to rerun, and identical for every country
 * including Nepal - there is no country-specific branch anywhere below:
 *   - countries: name kept in sync with the installed symfony/intl data
 *     (pure reference data, always safe to resync).
 *   - country_capabilities: only a MISSING row is ever created. An
 *     existing row - and any operator edits to it - is never touched,
 *     so rerunning this seeder can never reset a capability flag that
 *     was deliberately changed after the first run.
 */
class CountrySeeder extends Seeder
{
    public function run(): void
    {
        foreach (Countries::getNames('en') as $code => $name) {
            $code = mb_strtoupper($code);

            Country::updateOrCreate(['code' => $code], ['name' => $name]);

            CountryCapability::firstOrCreate(
                ['country_code' => $code],
                [
                    'registration_enabled' => false,
                    'earning_enabled' => false,
                    'advertising_enabled' => false,
                    'deposits_enabled' => false,
                    'withdrawals_enabled' => false,
                    'minimum_age' => 18,
                ],
            );
        }
    }
}
