<?php

namespace Adeguntoro\J2FAKit\Tests\Feature;

use Adeguntoro\J2FAKit\Tests\Support\User;
use Adeguntoro\J2FAKit\Tests\TestCase;
use RuntimeException;

class GateTest extends TestCase
{
    // ------------------------------------------------------------------
    // Guest (not logged in)
    // ------------------------------------------------------------------

    public function test_guest_html_is_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertAuthenticatedAsGuestRedirect($response);
    }

    public function test_guest_json_gets_401(): void
    {
        $this->getJson('/dashboard')->assertStatus(401);
    }

    public function test_xhr_guest_gets_401(): void
    {
        // Real XHR/fetch clients always send an Accept header alongside
        // X-Requested-With, which is what expectsJson() keys on.
        $this->get('/dashboard', [
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => '*/*',
        ])->assertStatus(401);
    }

    public function test_xhr_without_accept_header_is_treated_as_html(): void
    {
        // Degenerate client: X-Requested-With but no Accept header.
        // expectsJson() is false, so it gets the HTML redirect like any
        // other non-JSON request instead of a JSON body it cannot parse.
        $this->get('/dashboard', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(302);
    }

    public function test_guest_cannot_reach_non_whitelisted_route(): void
    {
        $this->get('/changelog-alias')->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // Logged in, 2FA not confirmed
    // ------------------------------------------------------------------

    public function test_unconfirmed_user_html_is_redirected_to_2fa_setup(): void
    {
        $user = new User(['email' => 'a@example.com']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('2fa.required'));
    }

    public function test_unconfirmed_user_json_gets_403(): void
    {
        $user = new User(['email' => 'b@example.com']);

        $this->actingAs($user)
            ->getJson('/dashboard')
            ->assertStatus(403)
            ->assertJson(['message' => 'Two factor authentication has not been confirmed.']);
    }

    public function test_missing_redirect_route_throws_instead_of_looping(): void
    {
        // The old behaviour redirected an already-authenticated user to the
        // login route, which has "guest" middleware and sends them straight
        // back - an infinite redirect loop with nothing in the log. That is a
        // wiring mistake, so the gate now refuses to guess.
        $this->withoutExceptionHandling();

        config()->set('j2fakit.redirect_to', 'route.that.does.not.exist');

        $user = new User(['email' => 'c@example.com']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("config('j2fakit.redirect_to')");
        $this->expectExceptionMessage('route.that.does.not.exist');

        $this->actingAs($user)->get('/dashboard');
    }

    public function test_non_string_redirect_route_also_throws(): void
    {
        $this->withoutExceptionHandling();

        config()->set('j2fakit.redirect_to', null);

        $user = new User(['email' => 'c2@example.com']);

        $this->expectException(RuntimeException::class);

        $this->actingAs($user)->get('/dashboard');
    }

    public function test_guest_login_fallback_is_unaffected_by_missing_redirect_route(): void
    {
        // Guests never reach the guard: for them, login is the correct
        // destination, so that path must keep working unchanged.
        config()->set('j2fakit.redirect_to', 'route.that.does.not.exist');

        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_json_unconfirmed_response_is_unaffected_by_missing_redirect_route(): void
    {
        // The JSON branch never redirects, so a missing target cannot loop
        // it - it keeps returning 403.
        config()->set('j2fakit.redirect_to', 'route.that.does.not.exist');

        $user = new User(['email' => 'c3@example.com']);

        $this->actingAs($user)
            ->getJson('/dashboard')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Logged in, 2FA confirmed
    // ------------------------------------------------------------------

    public function test_confirmed_user_passes(): void
    {
        $user = new User([
            'email' => 'd@example.com',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('dashboard');
    }

    public function test_stored_secret_counts_as_enrolled_when_confirmation_not_required(): void
    {
        config()->set('j2fakit.require_confirmation', false);

        $user = new User([
            'email' => 'e@example.com',
            'two_factor_secret' => 'secret-value',
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('dashboard');
    }

    public function test_stored_secret_is_not_enough_when_confirmation_is_required(): void
    {
        $user = new User([
            'email' => 'f@example.com',
            'two_factor_secret' => 'secret-value',
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('2fa.required'));
    }

    // ------------------------------------------------------------------
    // Alias resolution (middleware j2fakit / j2fakit.exempt)
    // ------------------------------------------------------------------

    public function test_j2fakit_alias_blocks_guest_and_passes_confirmed_user(): void
    {
        $this->get('/via-alias')->assertRedirect(route('login'));

        $user = new User([
            'email' => 'g@example.com',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)->get('/via-alias')->assertOk()->assertSee('via-alias');
    }

    public function test_exempt_alias_lets_guest_through(): void
    {
        $this->get('/support')->assertOk()->assertSee('support');
    }

    public function test_without_middleware_bypasses_the_gate(): void
    {
        $this->get('/changelog')->assertOk()->assertSee('changelog');
    }

    // ------------------------------------------------------------------
    // auth + exempt combinations
    // ------------------------------------------------------------------

    public function test_auth_plus_exempt_requires_login_but_not_2fa(): void
    {
        // Guest: still blocked by auth.
        $this->get('/settings')->assertRedirect(route('login'));

        // Logged in without 2FA: allowed, because the gate steps aside.
        $user = new User(['email' => 'h@example.com']);
        $this->actingAs($user)->get('/settings')->assertOk()->assertSee('settings');
    }

    public function test_whitelisted_route_stays_private_with_auth(): void
    {
        // profile.show is whitelisted by default config, but declares auth.
        $this->get('/profile')->assertRedirect(route('login'));

        $user = new User(['email' => 'i@example.com']);
        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('profile');
    }

    // ------------------------------------------------------------------
    // Whitelist: route names and paths
    // ------------------------------------------------------------------

    public function test_route_name_whitelist_passes_guest(): void
    {
        config()->set('j2fakit.whitelist', array_merge(
            (array) config('j2fakit.whitelist'),
            ['changelog.alias']
        ));

        $this->get('/changelog-alias')->assertOk()->assertSee('changelog-alias');
    }

    public function test_route_name_whitelist_wildcard_passes_guest(): void
    {
        config()->set('j2fakit.whitelist', array_merge(
            (array) config('j2fakit.whitelist'),
            ['changelog.*']
        ));

        $this->get('/changelog-alias')->assertOk();
    }

    public function test_path_whitelist_passes_guest(): void
    {
        // 'livewire/*' is a default whitelist_paths entry.
        $this->get('/livewire/counter')->assertOk()->assertSee('livewire');
    }

    public function test_garbage_whitelist_entries_are_skipped(): void
    {
        config()->set('j2fakit.whitelist', array_merge(
            (array) config('j2fakit.whitelist'),
            ['', null, 0, false]
        ));

        $this->get('/changelog-alias')->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // Kill switch
    // ------------------------------------------------------------------

    public function test_disabling_the_gate_lets_guests_through(): void
    {
        config()->set('j2fakit.enabled', false);

        $this->get('/dashboard')->assertOk()->assertSee('dashboard');
    }

    // ------------------------------------------------------------------
    // Login route fallback
    // ------------------------------------------------------------------

    public function test_missing_login_route_falls_back_to_login_path(): void
    {
        config()->set('j2fakit.login_route', 'no.such.route');
        config()->set('j2fakit.login_path', '/login');

        $this->get('/dashboard')->assertRedirect(url('/login'));
    }

    public function test_auth_lifecycle_routes_are_never_blocked(): void
    {
        // login itself must stay reachable for guests even though it is in
        // the web group with the gate appended.
        $this->get('/login')->assertOk()->assertSee('login-page');

        $this->get('/two-factor-required')->assertOk()->assertSee('2fa-setup-page');
    }

    // ------------------------------------------------------------------

    private function assertAuthenticatedAsGuestRedirect($response): void
    {
        $response->assertStatus(302);
        $this->assertGuest();
    }
}
