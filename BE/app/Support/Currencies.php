<?php

namespace App\Support;

use ResourceBundle;

/** ISO 4217 currency codes and English names, from the ICU data bundled with PHP's intl extension. */
final class Currencies
{
    /** Used only when intl is unavailable. */
    private const FALLBACK = [
        'IDR' => 'Indonesian Rupiah', 'USD' => 'US Dollar', 'EUR' => 'Euro', 'SGD' => 'Singapore Dollar', 'MYR' => 'Malaysian Ringgit',
        'AUD' => 'Australian Dollar', 'GBP' => 'British Pound', 'JPY' => 'Japanese Yen', 'PHP' => 'Philippine Peso', 'THB' => 'Thai Baht',
        'VND' => 'Vietnamese Dong', 'INR' => 'Indian Rupee', 'CNY' => 'Chinese Yuan',
    ];

    /** @var array<string, string>|null */
    private static ?array $list = null;

    /** @return array<string, string> code => "CODE – Name", sorted by code */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $code => $name) {
            $out[$code] = "{$code} – {$name}";
        }

        return $out;
    }

    public static function isValid(string $code): bool
    {
        return array_key_exists($code, self::all());
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$list !== null) {
            return self::$list;
        }

        $list = [];
        $bundle = class_exists(ResourceBundle::class) ? ResourceBundle::create('en', 'ICUDATA-curr') : null;
        if ($bundle) {
            foreach ($bundle['Currencies'] ?? [] as $code => $info) {
                // Three-letter codes only; ICU marks some (not all) historic currencies with a year range in the name.
                $name = (string) ($info[1] ?? $code);
                if (preg_match('/^[A-Z]{3}$/', (string) $code) && ! preg_match('/\(\d{4}/', $name)) {
                    $list[$code] = $name;
                }
            }
        }
        $list = $list ?: self::FALLBACK;
        ksort($list);

        return self::$list = $list;
    }
}
