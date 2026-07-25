<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks SUSPENDED/CLOSED accounts from every protected route, including
 * /admin, regardless of what Spatie permissions they happen to hold.
 * LIMITED passes through unaffected here - it only ever affects the
 * separately-computed eligibility result, never route access itself.
 *
 * Must run after 'auth' (so $request->user() is resolved) and before
 * 'verified' and any route-specific permission check, so a
 * restricted-but-unverified user gets the restriction response rather
 * than the email-verification prompt, and a restricted staff member
 * never reaches a permission check at all.
 */
class EnsureAccountCanAccessProtectedRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->account_status->blocksProtectedRoutes()) {
            return redirect()->route('account.restricted');
        }

        return $next($request);
    }
}
