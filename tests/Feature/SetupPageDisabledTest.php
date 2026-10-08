<?php

namespace Adeguntoro\J2FAKit\Tests\Feature;

use Adeguntoro\J2FAKit\Livewire\TwoFactorRequired;
use Adeguntoro\J2FAKit\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * The kill switch: setup_page.enabled = false.
 *
 * Proves the package registers NOTHING, so a host app that wants full control
 * over the path, name and middleware can wire the component up itself without
 * a duplicate route fighting it.
 */
class SetupPageDisabledTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('j2fakit.setup_page.enabled', false);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => response('login'))->name('login');

        // Exactly what a host app does after turning the kill switch off.
        $router->get('/my-two-factor', fn () => response('my-setup-page'))
            ->name('2fa.required');
    }

    public function test_package_registers_no_route_when_disabled(): void
    {
        $route = Route::getRoutes()->getByName('2fa.required');

        $this->assertNotNull($route, 'the host route must survive untouched');
        $this->assertSame('my-two-factor', $route->uri());
        $this->assertNotSame(
            TwoFactorRequired::class,
            $route->getAction()['controller'] ?? null,
            'the package must not have replaced the host route'
        );
    }

    public function test_package_route_path_is_left_free_when_disabled(): void
    {
        $paths = collect(Route::getRoutes())
            ->filter(fn ($route) => $route->uri() === 'two-factor-required')
            ->all();

        $this->assertCount(0, $paths, 'the default package path must not be registered');
    }
}
