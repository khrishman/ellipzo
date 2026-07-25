<?php

use App\Http\Middleware\EnsureAccountCanAccessProtectedRoutes;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'account.protected' => EnsureAccountCanAccessProtectedRoutes::class,
        ]);
        // ThrottleRequests is one of Laravel's own priority-sorted
        // middleware; PermissionMiddleware is not. Without an explicit
        // priority entry, Laravel's automatic reordering (driven by the
        // 'web' group's own priority-listed members, e.g.
        // SubstituteBindings) can pull a route's throttle:... ahead of a
        // route-specific permission:... check despite declaration order -
        // verified empirically: an unauthorized user's rejected request
        // was still incrementing the rate-limit counter. Explicitly
        // anchoring PermissionMiddleware right after auth (and therefore
        // before ThrottleRequests, which sits immediately after auth in
        // Laravel's default priority list) makes the order deterministic
        // regardless of group internals. Must reference the
        // AuthenticatesRequests *interface*, not the concrete Authenticate
        // class - that interface is the actual entry Laravel's default
        // priority list contains; the concrete class silently fails the
        // lookup and falls back to appending at the very end instead
        // (confirmed via reflection on the live priority list, not
        // assumed).
        $middleware->appendToPriorityList(
            after: AuthenticatesRequests::class,
            append: PermissionMiddleware::class,
        );
        // Required order is auth -> account-status -> verified ->
        // permission (Task 10). Inserting this *after* the
        // PermissionMiddleware call above, targeting the same anchor,
        // bumps PermissionMiddleware one slot later - the net effect is
        // Authenticate -> EnsureAccountCanAccessProtectedRoutes ->
        // PermissionMiddleware -> ThrottleRequests. Verified via
        // reflection on the live priority list, not assumed, given the
        // Task 9.1 lesson that declaration order alone is not reliable
        // once the 'web' group's own priority-listed members are involved.
        $middleware->appendToPriorityList(
            after: AuthenticatesRequests::class,
            append: EnsureAccountCanAccessProtectedRoutes::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
