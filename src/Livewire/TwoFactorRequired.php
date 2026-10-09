<?php

namespace Adeguntoro\J2FAKit\Livewire;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Jetstream\Http\Livewire\TwoFactorAuthenticationForm as JetstreamTwoFactorForm;
use Livewire\Attributes\Computed;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * The "finish setting up two factor authentication" page.
 *
 * Registered as the "2fa.required" route by J2FAKitServiceProvider, which is
 * the default target of config('j2fakit.redirect_to'). A host app can disable
 * that (setup_page.enabled = false) and mount this component on its own route
 * instead.
 *
 * Extends Jetstream's form so password confirmation, the enable/confirm/
 * regenerate actions and the "user" property all come for free. Everything the
 * page needs that Fortify does NOT ship is implemented here rather than on the
 * host's User model - a stock App\Models\User works with no edits:
 *
 *   - decrypting and Base32-validating the stored secret
 *   - decrypting the recovery codes
 *   - building the QR code SVG
 *
 * The view falls back to the copy published by `vendor:publish --tag=views`
 * (resources/views/livewire/two-factor-required.blade.php) when one exists, so
 * the page stays customizable in place.
 */
class TwoFactorRequired extends JetstreamTwoFactorForm
{
    /**
     * Clear a secret that can never be used before anything tries to render it.
     *
     * A secret that cannot be decrypted, or that decrypts to something other
     * than a Base32 string, can never be verified by an authenticator app and
     * cannot be encoded into a QR code. Treating it as corrupt here means the
     * "Generate QR Code" button can start over instead of crashing.
     *
     * Note: deliberately does NOT call the parent mount(), which would disable
     * 2FA outright whenever 'confirm' is on and the setup is unconfirmed - on
     * this page that would wipe the user's progress on every load.
     */
    public function mount(): void
    {
        $user = $this->user;

        if ($user && $user->two_factor_secret && ! $this->usableSecret($user)) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $user->refresh();
        }
    }

    /**
     * Whether the user has finished setting up two factor authentication.
     */
    #[Computed]
    public function confirmed(): bool
    {
        return ! is_null($this->user->two_factor_confirmed_at ?? null);
    }

    /**
     * The decrypted Base32 setup key, or null when 2FA is not set up yet.
     */
    #[Computed]
    public function setupKey(): ?string
    {
        return $this->usableSecret($this->user);
    }

    /**
     * The QR code as inline SVG, or null when it cannot be generated.
     */
    #[Computed]
    public function qrCodeSvg(): ?string
    {
        $secret = $this->usableSecret($this->user);

        if ($secret === null) {
            return null;
        }

        try {
            // Authenticator apps only display plain ASCII, so strip anything
            // else out of the issuer before building the otpauth:// payload.
            $issuer = preg_replace('/[^\x20-\x7E]/', '', (string) config('app.name')) ?: 'Laravel';

            $url = app(Google2FA::class)->getQRCodeUrl(
                $issuer,
                (string) ($this->user->email ?? ''),
                $secret
            );

            return (new Writer(
                new ImageRenderer(
                    new RendererStyle(200),
                    new SvgImageBackEnd()
                )
            ))->writeString($url, 'UTF-8');
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The decrypted recovery codes, or an empty list when none are usable.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function recoveryCodes(): array
    {
        $stored = $this->user->two_factor_recovery_codes ?? null;

        if (blank($stored)) {
            return [];
        }

        try {
            return json_decode(decrypt($stored), true) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Where the user lands once 2FA is confirmed.
     *
     * Resolved through Route::has() so a host app without a route of that name
     * degrades to the app root instead of throwing RouteNotFoundException at
     * the exact moment the user has just finished scanning the QR code.
     */
    #[Computed]
    public function continueUrl(): string
    {
        $route = config('j2fakit.after_setup_route', 'dashboard');

        if (is_string($route) && $route !== '' && Route::has($route)) {
            return route($route);
        }

        return url('/');
    }

    /**
     * Confirm the code and carry on to the intended (or default) page.
     */
    public function confirmTwoFactorAuthentication(ConfirmTwoFactorAuthentication $confirm)
    {
        parent::confirmTwoFactorAuthentication($confirm);

        $this->dispatch('twoFactorConfirmed');

        // First argument is the fallback used when no intended URL was stashed
        // by the gate, which is the normal case for a user who just logged in.
        $this->redirectIntended($this->continueUrl);
    }

    /**
     * Use the app-published view when present, otherwise the packaged copy.
     */
    public function render()
    {
        return view('livewire.two-factor-required')->layout('layouts.app');
        //$view = view(
        //    view()->exists('livewire.two-factor-required')
        //        ? 'livewire.two-factor-required'
        //        : 'j2fakit::two-factor-required'
        // );

        // A route-mounted component renders inside a layout. Without this
        // Livewire falls back to config('livewire.layout'), whose default is
        // "components.layouts.app" - a path Jetstream never publishes - and the
        // page dies with "layout view not found: [components.layouts.app]".
        // Jetstream's layouts/app.blade.php is {{ $slot }}, which is exactly
        // what ->layout() feeds.
        //
        // The explicit fallback matters: mergeConfigFrom() is a SHALLOW merge,
        // so a config/j2fakit.php published before this key existed replaces the
        // whole setup_page array and hides it. Arr::get still returns the
        // default for a missing key, while an explicit null (defer to
        // config('livewire.layout')) is preserved.
        // $layout = config('j2fakit.setup_page.layout', 'layouts.app');

        // return is_string($layout) && $layout !== ''
        //    ? $view->layout($layout)
        //    : $view;
    }

    /**
     * Decrypt the stored secret and return it only when it is valid Base32.
     *
     * A secret is only usable by an authenticator app if it is a valid Base32
     * string. Undecryptable ciphertext, raw binary and anything else is
     * reported as "no secret" so callers never build a QR code from garbage.
     */
    protected function usableSecret(object $user = null): ?string
    {
        $stored = $user->two_factor_secret ?? null;

        if (blank($stored)) {
            return null;
        }

        try {
            $secret = decrypt($stored);
        } catch (Throwable) {
            return null;
        }

        return is_string($secret) && preg_match('/^[A-Z2-7]+=*$/', $secret) === 1
            ? $secret
            : null;
    }
}
