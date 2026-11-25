<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user with a temporary password (issue 44) may only change it, see who they are and sign out until they change it.
 * Runs on the whole `auth:api` group, so every route added there later is blocked by default.
 */
class RequirePasswordChange
{
    /**
     * Routes a user with a temporary password may still call. `auth.refresh` sits outside `auth:api` and needs no
     * exemption.
     *
     * @var list<string>
     */
    public const array ALLOWED_ROUTES = ['auth.password.update', 'auth.me', 'auth.logout'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            return response()->json(['message' => 'Change your temporary password first.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
