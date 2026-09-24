<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level permission gate.
 *
 * Applied as `permission:trainees.view` or `permission:payments.view,payments.create`
 * (any one of them is enough). This is the outermost of three layers —
 * middleware, policy, service — so a missing check in one is not a hole.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $user->hasAnyPermission(...$permissions)) {
            abort(403, 'لا تملك صلاحية الوصول إلى هذه الصفحة.');
        }

        return $next($request);
    }
}
