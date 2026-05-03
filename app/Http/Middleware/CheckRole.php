<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to one or more roles by slug.
 *
 *   Route::middleware('role:super-admin,admin')->...
 *
 * Use sparingly. Prefer permission-based gates over role checks so that
 * permissions can be re-shuffled across roles without touching code.
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $user->role || ! in_array($user->role->slug, $roles, true)) {
            abort(403, 'Your role is not allowed to access this resource.');
        }

        return $next($request);
    }
}
