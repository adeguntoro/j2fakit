# J2FAKit

Simple package: by default **every web route** requires login + 2FA. Can be bypassed via whitelist (name/path), or `j2fakit.exempt`/`withoutMiddleware('j2fakit')` on the route.

## Requirements

This package **installs the 2FA stack for you** — `composer require` pulls in
`laravel/jetstream`, `laravel/fortify` and `livewire/livewire`, and the package
ships the setup page. It does **not** run Jetstream's installer or migrations for
you, so the host app still needs these:

| Requirement | Why | Provided by |
|---|---|---|
| Laravel 11/12/13 with `illuminate/*` | base framework | your app |
| `php artisan jetstream:install livewire` | publishes `config/fortify.php`, the `two_factor_*` migrations, and the `x-button` / `x-confirms-password` Blade components the setup view renders | **you run it** |
| Fortify 2FA feature enabled (`Features::twoFactorAuthentication(['confirm' => true])`) | writes `two_factor_confirmed_at` once the user scans the QR code | **you enable it** |
| `two_factor_secret` + `two_factor_confirmed_at` columns on `users` | the only signals the gate checks | Fortify migration |
| Route named `2fa.required` (default `redirect_to`) | where unconfirmed users are sent | ✅ **auto-registered by this package** |
| QR code / setup page component | renders the QR code, setup key and recovery codes | ✅ **shipped** — `Adeguntoro\J2FAKit\Livewire\TwoFactorRequired` |

> ⚠️ **Composer installs Jetstream's code, not Jetstream's setup.** `composer require` already brings in `laravel/jetstream`, `laravel/fortify` and `livewire/livewire`, but nothing works until you run `php artisan jetstream:install livewire` — it publishes `config/fortify.php`, the `two_factor_*` migrations, the Blade components the setup view renders, and registers `App\Providers\FortifyServiceProvider`. Until then no user can ever have `two_factor_confirmed_at` set, so every logged-in user is sent back to `2fa.required` forever. Either do not append the middleware to the `web` group yet, or set `J2FAKIT_ENFORCED=false`.

> ⚠️ **A missing `redirect_to` route throws instead of looping.** If an authenticated user has not confirmed 2FA and `config('j2fakit.redirect_to')` names a route that does not exist, the gate throws a `RuntimeException` naming the config key. It deliberately does **not** fall back to the login route: `/login` carries the `guest` middleware, which sends authenticated users straight back to the protected page — an infinite `ERR_TOO_MANY_REDIRECTS` loop with nothing in the log. Guests are unaffected and still fall back to login normally.

This package never modifies Jetstream/Fortify files — publish and customize those in the host project as needed.

## Install

### Already ran `jetstream:install`?

```bash
composer require adeguntoro/j2fakit
```

`laravel/jetstream`, `laravel/fortify` and `livewire/livewire` are pulled in as
dependencies — you never need to require them separately.

Skip ahead to [Two settings to check](#two-settings-to-check-before-switching-the-gate-on).

### Starting from scratch

```bash
composer require adeguntoro/j2fakit
```

Composer brings the 2FA stack in as dependencies, but you still have to
scaffold Jetstream itself:

```bash
php artisan jetstream:install livewire    # or: php artisan jetstream:install inertia
```

The stack argument is required — only `livewire` and `inertia` are accepted.
Optional flags: `--teams`, `--api`, `--dark`, `--verification`, `--ssr`, `--pest`.

It is interactive, and it does the heavy lifting for you:

- publishes `config/fortify.php`, the Fortify support classes, and the
  migrations that add `two_factor_secret` / `two_factor_confirmed_at`
- registers `App\Providers\FortifyServiceProvider` in `bootstrap/app.php`
- rewrites the Fortify home route from `/home` to `/dashboard`
- installs Livewire `^3.6.4` (or Inertia `^2.0` + Ziggy), then runs
  `npm install && npm run build` — you do **not** need a separate npm step

> ⚠️ Near the end it asks *"New database migrations were added. Would you like
> to re-run your migrations?"* and, if you accept, runs **`migrate:fresh --force`**
> — which **drops every table in the database**. Answer **No** on an existing
> database and run `php artisan migrate` yourself.

> Composer cannot run `jetstream:install` on your behalf: a dependency's
> `scripts` are ignored, only the *root* package's scripts execute. This step
> is always manual.

### Two settings to check before switching the gate on

**1. Turn 2FA confirmation on** — `config/fortify.php`:

```php
'features' => [
    // ...
    Features::twoFactorAuthentication([
        'confirm' => true,           // required by this package's defaults
        'confirmPassword' => true,    // optional: re-ask for the password
    ]),
],
```

Fortify's published default is the bare `Features::twoFactorAuthentication()`,
which leaves `confirm` **off**. In that mode nothing ever writes
`two_factor_confirmed_at`, and since `j2fakit.require_confirmation` defaults to
`true`, **every user would be locked out permanently**.

If you would rather keep `confirm` off, set `'require_confirmation' => false`
in `config/j2fakit.php` instead — then a stored `two_factor_secret` alone counts
as enrolled.

**2. `redirect_to` resolves out of the box** — the package registers the
`2fa.required` route itself, pointing at its own setup page:

```php
'redirect_to' => '2fa.required',    // registered by this package
```

Two things to know:

- If your app already defines a route named `2fa.required`, **yours wins** —
  the package detects it after your routes have loaded and stays out of the way.
- If you turn the page off with `'setup_page' => ['enabled' => false]`, the
  package registers nothing, so `redirect_to` must name a route **you** define.
  When it names a route that does not exist the gate throws a `RuntimeException`
  for authenticated users rather than falling back to `/login` (that fallback
  loops forever).

## The bundled 2FA setup page

`composer require` gives you a working QR-code page — no `App\Livewire\...`
class to write. The package registers it automatically:

| | Default |
|---|---|
| Route name | `2fa.required` |
| Path | `/two-factor-required` |
| Component | `Adeguntoro\J2FAKit\Livewire\TwoFactorRequired` |
| Middleware | `['web', 'auth']` |
| Env kill switch | `J2FAKIT_SETUP_PAGE` |

It renders the QR code, the manual setup key, the verification input and the
recovery codes, then carries the user on to `j2fakit.after_setup_route` once
confirmed. It needs **no changes to your `User` model** — decrypting the secret
and the recovery codes happens inside the component.

Three ways to take control:

**1. Just change where it lives** — config/j2fakit.php:

```php
'setup_page' => [
    'enabled'    => env('J2FAKIT_SETUP_PAGE', true),
    'path'       => '/scan-this-first',
    'name'       => 'setup.2fa',
    'middleware' => ['web', 'auth'],
],
```

**2. Own route entirely (the kill switch)** — set `'enabled' => false` and
register the component yourself, so path, name and middleware are all yours:

```php
// routes/web.php
use Adeguntoro\J2FAKit\Livewire\TwoFactorRequired;

Route::middleware(['web', 'auth'])->get('/two-factor-required', TwoFactorRequired::class)
    ->name('2fa.required');
```

Keep `'web'` in the middleware: the component reads the signed-in user from the
session, so without that group it renders as logged out.

**3. Already have your own route named `2fa.required`?** Nothing to do. The
package registers late, sees yours, and stays out of the way — it never shadows
or duplicates a host route.

To customize the look, publish the view:

```bash
php artisan vendor:publish --tag=views
```

That drops a copy at `resources/views/livewire/two-factor-required.blade.php`,
which takes precedence over the packaged one automatically.

## Publish

```bash
php artisan vendor:publish --tag=config
php artisan vendor:publish --tag=views  # optional, custom view livewire/two-factor-required
```

## Register middleware (optional)

The provider automatically registers the aliases `j2fakit` & `j2fakit.exempt`. To enforce it on ALL web routes, add it to the web group in `bootstrap/app.php` (Laravel 11+):

```php
use Adeguntoro\J2FAKit\Middleware\J2FAKitEnsureTwoFactorIsEnabled;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        J2FAKitEnsureTwoFactorIsEnabled::class,
    ]);
})
```

## How to use

### What the gate does on every request

Once the middleware is on the `web` group, each request goes through this decision:

| Visitor | HTML request | JSON/XHR request |
|---|---|---|
| Guest (not logged in) | Redirect to the login route | `401 Unauthenticated` |
| Logged in, 2FA **not** confirmed yet | Redirect to `j2fakit.redirect_to` (default: route `2fa.required`, the QR code page) | `403` with a message |
| Logged in, 2FA confirmed | Passes through | Passes through |
| Route is whitelisted/exempt | Passes through with **no** checks at all | Passes through |

Notes:

- The login redirect is resolved with `Route::has()` first (`j2fakit.login_route`), then falls back to the plain URL `j2fakit.login_path`. A renamed/missing login route degrades to a redirect instead of a 500.
- Some routes are **always allowed** and hardcoded in the middleware (`login`, `logout`, `register`, `password.*`, `verification.*`, `2fa.required`, `two-factor.*`, `livewire.*`, `sanctum.csrf-cookie`, ...), so you can never lock yourself out of the login screen - even if the config file is emptied or not published.
- The gate only runs on routes in the `web` group. `api` routes are untouched.

### routes/web.php examples

```php
use Illuminate\Support\Facades\Route;

// 1. Default - behind the gate.
// Guest -> /login, user without confirmed 2FA -> /two-factor-required,
// user with confirmed 2FA -> allowed.
Route::get('/dashboard', function () {
    return view('dashboard');
})->name('dashboard');


// 2. Fully PUBLIC (no login, no 2FA check at all).
// The "j2fakit.exempt" alias marks the route as exempt.
Route::middleware('j2fakit.exempt')->get('/support', function () {
    return view('support');
})->name('support');


// 3. Alternative way to do the same thing: drop the middleware from this route.
Route::get('/changelog', function () {
    return view('changelog');
})->withoutMiddleware('j2fakit')->name('changelog');


// 4. Login REQUIRED, but 2FA NOT required.
// "auth" is what enforces the login - the gate simply steps aside.
Route::middleware(['auth', 'j2fakit.exempt'])->get('/account/settings', function () {
    return view('settings');
})->name('settings');


// 5. Whitelisted by name in config, but you still want it PRIVATE.
// Whitelisting only opts out of the 2FA gate; declare "auth" on the
// route itself to keep guests out.
Route::middleware('auth')->get('/profile', function () {
    return view('profile');
})->name('profile.show');


// 6. Exempt works on route groups too.
Route::middleware('j2fakit.exempt')->group(function () {
    Route::get('/docs', fn () => view('docs.index'))->name('docs.index');
    Route::get('/docs/{page}', fn () => view('docs.show'))->name('docs.show');
});
```

The fully qualified class name works anywhere the alias does, since the gate
recognises both:

```php
use Adeguntoro\J2FAKit\Middleware\J2FAKitWhitelistRoutesMiddleware;

Route::middleware(J2FAKitWhitelistRoutesMiddleware::class)
    ->get('/public-key', fn () => response()->noContent());
```

### Whitelist by route name (config/j2fakit.php)

Matched with `$request->routeIs()`, so `*` wildcards work:

```php
'whitelist' => [
    'home',              // exact route name
    'marketing.*',       // every route named marketing.something
    'docs.show',
],
```

Entries are matched against the route **name** (the `->name(...)` value), not the URL.

### Whitelist by path (config/j2fakit.php)

Use this when a route name is dynamic or generated and unreliable to match.
Matched with `$request->is()`, leading `/` optional:

```php
'whitelist_paths' => [
    'docs/*',            // matches /docs, /docs/intro, ...
    '/debug-2fa',        // leading slash is fine too
    'webhooks/stripe/*',
],
```

Path whitelist makes the route reachable by **anybody** - keep it private by
adding `->middleware('auth')` to the route if guests should not see it.

### Other config options

```php
// config/j2fakit.php

// Kill switch - turn the whole gate off without removing the middleware.
'enabled' => env('J2FAKIT_ENFORCED', true),

// Where an unconfirmed user is sent (route NAME, checked with Route::has()).
// MUST exist: if it does not, the gate throws a RuntimeException for
// authenticated users rather than looping through /login.
// Registered by this package by default — see the setup page section above.
'redirect_to' => '2fa.required',

// Where the setup page sends the user once 2FA is confirmed (route NAME,
// checked with Route::has(); falls back to the app root when missing).
'after_setup_route' => 'dashboard',

// The bundled QR-code page: kill switch, path, name and middleware.
// 'enabled' => false registers nothing — you wire the component up yourself.
'setup_page' => [
    'enabled'    => env('J2FAKIT_SETUP_PAGE', true),
    'path'       => '/two-factor-required',
    'name'       => '2fa.required',
    'middleware' => ['web', 'auth'],
],

// Login destination: name first, plain path as fallback.
'login_route' => 'login',
'login_path'  => '/login',

// false for hosts running Fortify/Jetstream with 'confirm' => false,
// where two_factor_confirmed_at is never written and users would
// otherwise be locked out forever.
'require_confirmation' => true,
```

### JSON / XHR responses

API and XHR calls never receive an HTML redirect:

- Guest -> `401 {"message": "Unauthenticated."}`
- Logged in but 2FA unconfirmed -> `403 {"message": "Two factor authentication has not been confirmed."}`

## Notes

- The gate only targets the **web group** (the api is not touched).
- `'*'` in the whitelist bypasses ALL named routes (be careful).
- Safe against login loops (Route::has guard + fallback), leading slash in whitelist_paths, garbage entries are skipped.
- Custom view: publish `views` to override `resources/views/livewire/two-factor-required.blade.php`.
- Bundled setup page: auto-registered as `2fa.required`; disable with `J2FAKIT_SETUP_PAGE=false` (or `setup_page.enabled => false`) and register the component yourself if you want full control.
- Your `User` model stays stock — secret/recovery-code decryption and QR generation live in the component, not on the model.
- Never overwrites a route you already defined with the same name.
- Requires Laravel 11+ (Laravel 10 support was dropped when Jetstream became a hard dependency).
- Tested with PHPUnit: 38 tests / 74 assertions covering the gate, the whitelist, setup-page registration and the component's secret handling.
