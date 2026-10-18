<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Supported UI languages and locale negotiation. English is the fallback for every missing key. */
class Locales
{
    public function __construct(private readonly Settings $settings) {}

    /** @return array<string, string> code => native name */
    public static function supported(): array
    {
        return config('peopleos.locales', ['en' => 'English', 'id' => 'Bahasa Indonesia']);
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::supported());
    }

    public function resolve(Request $request): string
    {
        $user = $request->user();
        if ($user && self::isSupported($user->locale)) {
            return $user->locale;
        }

        foreach ([$request->header('X-Locale'), ...$request->getLanguages()] as $candidate) {
            $code = strtolower(substr((string) $candidate, 0, 2));
            if (self::isSupported($code)) {
                return $code;
            }
        }

        $default = $this->companyDefault();

        return self::isSupported($default) ? $default : config('app.fallback_locale', 'en');
    }

    private function companyDefault(): ?string
    {
        try {
            return $this->settings->string('general.locale');
        } catch (\Throwable) {
            return null; // before the first migration/company exists
        }
    }
}
