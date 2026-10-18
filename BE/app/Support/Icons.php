<?php

namespace App\Support;

/**
 * Inline SVG icons from the bundled Lucide set (ISC licence, see docs/assets.md).
 * Files live in resources/svg/lucide; only the inner shapes are returned so Blade can wrap them.
 */
final class Icons
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function inner(string $name): string
    {
        $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name)) ?? '';

        return self::$cache[$name] ??= self::load($name);
    }

    private static function load(string $name): string
    {
        $path = resource_path("svg/lucide/{$name}.svg");
        if ($name === '' || ! is_file($path)) {
            return '<circle cx="12" cy="12" r="9"/>';
        }

        $svg = (string) file_get_contents($path);
        $svg = preg_replace('/<!--.*?-->/s', '', $svg) ?? $svg;
        $svg = preg_replace('/^\s*<svg[^>]*>/s', '', $svg) ?? $svg;

        return trim(str_replace('</svg>', '', $svg));
    }
}
