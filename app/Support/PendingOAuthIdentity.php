<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * A brand-new OAuth identity is a *candidate* only, held in the
 * server-side session until the user explicitly completes onboarding -
 * never persisted to the database until then. Deliberately carries only
 * what the completion screen and account-creation transaction need:
 * provider, provider user id, verified email, display name, and a
 * server-generated expiration. Never the access or refresh token.
 */
final readonly class PendingOAuthIdentity
{
    private const SESSION_KEY = 'oauth_pending';

    private const TTL_MINUTES = 10;

    public function __construct(
        public string $provider,
        public string $providerUserId,
        public string $email,
        public string $name,
    ) {}

    public static function store(string $provider, string $providerUserId, string $email, string $name): void
    {
        session()->put(self::SESSION_KEY, [
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'email' => $email,
            'name' => $name,
            'expires_at' => Carbon::now('UTC')->addMinutes(self::TTL_MINUTES)->timestamp,
        ]);
    }

    /**
     * The current pending identity, or null if none exists or it has
     * expired. An expired entry is cleared as a side effect of being
     * read, so it can never be completed after its window passes.
     */
    public static function current(): ?self
    {
        $data = session(self::SESSION_KEY);

        if (! is_array($data) || ! isset($data['expires_at'], $data['provider'], $data['provider_user_id'], $data['email'], $data['name'])) {
            return null;
        }

        if (Carbon::now('UTC')->timestamp > $data['expires_at']) {
            self::forget();

            return null;
        }

        return new self(
            provider: $data['provider'],
            providerUserId: $data['provider_user_id'],
            email: $data['email'],
            name: $data['name'],
        );
    }

    /**
     * Called once completion succeeds, so the same pending identity can
     * never be replayed into a second account.
     */
    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
