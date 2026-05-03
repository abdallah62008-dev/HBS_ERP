<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend permission enforcement.
 *
 * Usage in routes:
 *   Route::middleware('permission:orders.view')->...
 *   Route::middleware('permission:orders.edit,orders.create')->...  // ANY of
 *
 * Super Admin always passes (handled inside User::hasPermission).
 *
 * Per the project rules in 03_RBAC_SECURITY_AUDIT.md, permissions MUST be
 * enforced in the backend, not only the UI.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $user->isActive()) {
            abort(403, 'Inactive user.');
        }

        // Eager-load once so hasPermission() doesn't issue N queries.
        $user->loadMissing(['role.permissions', 'userPermissions']);

        if (empty($permissions) || $user->hasAnyPermission($permissions)) {
            return $next($request);
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}
