<?php

namespace Adeguntoro\J2FAKit;

use Adeguntoro\J2FAKit\Middleware\J2FAKitEnsureTwoFactorIsEnabled;
use Adeguntoro\J2FAKit\Middleware\J2FAKitWhitelistRoutesMiddleware;
use Illuminate\Support\ServiceProvider;

class J2FAKitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/j2fakit.php' => config_path('j2fakit.php'),
        ], 'config');

        // Publish stubs (views etc., Jetstream custom view optional)
        $this->publishes([
            __DIR__.'/../stubs/two-factor-required.blade.php' => resource_path('views/livewire/two-factor-required.blade.php'),
        ], 'views');
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
