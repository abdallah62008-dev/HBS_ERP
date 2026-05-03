<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block authenticated users whose status is Inactive or Suspended.
 * Admin can deactivate a user without deleting them; this middleware
 * is the gate that enforces it on every request.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Your account is '.strtolower($user->status).'. Please contact an administrator.']);
        }

        return $next($request);
    }
}
