<?php

namespace Adeguntoro\J2FAKit\Tests\Feature;

use Adeguntoro\J2FAKit\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * The kill switch is ON, but the host app has defined its own "2fa.required"
 * (which is exactly what petabudaya does).
 *
 * This is why registerSetupPage() is deferred to the "booted" callback: the
 * package provider boots before routes/web.php is loaded, so checking
 * Route::has() inside boot() would be blind and the package would register a
 * route that shadows the host's.
 */
class SetupPageHostRouteTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('j2fakit.setup_page.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => response('login'))->name('login');

        $router->get('/my-own-2fa', fn () => response('host-setup-page'))
            ->name('2fa.required');
    }

    public function test_host_route_wins(): void
    {
        $route = Route::getRoutes()->getByName('2fa.required');

        $this->assertNotNull($route);
        $this->assertSame(
            'my-own-2fa',
            $route->uri(),
            'the host definition must not be shadowed by the package'
        );
    }

    public function test_package_does_not_claim_its_default_path(): void
    {
        $paths = collect(Route::getRoutes())
            ->filter(fn ($route) => $route->uri() === 'two-factor-required')
            ->all();

        $this->assertCount(
            0,
            $paths,
            'with a host route already named 2fa.required the package must register nothing'
        );
    }
}
