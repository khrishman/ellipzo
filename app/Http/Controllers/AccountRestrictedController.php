<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountRestrictedController extends Controller
{
    /**
     * Deliberately not gated by EnsureAccountCanAccessProtectedRoutes -
     * gating this page with the same middleware that redirects here would
     * create a redirect loop. A non-restricted user visiting directly has
     * nothing to see, so they're sent back to the dashboard instead.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->account_status->blocksProtectedRoutes()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('account/restricted');
    }
}
