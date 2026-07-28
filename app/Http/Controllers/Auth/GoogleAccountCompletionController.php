<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CompleteGoogleAccountRequest;
use App\Models\User;
use App\Models\UserConsent;
use App\Support\PendingOAuthIdentity;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GoogleAccountCompletionController extends Controller
{
    public function __construct(
        private readonly WalletAccountProvisioner $walletAccountProvisioner,
    ) {}

    /**
     * Show the completion/consent screen for a pending Google identity.
     * There is nothing to show without a live, unexpired pending
     * identity in the session - the browser is sent back to log in.
     */
    public function show(): Response|RedirectResponse
    {
        $pending = PendingOAuthIdentity::current();

        if ($pending === null) {
            return redirect()->route('login')->with('status', 'Your Google sign-in has expired. Please try again.');
        }

        return Inertia::render('auth/google-complete', [
            'name' => $pending->name,
            'email' => $pending->email,
            'documentsPublished' => $this->requiredDocumentsPublished(),
        ]);
    }

    /**
     * Create the account. The user row, the OAuth identity, both required
     * consent records, and the four user-scoped wallet accounts are
     * created inside one transaction - either all seven rows exist or
     * none of them do. The Registered event and login only happen after
     * that transaction has actually committed, and the pending identity
     * is forgotten immediately on success so it can never be completed a
     * second time.
     */
    public function store(CompleteGoogleAccountRequest $request): RedirectResponse
    {
        $pending = PendingOAuthIdentity::current();

        if ($pending === null) {
            return redirect()->route('login')->with('status', 'Your Google sign-in has expired. Please try again.');
        }

        $user = DB::transaction(function () use ($pending) {
            // forceCreate, not create: email_verified_at is server-derived
            // (Google's own verified claim, checked in the callback) and
            // deliberately not mass-assignable through the User model's
            // normal $fillable list. No password key at all - the column
            // stays NULL, never a fake or generated value.
            $user = User::forceCreate([
                'name' => $pending->name,
                'email' => $pending->email,
                'email_verified_at' => Carbon::now('UTC'),
            ]);

            $user->oauthIdentities()->create([
                'provider' => $pending->provider,
                'provider_user_id' => $pending->providerUserId,
            ]);

            UserConsent::recordAcceptance($user, 'terms', 'google_oauth');
            UserConsent::recordAcceptance($user, 'privacy', 'google_oauth');

            $this->walletAccountProvisioner->provisionUserAccountsWithinTransaction($user);

            return $user;
        });

        PendingOAuthIdentity::forget();

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function requiredDocumentsPublished(): bool
    {
        foreach (config('legal.required_documents') as $document) {
            if (! (bool) config("legal.documents.{$document}.published")) {
                return false;
            }
        }

        return true;
    }
}
