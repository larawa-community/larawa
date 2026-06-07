<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthentication
{
    public function __construct(private readonly Google2FA $google2fa = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function currentCode(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }

    public function verifyCode(string $secret, string $code): bool
    {
        return (bool) $this->google2fa->verifyKey($secret, preg_replace('/\s+/', '', $code), 1);
    }

    public function qrCodeSvg(User $user, string $secret): string
    {
        $company = config('app.name', 'LaraWA');
        $label = rawurlencode($company.':'.$user->email);
        $issuer = rawurlencode($company);
        $uri = "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}";
        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($uri);
    }

    /**
     * @return array<int, string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)))
            ->all();
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $code) => Hash::make($this->normalizeRecoveryCode($code)), $codes);
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);
        $codes = $user->two_factor_recovery_codes ?: [];

        foreach ($codes as $index => $hashedCode) {
            if (Hash::check($normalized, $hashedCode)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return Str::upper(str_replace(' ', '', trim($code)));
    }
}
