<?php

namespace Adeguntoro\J2FAKit\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class J2FAKitEnsureTwoFactorIsEnabled
{
    /**
     * Routes the gate never touches (public by definition).
     *
     * Deliberately duplicated from config('j2fakit.whitelist') so that
     * the auth lifecycle keeps working even if that file is edited, emptied
     * or not published. Once guests are blocked by default, a missing login
     * entry would be a self-inflicted lockout: nobody could reach the login
     * screen to fix anything.
     */
    protected const ALWAYS_ALLOWED = [
        // Auth lifecycle - reachable while logged out, by definition. Now
        // that guests are blocked by default, dropping these would be a
        // self-inflicted lockout: nobody could reach the login screen.
        'login',
        'login.store',
        'logout',
        'register',
        'password.*',
        'verification.*',
        'sanctum.csrf-cookie',

        // 2FA setup. Bouncing the user away from the QR code screen is what
        // makes 2FA impossible to enable in the first place.
        'profile.show',
        '2fa.required',
        'two-factor.*',

        // Livewire plumbing. The update route is named
        // "default.livewire.update", so "livewire.*" alone misses it.
        'default.livewire.*',
        'livewire.*',
    ];

    /**
     * Handle an incoming request.
     *
     * DEFAULT: every web route needs a signed-in user with a confirmed 2FA
     * setup. Guests are sent to the login screen; users who have not
     * enrolled are sent to the 2FA setup page.
     *
     * WHITELISTED: the gate steps aside completely - no login check, no 2FA
     * check. A route stays private by declaring ->middleware('auth') itself,
     * which runs independently of this middleware.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('j2fakit.enabled', true)) {
            return $next($request);
        }

        if ($this->isWhitelisted($request)) {
            return $next($request);
        }

        $user = $request->user();

        // A guest has no 2FA to check, so they must sign in first.
        // redirect()->guest() stashes the intended URL so that after login
        // the visitor lands back on the page they originally asked for.
        if (! $user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->guest($this->loginUrl());
        }

        // 2FA is enabled and confirmed, carry on.
        if ($this->hasConfirmedTwoFactor($user)) {
            return $next($request);
        }

        // XHR must never receive an HTML redirect it cannot parse.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Two factor authentication has not been confirmed.',
            ], 403);
        }

        $redirectRoute = config('j2fakit.redirect_to', '2fa.required');

        // The user is ALREADY authenticated at this point, so falling back to
        // the login route would be a trap: /login carries the "guest"
        // middleware, which redirects authenticated users back to their home
        // route. The two redirects chase each other forever, the browser
        // reports ERR_TOO_MANY_REDIRECTS, and nothing reaches the log.
        //
        // A missing target is a wiring mistake rather than a runtime
        // condition, so fail loudly with the offending config key instead of
        // guessing. Guests never reach this branch - they are handled above
        // and still fall back to the login route normally.
        if (! is_string($redirectRoute) || ! Route::has($redirectRoute)) {
            throw new RuntimeException(
                "J2FAKit: config('j2fakit.redirect_to') is "
                .var_export($redirectRoute, true)
                .' but no route with that name exists. Define that route, or '
                .'point j2fakit.redirect_to at an existing route. Refusing to '
                .'fall back to the login route because the user is already '
                .'authenticated and that fallback loops forever.'
            );
        }

        return redirect()->route($redirectRoute);
    }

    /**
     * Where to send a guest.
     *
     * The route NAME is configurable and checked with Route::has() so a host
     * app that renamed its login route degrades to the login_path URL
     * instead of throwing RouteNotFoundException on every guest request.
     */
    protected function loginUrl(): string
    {
        $route = config('j2fakit.login_route', 'login');

        return Route::has($route)
            ? route($route)
            : url(config('j2fakit.login_path', '/login'));
    }

    /**
     * Decide whether the user's 2FA setup counts as finished.
     *
     * With Fortify's 'confirm' => true, confirmed_at is written when the
     * user proves they can read their authenticator. Host apps running
     * 'confirm' => false NEVER write it, so requiring it would lock every
     * user out forever - for those the stored secret alone counts (set
     * j2fakit.require_confirmation to false).
     */
    protected function hasConfirmedTwoFactor(object $user): bool
    {
        if (! is_null($user->two_factor_confirmed_at ?? null)) {
            return true;
        }

        if (config('j2fakit.require_confirmation', true)) {
            return false;
        }

        // Raw attribute read: never decrypt here - a key rotation must not
        // be able to crash every request that passes through the gate.
        $attributes = $user instanceof Model ? $user->getAttributes() : [];

        return isset($attributes['two_factor_secret']);
    }

    /**
     * Determine whether the gate should step aside entirely for this request.
     *
     * A whitelisted request is PUBLIC: neither guests nor unconfirmed users
     * are bounced anywhere.
     */
    protected function isWhitelisted(Request $request): bool
    {
        $patterns = array_merge(
            static::ALWAYS_ALLOWED,
            (array) config('j2fakit.whitelist', [])
        );

        foreach ($patterns as $pattern) {
            // Skip garbage entries: '', null, 0 ... - only strings can be
            // matched, and an empty pattern would be a silent wildcard-ish
            // footgun rather than a useful rule.
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if ($request->routeIs($pattern)) {
                return true;
            }
        }

        foreach ((array) config('j2fakit.whitelist_paths', []) as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            // Accept '/debug-2fa' as well as 'debug-2fa' - Request::is()
            // matches against a path that never carries a leading slash,
            // so config written either way behaves the same.
            if ($request->is(ltrim($pattern, '/'))) {
                return true;
            }
        }

        return $this->isMarkedExempt($request);
    }

    /**
     * Determine whether the matched route was tagged with the "j2fakit.exempt"
     * middleware (or this middleware's class name directly).
     *
     * gatherMiddleware() does not resolve aliases into class names, so both
     * forms have to be checked.
     */
    protected function isMarkedExempt(Request $request): bool
    {
        $route = $request->route();

        if (! $route) {
            return false;
        }

        $middleware = $route->gatherMiddleware();

        return in_array(J2FAKitWhitelistRoutesMiddleware::class, $middleware, true)
            || in_array(J2FAKitWhitelistRoutesMiddleware::ALIAS, $middleware, true);
    }
}
