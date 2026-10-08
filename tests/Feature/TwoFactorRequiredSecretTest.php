<?php

namespace Adeguntoro\J2FAKit\Tests\Feature;

use Adeguntoro\J2FAKit\Livewire\TwoFactorRequired;
use Adeguntoro\J2FAKit\Tests\TestCase;
use ReflectionMethod;
use stdClass;

/**
 * The component's "is this stored secret usable?" logic.
 *
 * These helpers deliberately live on the component instead of the host's User
 * model (so a stock App\Models\User works), which makes them worth pinning
 * down on their own: every one of these branches either lets a QR code be
 * generated or stops a crash on one that can never work.
 */
class TwoFactorRequiredSecretTest extends TestCase
{
    protected function usableSecret(?string $stored): ?string
    {
        $user = new stdClass();
        $user->two_factor_secret = $stored;

        $method = new ReflectionMethod(TwoFactorRequired::class, 'usableSecret');

        return $method->invoke(new TwoFactorRequired(), $user);
    }

    public function test_missing_secret_is_not_usable(): void
    {
        $this->assertNull($this->usableSecret(null), 'no secret stored yet');
        $this->assertNull($this->usableSecret(''), 'empty string is not a secret');
    }

    public function test_valid_base32_secret_comes_back_decrypted(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';

        $this->assertSame($secret, $this->usableSecret(encrypt($secret)));
    }

    public function test_undecryptable_value_is_not_usable(): void
    {
        // Never went through encrypt(), so decrypt() rejects it outright.
        $this->assertNull($this->usableSecret('not-encrypted-at-all'));
    }

    public function test_decrypted_non_base32_value_is_not_usable(): void
    {
        // Round-trips through encrypt()/decrypt() cleanly, but an
        // authenticator app could never parse it into a QR code.
        $this->assertNull($this->usableSecret(encrypt('not a base32 secret!')));
    }
}
