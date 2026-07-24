<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately minimal: provider + provider_user_id only. No access or
 * refresh token, no raw provider payload, no avatar, no metadata beyond
 * what's needed to recognize a returning identity - see the migration
 * for the reasoning.
 */
#[Fillable(['provider', 'provider_user_id'])]
class OAuthIdentity extends Model
{
    // Eloquent's snake_case pluralization guesses "o_auth_identities"
    // (a capital-letter boundary between "O" and "Auth") - the real
    // table is "oauth_identities".
    protected $table = 'oauth_identities';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
