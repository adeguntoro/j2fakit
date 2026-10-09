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

    public function test_layout_defaults_to_the_view_jetstream_actually_publishes(): void
    {
        // Jetstream publishes layouts/app.blade.php ({{ $slot }}), never
        // components/layouts/app.blade.php, which is Livewire's default.
        $this->assertSame('layouts.app', config('j2fakit.setup_page.layout'));
    }

    public function test_render_applies_the_configured_layout(): void
    {
        $view = (new TwoFactorRequired())->render();

        $this->assertNotNull($view->layoutConfig, 'render() must set a layout');
        $this->assertSame(
            'layouts.app',
            $view->layoutConfig->view,
            'without this the page dies with "layout view not found"'
        );
        $this->assertSame('component', $view->layoutConfig->type, 'Jetstream layouts take {{ $slot }}');
    }

    public function test_layout_survives_a_published_config_missing_the_key(): void
    {
        // mergeConfigFrom() is a shallow array_merge, so a config/j2fakit.php
        // published BEFORE 'layout' existed replaces the whole setup_page array
        // and hides the key. The package must still pick a working layout.
        config()->set('j2fakit.setup_page', [
            'enabled' => true,
            'path' => '/two-factor-required',
            'name' => '2fa.required',
            'middleware' => ['web', 'auth'],
        ]);

        $view = (new TwoFactorRequired())->render();

        $this->assertSame(
            'layouts.app',
            $view->layoutConfig?->view,
            'a stale published config must not reintroduce the layout error'
        );
    }

    public function test_explicit_null_layout_defers_to_livewire_config(): void
    {
        config()->set('j2fakit.setup_page.layout', null);

        $view = (new TwoFactorRequired())->render();

        $this->assertNull(
            $view->layoutConfig,
            'an explicit null must hand the choice back to config(livewire.layout)'
        );
    }
}
