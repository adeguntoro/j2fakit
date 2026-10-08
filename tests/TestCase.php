<?php

namespace Adeguntoro\J2FAKit\Tests;

use Adeguntoro\J2FAKit\J2FAKitServiceProvider;
use Adeguntoro\J2FAKit\Middleware\J2FAKitEnsureTwoFactorIsEnabled;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [J2FAKitServiceProvider::class];
    }

    /**
     * The routes below sit in the "web" group, whose EncryptCookies /
     * session middleware needs an app key to run in the test harness.
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // This suite defines its own 2fa.required route below, so the bundled
        // setup page is switched off to keep the gate tests deterministic.
        // SetupPageTest / SetupPageDisabledTest cover the auto-registration.
        $app['config']->set('j2fakit.setup_page.enabled', false);
    }

    /**
     * Mirror of the README's routes/web.php examples: every route in the
     * "web" group with the gate appended, exactly like a host app would
     * register it in bootstrap/app.php.
     */
    protected function defineRoutes($router): void
    {
        $gate = J2FAKitEnsureTwoFactorIsEnabled::class;

        $router->group(['middleware' => ['web', $gate]], function ($router) use ($gate) {
            // Auth lifecycle targets the gate redirects to.
            $router->get('/login', fn () => response('login-page'))->name('login');
            $router->get('/two-factor-required', fn () => response('2fa-setup-page'))->name('2fa.required');

            // 1. Default - behind the gate.
            $router->get('/dashboard', fn () => response('dashboard'))->name('dashboard');

            // 2. Fully public via the alias.
            $router->middleware('j2fakit.exempt')
                ->get('/support', fn () => response('support'))->name('support');

            // 3. Public via withoutMiddleware().
            $router->get('/changelog', fn () => response('changelog'))
                ->withoutMiddleware($gate)->name('changelog');

            // 4. Login required, 2FA not required.
            $router->middleware(['auth', 'j2fakit.exempt'])
                ->get('/settings', fn () => response('settings'))->name('settings');

            // 5. Whitelisted by name in config (profile.show) but still private.
            $router->middleware('auth')
                ->get('/profile', fn () => response('profile'))->name('profile.show');

            // Path-whitelisted by default config: 'livewire/*'.
            $router->get('/livewire/{component}', fn () => response('livewire'));

            // Route registered under a non-whitelisted name for bypass tests.
            $router->get('/changelog-alias', fn () => response('changelog-alias'))->name('changelog.alias');
        });

        // Route that resolves the gate ONLY through the 'j2fakit' alias -
        // proves aliasMiddleware() registration works.
        $router->get('/via-alias', fn () => response('via-alias'))
            ->middleware(['web', 'j2fakit'])->name('via.alias');
    }
}
