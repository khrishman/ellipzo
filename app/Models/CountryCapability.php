<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'country_code',
    'registration_enabled',
    'earning_enabled',
    'advertising_enabled',
    'deposits_enabled',
    'withdrawals_enabled',
    'minimum_age',
    'notes',
])]
class CountryCapability extends Model
{
    protected $primaryKey = 'country_code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'registration_enabled' => 'boolean',
            'earning_enabled' => 'boolean',
            'advertising_enabled' => 'boolean',
            'deposits_enabled' => 'boolean',
            'withdrawals_enabled' => 'boolean',
            'minimum_age' => 'integer',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }
}
