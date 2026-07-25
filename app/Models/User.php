<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Raw (pre-cast) attribute defaults applied to every new instance
     * before the constructor's own $attributes are filled in - this is
     * what guarantees account_status is AccountStatus::Active in-memory
     * immediately after any creation path (new User, create(),
     * forceCreate(), the factory), with no refresh()/fresh() required.
     * The value must be the raw string the enum cast expects, not the
     * enum instance itself, since casting only happens on read.
     *
     * Hydrating an existing row (User::find(), query results, ...)
     * fully replaces this array via setRawAttributes(), so a real
     * stored status can never be masked by this default.
     *
     * The users.account_status column also has its own database-level
     * default('active') as defense in depth for any insert that
     * bypasses Eloquent entirely.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'account_status' => 'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // account_status is deliberately absent from $fillable above -
            // App\Support\AccountStatusTransitioner is the only write path.
            'account_status' => AccountStatus::class,
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class);
    }

    public function oauthIdentities(): HasMany
    {
        return $this->hasMany(OAuthIdentity::class);
    }
}
