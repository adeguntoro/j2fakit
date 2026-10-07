{{--
    Box width is controlled by `max-w-*` on the card below.
      max-w-xs 20rem (320px)   max-w-sm 24rem (384px)
      max-w-md 28rem (448px)   max-w-lg 32rem (512px)
      max-w-xl 36rem (576px)
    Card padding is `p-6 sm:p-8`.
    The QR code size is NOT set here - it lives in the component at
    App\Livewire\TwoFactorRequired::qrCodeSvg() via `new RendererStyle(200)`.
--}}

<div class="flex items-start justify-center px-4 py-10 sm:py-16">
    <div class="w-full max-w-md bg-white shadow-lg rounded-sm p-6 sm:p-8">

        <!-- Header -->
        <div class="text-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Two-Factor Authentication Required</h2>
            <p class="text-gray-600 mt-2">Please scan the QR code and enter the verification code to continue.</p>
        </div>

        @unless ($this->confirmed)
            <!-- QR Code Section -->
            <div class="mb-6">
                <div class="flex justify-center mb-4">
                    @if ($this->qrCodeSvg)
                        <div class="bg-white p-2">{!! $this->qrCodeSvg !!}</div>
                    @else
                        <div class="text-center py-8">
                            <p class="text-gray-500 mb-4">Setting up Two-Factor Authentication...</p>
                            <x-confirms-password wire:then="enableTwoFactorAuthentication">
                                <x-button type="button" wire:loading.attr="disabled">
                                    {{ __('Generate QR Code') }}
                                </x-button>
                            </x-confirms-password>
                        </div>
                    @endif
                </div>

                @if ($this->setupKey)
                    <p class="text-sm text-gray-600 text-center mb-4">
                        Scan this QR code with your authenticator app (Google Authenticator, Authy, etc.)
                    </p>

                    <!-- Manual Entry Key -->
                    <div class="bg-gray-50 p-3 rounded mb-4">
                        <p class="text-xs text-gray-500 mb-1 text-center">Or enter this code manually:</p>
                        <code class="block text-sm font-mono text-center break-all">{{ $this->setupKey }}</code>
                    </div>
                @endif
            </div>

            <!-- Verification Code Input -->
            @php($confirmableId = md5('confirmTwoFactorAuthentication'))

            <form wire:submit="confirmTwoFactorAuthentication"
                  x-on:submit="$wire.startConfirmingPassword('{{ $confirmableId }}')">
                <div class="mb-4">
                    <x-label for="code" class="text-center" value="{{ __('Verification Code') }}" />
                    <x-input id="code" type="text" class="mt-1 block w-full text-center text-lg tracking-widest"
                             maxlength="6" wire:model="code"
                             placeholder="123456" autocomplete="one-time-code" />
                    <x-input-error for="code" class="mt-2" />
                </div>

                <x-confirms-password wire:then="confirmTwoFactorAuthentication">
                    <x-button type="submit" class="w-full justify-center">
                        {{ __('Verify & Continue') }}
                    </x-button>
                </x-confirms-password>
            </form>
        @endunless

        <!-- Backup Codes (Show after confirmation) -->
        @if ($this->confirmed)
            <div class="p-4 bg-green-50 border border-green-200 rounded">
                <h3 class="text-green-800 font-semibold mb-2">✅ 2FA Enabled!</h3>
                <p class="text-sm text-green-700 mb-3">Save these backup codes in a safe place. You can use them to access your account if you lose your device.</p>

                <div class="bg-white p-3 rounded font-mono text-sm grid grid-cols-2 gap-2">
                    @foreach ($this->user->twoFactorRecoveryCodes() as $recoveryCode)
                        <div>{{ $recoveryCode }}</div>
                    @endforeach
                </div>

                <x-confirms-password wire:then="regenerateRecoveryCodes">
                    <x-button type="button" class="mt-3 w-full" wire:loading.attr="disabled">
                        {{ __('Regenerate Codes') }}
                    </x-button>
                </x-confirms-password>

                <a href="{{ route('dashboard') }}" class="block text-center mt-3 text-sm text-gray-600 hover:text-gray-900">
                    Continue to Dashboard →
                </a>
            </div>
        @endif
    </div>
</div>

@script
    <script>
        Livewire.on('twoFactorConfirmed', () => {
            window.location.href = @js(route('dashboard'));
        });
    </script>
@endscript