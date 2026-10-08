<?php

namespace Adeguntoro\J2FAKit\Tests\Feature;

use Adeguntoro\J2FAKit\Livewire\TwoFactorRequired;
use Adeguntoro\J2FAKit\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * The bundled setup page with the kill switch left ON (the package default).
 *
 * defineRoutes() deliberately does NOT declare a "2fa.required" route, so any
 * route with that name can only have come from the service provider.
 */
class SetupPageTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('j2fakit.setup_page.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => response('login'))->name('login');
        $router->get('/dashboard', fn () => response('dashboard'))->name('dashboard');
    }

    public function test_setup_page_route_is_registered(): void
    {
        $this->assertTrue(Route::has('2fa.required'));
    }

    public function test_setup_page_route_points_at_the_component(): void
    {
        $action = Route::getRoutes()->getByName('2fa.required')->getAction();

        $this->assertSame(
            TwoFactorRequired::class,
            $action['controller'] ?? ($action['uses'] ?? null)
        );
    }

    public function test_setup_page_keeps_a_session_and_an_auth_check(): void
    {
        $middleware = Route::getRoutes()->getByName('2fa.required')->gatherMiddleware();

        $this->assertContains('web', $middleware, 'the route must carry the web group or it has no session');
        $this->assertContains('auth', $middleware, 'the route must require a signed-in user');
    }

    public function test_setup_page_is_registered_at_the_default_path(): void
    {
        $route = Route::getRoutes()->getByName('2fa.required');

        $this->assertNotNull($route);
        $this->assertSame('two-factor-required', $route->uri());
    }
}
