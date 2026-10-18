<?php

namespace App\Support;

/**
 * Leave types store a design-system colour *token* (never a hex value). This maps each token to the
 * CSS classes used by the admin, backed by variables in resources/css/app.css (light and dark).
 */
final class LeaveColors
{
    /** token => [label, solid class, soft background + text classes] */
    public const TOKENS = [
        'blue' => ['Blue', 'bg-[var(--tone-blue)]', 'bg-[var(--tone-blue-soft)] text-[var(--tone-blue-ink)]'],
        'green' => ['Green', 'bg-[var(--tone-green)]', 'bg-[var(--tone-green-soft)] text-[var(--tone-green-ink)]'],
        'orange' => ['Orange', 'bg-[var(--tone-orange)]', 'bg-[var(--tone-orange-soft)] text-[var(--tone-orange-ink)]'],
        'red' => ['Red', 'bg-[var(--tone-red)]', 'bg-[var(--tone-red-soft)] text-[var(--tone-red-ink)]'],
        'purple' => ['Purple', 'bg-[var(--tone-purple)]', 'bg-[var(--tone-purple-soft)] text-[var(--tone-purple-ink)]'],
        'teal' => ['Teal', 'bg-[var(--tone-teal)]', 'bg-[var(--tone-teal-soft)] text-[var(--tone-teal-ink)]'],
        'gray' => ['Gray', 'bg-[var(--tone-gray)]', 'bg-[var(--tone-gray-soft)] text-[var(--tone-gray-ink)]'],
    ];

    public static function solid(?string $token): string
    {
        return (self::TOKENS[$token] ?? self::TOKENS['gray'])[1];
    }

    public static function soft(?string $token): string
    {
        return (self::TOKENS[$token] ?? self::TOKENS['gray'])[2];
    }

    /** @return array<string, string> token => label */
    public static function options(): array
    {
        return array_map(fn ($t) => $t[0], self::TOKENS);
    }
}
