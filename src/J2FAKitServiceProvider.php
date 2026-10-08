<?php

namespace Adeguntoro\J2FAKit;

use Adeguntoro\J2FAKit\Livewire\TwoFactorRequired;
use Adeguntoro\J2FAKit\Middleware\J2FAKitEnsureTwoFactorIsEnabled;
use Adeguntoro\J2FAKit\Middleware\J2FAKitWhitelistRoutesMiddleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class J2FAKitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The packaged view, exposed as "j2fakit::two-factor-required". An app
        // that published its own copy at resources/views/livewire/... wins,
        // because the component checks for that first.
        $this->loadViewsFrom(__DIR__.'/../stubs', 'j2fakit');

        // Publish config
        $this->publishes([
            __DIR__.'/../config/j2fakit.php' => config_path('j2fakit.php'),
        ], 'config');

        // Publish stubs (views etc., Jetstream custom view optional)
        $this->publishes([
            __DIR__.'/../stubs/two-factor-required.blade.php' => resource_path('views/livewire/two-factor-required.blade.php'),
        ], 'views');

        // Deferred until the app has fully booted. This provider boots BEFORE
        // routes/web.php is loaded (the framework registers the app's route
        // provider from a "booting" callback, so it lands at the end of the
        // provider list), which means a Route::has() check made right here
        // would be blind and could register a duplicate that shadows the
        // host's own 2fa.required route. Waiting for "booted" makes the guard
        // see every route the app defined.
        $this->app->booted(fn () => $this->registerSetupPage());
    }

    /**
     * Register the bundled setup page, unless it is switched off or the host
     * app already defines a route with the same name.
     */
    protected function registerSetupPage(): void
    {
        if (! config('j2fakit.setup_page.enabled', true)) {
            return;
        }

        $name = (string) config('j2fakit.setup_page.name', '2fa.required');
        $path = (string) config('j2fakit.setup_page.path', '/two-factor-required');

        if ($name === '' || $path === '' || Route::has($name)) {
            return;
        }

        // 'web' is required even though the route is not in routes/web.php:
        // without that group there is no session, so the component would see
        // the visitor as logged out and render nothing useful.
        $middleware = array_values(array_filter(
            (array) config('j2fakit.setup_page.middleware', ['web', 'auth']),
            fn ($m) => is_string($m) && $m !== ''
        ));

        Route::middleware($middleware)
            ->get($path, TwoFactorRequired::class)
            ->name($name);
    }

    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(__DIR__.'/../config/j2fakit.php', 'j2fakit');

        // Register middleware aliases globally (optional - app can also register)
        $router = $this->app['router'];
        $router->aliasMiddleware('j2fakit', J2FAKitEnsureTwoFactorIsEnabled::class);
        $router->aliasMiddleware('j2fakit.exempt', J2FAKitWhitelistRoutesMiddleware::class);
    }
}
