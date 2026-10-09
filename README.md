# J2FAKit

By default **every web route** requires login + 2FA. Skip a route by whitelisting
it in config, using the `j2fakit.exempt` alias, or `->withoutMiddleware('j2fakit')`.

Requires **Laravel 11 / 12 / 13**.

---

## Step 1 — Install Jetstream

```bash
php artisan jetstream:install livewire
```

`composer require` (Step 2) pulls the Jetstream *code* in, but a dependency's
scripts never run — this step is always manual. It publishes `config/fortify.php`,
the `two_factor_*` migrations, and the Blade components the setup view renders.

> ⚠️ Near the end it asks to re-run migrations, which runs **`migrate:fresh
> --force`** and **drops every table**. Answer **No** on an existing database and
> run `php artisan migrate` yourself.

Then edit `config/fortify.php` — this is the one setting that can lock you out:

```php
'features' => [
    // ...
    Features::twoFactorAuthentication(['confirm' => true]),
],
```

Fortify's published default is the bare `Features::twoFactorAuthentication()`,
which leaves `confirm` **off**. Nothing then ever writes `two_factor_confirmed_at`,
so with `require_confirmation` at its default `true`, **every user would be locked
out permanently**. If you want `confirm` off instead, set
`'require_confirmation' => false` in `config/j2fakit.php`.

## Step 2 — Install this package

```bash
composer require adeguntoro/j2fakit
```

`laravel/jetstream`, `laravel/fortify` and `livewire/livewire` arrive as
dependencies — never require them separately.

## Step 3 — Publish config / view

```bash
php artisan vendor:publish --tag=config   # config/j2fakit.php
php artisan vendor:publish --tag=views    # optional: restyle the QR page
```

The view lands at `resources/views/livewire/two-factor-required.blade.php` and
takes precedence over the packaged copy automatically.

## Step 4 — Add the middleware

`bootstrap/app.php` (Laravel 11+):

```php
use Adeguntoro\J2FAKit\Middleware\J2FAKitEnsureTwoFactorIsEnabled;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        J2FAKitEnsureTwoFactorIsEnabled::class,
    ]);
})
```

The aliases `j2fakit` and `j2fakit.exempt` are registered for you.

> The package registers the `2fa.required` route (the QR page) itself, so
> `redirect_to` resolves out of the box. If your app already defines a route with
> that name, **yours wins** — the package registers late and stays out of the way.

## Step 5 — Add a route

`routes/web.php`:

```php
use Illuminate\Support\Facades\Route;

// Behind the gate: guest -> /login, 2FA unconfirmed -> /two-factor-required.
Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');
```

Other shapes:

```php
// Fully public — no login, no 2FA check at all.
Route::middleware('j2fakit.exempt')->get('/support', fn () => view('support'))->name('support');

// The same, by dropping the middleware from the route.
Route::get('/changelog', fn () => view('changelog'))->withoutMiddleware('j2fakit')->name('changelog');

// Login required, but 2FA not required.
Route::middleware(['auth', 'j2fakit.exempt'])->get('/settings', fn () => view('settings'))->name('settings');
```

The fully qualified class name works anywhere the alias does.

## Step 6 — Test it

Log in, then visit `/dashboard`:

| Visitor | HTML request | JSON / XHR |
|---|---|---|
| Guest | redirect to the login route | `401 {"message":"Unauthenticated."}` |
| Logged in, 2FA **not** confirmed | redirect to `2fa.required` (the QR page) | `403 {"message":"Two factor authentication has not been confirmed."}` |
| Logged in, 2FA confirmed | passes | passes |
| Route whitelisted / exempt | passes with no checks | passes |

On the setup page: scan the QR code (or copy the setup key), enter the code, and
you get your recovery codes. The page then sends you to
`j2fakit.after_setup_route` (default `dashboard`).

---

## Whitelisting

`config/j2fakit.php`. Matched on route **name** via `$request->routeIs()`, so `*`
wildcards work:

```php
'whitelist' => [
    'home',              // exact route name
    'marketing.*',       // every route named marketing.something
],
```

Matched on route **path** via `$request->is()`, leading `/` optional:

```php
'whitelist_paths' => [
    'docs/*',            // /docs, /docs/intro, ...
    '/debug-2fa',
],
```

A whitelisted **path** is reachable by anybody — add `->middleware('auth')` to the
route if guests should not see it. Whitelisting only opts out of the 2FA gate.

Some routes are always allowed and hardcoded (`login`, `logout`, `register`,
`password.*`, `verification.*`, `2fa.required`, `two-factor.*`, `livewire.*`,
`sanctum.csrf-cookie`, ...) — you can never lock yourself out of the login screen,
even if the config file is emptied or not published.

## The bundled setup page

| | Default |
|---|---|
| Route name | `2fa.required` |
| Path | `/two-factor-required` |
| Component | `Adeguntoro\J2FAKit\Livewire\TwoFactorRequired` |
| Middleware | `['web', 'auth']` |
| Layout | `layouts.app` |
| Kill switch | `J2FAKIT_SETUP_PAGE=false` |

Your `User` model stays stock — secret/recovery-code decryption and QR generation
live in the component, not on the model.

To move it, `config/j2fakit.php`:

```php
'setup_page' => [
    'enabled'    => env('J2FAKIT_SETUP_PAGE', true),
    'path'       => '/scan-this-first',
    'name'       => 'setup.2fa',
    'middleware' => ['web', 'auth'],
    'layout'     => 'layouts.app',   // null -> defer to config('livewire.layout')
],
```

To own the route entirely, set `'enabled' => false` and register the component
yourself:

```php
use Adeguntoro\J2FAKit\Livewire\TwoFactorRequired;

Route::middleware(['web', 'auth'])->get('/two-factor-required', TwoFactorRequired::class)
    ->name('2fa.required');
```

Keep `'web'` — the component reads the signed-in user from the session, so without
it the page renders logged out.

> **Why `layout` matters.** A route-mounted Livewire component renders inside a
> layout, and Livewire's default is `components.layouts.app` — a path Jetstream
> never creates (it publishes `layouts/app.blade.php`), which kills the page with
> `Livewire page component layout view not found: [components.layouts.app]`.
> Jetstream's layout is `{{ $slot }}`, which is what `->layout()` feeds.

## Config reference

```php
// config/j2fakit.php

// Kill switch for the whole gate, without removing the middleware.
'enabled' => env('J2FAKIT_ENFORCED', true),

// Where an unconfirmed user is sent (route NAME, checked with Route::has()).
// MUST exist — a missing one throws a RuntimeException for authenticated users
// rather than falling back to /login, which loops forever. Guests still fall
// back to login normally. Auto-registered by this package.
'redirect_to' => '2fa.required',

// Route NAME the setup page sends you to once 2FA is confirmed
// (checked with Route::has(); falls back to the app root when missing).
'after_setup_route' => 'dashboard',

// Login destination: name first, plain path as a fallback.
'login_route' => 'login',
'login_path'  => '/login',

// false for hosts running Fortify with 'confirm' => false, where
// two_factor_confirmed_at is never written and users would otherwise
// be locked out forever.
'require_confirmation' => true,

// The bundled QR-code page and the two whitelists — see the sections above.
'setup_page'       => [ /* enabled, path, name, middleware, layout */ ],
'whitelist'        => [],
'whitelist_paths'  => [],
```

## Notes

- The gate only runs on routes in the `web` group; `api` routes are untouched.
- `'*'` in the whitelist bypasses **all** named routes — be careful.
- Leading slashes in `whitelist_paths` are fine; garbage entries are skipped.
- This package never modifies Jetstream/Fortify files — publish and customize
  those in the host project as needed.
- Requires Laravel 11+ (10 was dropped when Jetstream became a hard dependency).
- 42 tests / 80 assertions covering the gate, the whitelist, setup-page
  registration, layout resolution and the component's secret handling.

## License

MIT.
