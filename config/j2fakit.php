<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Global 2FA enforcement
    |--------------------------------------------------------------------------
    |
    | When this is enabled, J2FAKitEnsureTwoFactorIsEnabled is appended to
    | the "web" middleware group in bootstrap/app.php, so that *every* web
    | route requires a signed-in user with a confirmed two factor setup -
    | not just the routes that explicitly list the "j2fakit" alias.
    | Guests are sent to /login, unconfirmed users to the "2fa.required" page.
    |
    */

    'enabled' => env('J2FAKIT_ENFORCED', true),

    /*
    |--------------------------------------------------------------------------
    | Redirect target
    |--------------------------------------------------------------------------
    |
    | The named route an unconfirmed user is sent to in order to set 2FA up.
    |
    */

    'redirect_to' => '2fa.required',

    /*
    |--------------------------------------------------------------------------
    | Login route (where guests are sent)
    |--------------------------------------------------------------------------
    |
    | login_route is a ROUTE NAME checked with Route::has(). If it does not
    | exist (host app renamed its login route and forgot this config), the
    | gate falls back to login_path - a plain URL - so guest requests
    | degrade to a normal redirect instead of throwing a 500 on every hit.
    |
    */

    'login_route' => 'login',

    'login_path' => '/login',

    /*
    |--------------------------------------------------------------------------
    | Does a stored secret count as "enrolled"?
    |--------------------------------------------------------------------------
    |
    | true (default): a user is only let through once
    |     two_factor_confirmed_at is set - the Fortify flow with
    |     'confirm' => true, where the user proves they can read their
    |     authenticator before 2FA is considered on.
    |
    | false: for apps running Fortify/Jetstream with 'confirm' => false.
    |     Nothing ever writes two_factor_confirmed_at in that mode, so
    |     requiring it would lock EVERY user out forever. With this set to
    |     false, a stored two_factor_secret alone counts as enrolled.
    |
    */

    'require_confirmation' => true,

    /*
    |--------------------------------------------------------------------------
    | Bundled 2FA setup page
    |--------------------------------------------------------------------------
    |
    | The package ships a Livewire component
    | (Adeguntoro\J2FAKit\Livewire\TwoFactorRequired) that renders the QR
    | code, the manual setup key, the verification input and the recovery
    | codes. When 'enabled' it is registered automatically, so the default
    | redirect_to ("2fa.required") resolves out of the box.
    |
    | The route is registered LATE, after routes/web.php has loaded. If your
    | app already defines a route with 'name', yours is kept and the package
    | stays out of the way - no shadowing, no duplicate-name surprises.
    |
    | 'enabled' => false is the kill switch: the package registers nothing at
    | all and you wire the component into routes/web.php yourself, which is
    | how to get full control over the path, name and middleware:
    |
    |     Route::middleware(['web', 'auth'])->get('/two-factor-required',
    |         \Adeguntoro\J2FAKit\Livewire\TwoFactorRequired::class
    |     )->name('2fa.required');
    |
    | 'middleware' must keep a session-backed 'web' group and an auth check:
    | the component reads the signed-in user from the session, so dropping
    | 'web' would render it logged out.
    |
    | 'layout' is the view the page renders inside. A route-mounted Livewire
    | component otherwise falls back to config('livewire.layout'), whose
    | default is "components.layouts.app" - a path Jetstream never creates
    | (it publishes layouts/app.blade.php), which would kill the page with
    | "Livewire page component layout view not found". Jetstream's layout is
    | {{ \$slot }}, which is what ->layout() feeds.
    |
    | Set 'layout' => null to hand the choice back to config('livewire.layout').
    |
    */

    'setup_page' => [
        'enabled'    => env('J2FAKIT_SETUP_PAGE', true),
        'path'       => '/two-factor-required',
        'name'       => '2fa.required',
        'middleware' => ['web', 'auth'],
        'layout'     => 'layouts.app',
    ],

    /*
    |--------------------------------------------------------------------------
    | Where the setup page sends the user afterwards
    |--------------------------------------------------------------------------
    |
    | Route NAME behind the "Continue" link and the default for Livewire's
    | redirectIntended(). Checked with Route::has(), so a host app without a
    | route of that name degrades to the app root instead of throwing
    | RouteNotFoundException after the user just finished scanning a QR code.
    |
    */

    'after_setup_route' => 'dashboard',

    /*
    |--------------------------------------------------------------------------
    | Whitelisted routes -> PUBLIC (matched against the route NAME)
    |--------------------------------------------------------------------------
    |
    | Anything matched here is handed straight through with NO checks at all:
    | a logged-out visitor can open it, and a logged-in user without a
    | confirmed 2FA setup can open it. The gate is the default for everything
    | else - guests are sent to /login, unconfirmed users to 2fa.required.
    |
    | Entries are matched with $request->routeIs(), which supports "*" as a
    | wildcard, so "two-factor.*" covers every Fortify/Jetstream 2FA endpoint.
    |
    | WARNING: an entry of exactly '*' whitelists EVERY named route - the
    | entire gate goes off app-wide. Never ship that value.
    |
    | Garbage entries ('', null, 0) are skipped by the gate - they can never
    | accidentally widen the list.
    |
    | This list matters more than it looks: the QR code is rendered on the
    | pages listed below, so failing to whitelist them means users get
    | bounced away from the setup screen and never see a QR code at all.
    |
    | Keep a whitelisted route PRIVATE by declaring ->middleware('auth') on
    | the route itself - that middleware runs independently of the gate, and
    | the gate is the only thing opting out here.
    |
    | Example - make /hello public (the route needs ->name('hello')):
    |     'hello',
    |
    */

    'whitelist' => [
        // Where 2FA is configured - renders the QR code, setup key and
        // recovery codes. Without this the profile page just redirects.
        // (Still protected by auth: Jetstream's route declares it.)
        'profile.show',

        // The dedicated "you must enable 2FA" page.
        '2fa.required',

        // Fortify + Jetstream 2FA endpoints: the login challenge, the
        // enable / disable / confirm endpoints, and the QR code + secret
        // key images.
        'two-factor.*',

        // Auth lifecycle - must stay reachable while logged out. These are
        // also hardcoded in the middleware's ALWAYS_ALLOWED, so even an
        // emptied config file cannot lock you out.
        'login',
        'login.store',
        'logout',
        'register',
        'password.*',
        'verification.*',

        // The public landing page. Remove to put the home page behind login.
        'home',

        // Livewire endpoints. Note the update route is really named
        // "default.livewire.update" - it is prefixed with the Livewire
        // component group - so "livewire.*" on its own does NOT match it.
        'default.livewire.*',
        'livewire.*',

        // Sanctum CSRF cookie endpoint.
        'sanctum.csrf-cookie',
    ],

    /*
    |--------------------------------------------------------------------------
    | Whitelisted paths -> also PUBLIC (matched against the request PATH)
    |--------------------------------------------------------------------------
    |
    | Matched with $request->is(), so "*" is a wildcard. Same semantics as the
    | 'whitelist' list above: the gate steps aside entirely, so these are
    | reachable by anybody. Use this for endpoints whose route name is dynamic
    | or generated, where matching by name is unreliable. Route names are
    | preferred over paths wherever possible.
    |
    */

    'whitelist_paths' => [
        'livewire/*',
        'sanctum/csrf-cookie',
    ],

];
