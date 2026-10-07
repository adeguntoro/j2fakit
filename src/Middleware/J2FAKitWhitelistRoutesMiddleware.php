<?php

namespace Adeguntoro\J2FAKit\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a route as exempt from the global 2FA requirement.
 *
 * This middleware deliberately does nothing when it runs: its only job is to
 * sit in a route's middleware list so that J2FAKitEnsureTwoFactorIsEnabled can spot
 * it via gatherMiddleware() and let the request through. Add it to any route
 * that must stay reachable before the user has confirmed two factor setup:
 *
 *     Route::middleware('j2fakit.exempt')->get('/support', SupportController::class);
 *
 * Prefer this over editing config/j2fakit.php when the exemption belongs
 * to a single route - it keeps the exception next to the route it applies to,
 * and it survives config changes.
 *
 * Both the alias and the fully qualified class name are recognised, so the
 * middleware still works if it is referenced without the alias.
 */
class J2FAKitWhitelistRoutesMiddleware
{
    /**
     * The route middleware alias registered in bootstrap/app.php.
     */
    public const ALIAS = 'j2fakit.exempt';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
