<?php

namespace App\Services;

use App\Services\Plugins\PluginManager;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class LocaleResolver
{
    public const COOKIE = 'dashboard_locale';

    public function __construct(private PluginManager $plugins) {}

    public function apply(Request $request): string
    {
        $this->plugins->bootEnabled();

        $locale = $this->resolve($request);

        app()->setLocale($locale);

        if (class_exists(\Locale::class)) {
            \Locale::setDefault($locale);
        }

        return $locale;
    }

    public function resolve(Request $request): string
    {
        $available = array_keys($this->availableLocales());
        $candidates = [
            $request->user()?->dashboard_locale,
            $request->hasSession() ? $request->session()->get(self::COOKIE) : null,
            $request->cookies->get(self::COOKIE),
            $this->decryptedCookieLocale($request),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        $preferred = $request->getPreferredLanguage($available);

        return is_string($preferred) && in_array($preferred, $available, true) ? $preferred : 'en';
    }

    public function availableLocales(): array
    {
        return $this->plugins->availableLocales();
    }

    private function decryptedCookieLocale(Request $request): ?string
    {
        $value = $request->cookies->get(self::COOKIE);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }

        return CookieValuePrefix::validate(self::COOKIE, $decrypted, Crypt::getAllKeys());
    }
}
