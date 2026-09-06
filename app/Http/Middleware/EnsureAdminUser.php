<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a request authenticated with a CustomerAccount token (or any
 * non-User tokenable) before it reaches admin/staff-only route logic that
 * assumes `$request->user()` is a `User` (e.g. calls `isSuperAdmin()`).
 */
class EnsureAdminUser
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() instanceof User, 403, 'This endpoint is for staff/admin accounts only.');

        return $next($request);
    }
}
