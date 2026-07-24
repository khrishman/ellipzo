<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OAuthIdentity;
use App\Models\User;
use App\Support\PendingOAuthIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Redirect to Google using Socialite's default, session-bound flow -
     * a CSRF-protected "state" parameter round-trips through the
     * session. Deliberately not ->stateless().
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle Google's callback.
     *
     * An already-linked identity logs straight in. A brand new identity
     * is never turned into a user here - it becomes a short-lived
     * pending candidate (see PendingOAuthIdentity) and the browser is
     * sent to the completion screen, which is the only place a new
     * account can actually be created. An email that matches an
     * existing account is deliberately not auto-linked.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            /** @var SocialiteUser $googleUser */
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Covers a cancelled consent screen, a mismatched/expired
            // state, and any provider-side failure alike. Only the
            // exception class is logged - never the request, a token, or
            // any raw provider payload.
            Log::warning('Google sign-in failed.', ['exception' => $e::class]);

            return redirect()->route('login')->with(
                'status',
                'Google sign-in was cancelled or could not be completed. Please try again.',
            );
        }

        $providerUserId = trim((string) $googleUser->getId());
        $email = is_string($googleUser->getEmail()) ? mb_strtolower(trim($googleUser->getEmail())) : '';
        $emailVerified = filter_var($googleUser->getRaw()['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $identity = OAuthIdentity::where('provider', 'google')
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($identity !== null) {
            Auth::login($identity->user);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard', absolute: false));
        }

        if ($providerUserId === '' || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $emailVerified) {
            return redirect()->route('login')->with('status', 'Google sign-in could not be completed. Please try again.');
        }

        if (User::where('email', $email)->exists()) {
            return redirect()->route('login')->with(
                'status',
                'An account with this email already exists. Sign in with your password, then connect Google from your account settings.',
            );
        }

        $name = $googleUser->getName();
        $name = is_string($name) && trim($name) !== '' ? trim($name) : $email;

        PendingOAuthIdentity::store('google', $providerUserId, $email, $name);

        return redirect()->route('auth.google.complete');
    }
}
