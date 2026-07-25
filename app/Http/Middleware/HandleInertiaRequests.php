<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'emailVerifiedAt' => $user->email_verified_at?->toIso8601String(),
                ] : null,
                // Display-only, for filtering admin navigation. Every
                // admin route independently re-checks the same
                // permissions server-side (routes/admin.php) - this list
                // is never itself an authorization boundary.
                'permissions' => $user ? $user->getAllPermissions()->pluck('name') : [],
                // Just the status value, not the full eligibility
                // assessment - eligibility is computed on-demand only
                // where it's actually needed (the dashboard), not on
                // every request.
                //
                // Only a genuinely absent (guest) user may produce null
                // here. An authenticated, persisted user must always have
                // a valid AccountStatus - the model's own default
                // guarantees this in-memory with no refresh() needed, and
                // the database column default backs it up on every row.
                // If that invariant is ever broken, ->value below throws
                // rather than silently presenting a missing status as
                // null or as a default of "active".
                'accountStatus' => $user ? $user->account_status->value : null,
            ],
        ];
    }
}
