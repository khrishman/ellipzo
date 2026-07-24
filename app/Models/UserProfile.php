<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['username', 'date_of_birth', 'country_code', 'locale', 'timezone'])]
#[Hidden(['username_normalized'])]
class UserProfile extends Model
{
    /** @use HasFactory<UserProfileFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The single canonical derivation of `username_normalized` from a raw
     * username value - used by the mutator below, by the update-request
     * validation's uniqueness check, and by the controller's
     * race-condition recovery, so all three can never drift out of sync.
     */
    public static function normalizeUsername(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : Str::lower($trimmed);
    }

    /**
     * Assigning `username` always keeps `username_normalized` in sync, for
     * every write path (HTTP requests, factories, seeders, tinker) - not
     * just the settings form. `username_normalized` itself is never
     * fillable, so it can only ever be derived here, never set directly
     * from request input.
     *
     * Returns both attributes as an array rather than mutating
     * $this->attributes['username_normalized'] as a side effect inside the
     * closure: Eloquent's setter path evaluates `$this->attributes` for its
     * own array_merge() before the closure's side effects would apply, so
     * an in-closure mutation of a different attribute is silently
     * discarded. Returning an array is the mechanism Eloquent actually
     * supports for one mutator to set multiple attributes.
     */
    protected function username(): Attribute
    {
        return Attribute::make(
            set: function (?string $value) {
                $trimmed = $value !== null ? trim($value) : null;
                $trimmed = $trimmed === '' ? null : $trimmed;

                return [
                    'username' => $trimmed,
                    'username_normalized' => static::normalizeUsername($value),
                ];
            },
        );
    }
}
