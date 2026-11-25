<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A pending user (issue 46, Q5) can sign in but may only see who they are, change their password (issue 44) and sign
 * out until a System Administrator classifies them. Runs on the whole `auth:api` group, so every route added there later is blocked by default.
 */
class BlockPendingUsers
{
    /**
     * Routes a pending user may still call. `auth.refresh` sits outside `auth:api` and needs no exemption.
     *
     * @var list<string>
     */
    public const array ALLOWED_ROUTES = ['auth.me', 'auth.logout', 'auth.password.update'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isPending() && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            return response()->json([
                'message' => 'Your account is waiting for an administrator to set up your access.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
