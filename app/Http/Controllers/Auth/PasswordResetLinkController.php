<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * The generic status message shown regardless of whether the
     * submitted email address belongs to a real account. Never branch
     * this on Password::sendResetLink()'s return status — doing so would
     * let an attacker enumerate registered accounts.
     */
    private const GENERIC_STATUS = 'If an account exists for that email address, a password reset link has been sent.';

    /**
     * Display the forgot-password page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email:rfc'],
        ]);

        $email = mb_strtolower(trim((string) $request->string('email')));

        Password::sendResetLink(['email' => $email]);

        return back()->with('status', self::GENERIC_STATUS);
    }
}
