<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly WalletAccountProvisioner $walletAccountProvisioner,
    ) {}

    /**
     * Display the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    /**
     * Handle an incoming registration request.
     *
     * The user row, both required consent records, and the four
     * user-scoped wallet accounts are created inside one transaction:
     * either all six rows exist or none of them do. The Registered event
     * and login only happen after that transaction has actually
     * committed, so a consent or provisioning failure can never leave
     * behind an unconsented, unprovisioned, already-authenticated
     * account.
     */
    public function store(RegisterUserRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => Hash::make($request->validated('password')),
            ]);

            UserConsent::recordAcceptance($user, 'terms', 'registration_checkbox');
            UserConsent::recordAcceptance($user, 'privacy', 'registration_checkbox');

            $this->walletAccountProvisioner->provisionUserAccountsWithinTransaction($user);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
