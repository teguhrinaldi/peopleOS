<?php

namespace App\Enums;

/**
 * ACTIVE / EXPIRING / EXPIRED are derived from the expiry date (see DocumentStatusCalculator);
 * ARCHIVED is a manual state that is restorable to the derived one.
 */
enum DocumentStatus: string
{
    case Active = 'ACTIVE';
    case Expiring = 'EXPIRING';
    case Expired = 'EXPIRED';
    case Archived = 'ARCHIVED';

    public function isDerived(): bool
    {
        return $this !== self::Archived;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
