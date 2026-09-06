<?php

namespace App\Http\Middleware;

use App\Models\CustomerAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a request authenticated with a staff/admin User token before it
 * reaches customer-app route logic that assumes `$request->user()` is a
 * `CustomerAccount`.
 */
class EnsureCustomerAccount
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() instanceof CustomerAccount, 403, 'This endpoint is for customer accounts only.');

        return $next($request);
    }
}
