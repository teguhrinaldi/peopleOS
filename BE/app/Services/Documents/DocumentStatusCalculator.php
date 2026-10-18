<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Pure status rules (no database, no clock). All dates are Y-m-d business dates in the company timezone.
 *
 *  archived                                   -> ARCHIVED (manual, wins over everything)
 *  no expiry date                             -> ACTIVE
 *  expiry_date <  today                       -> EXPIRED   (the expiry date itself is the last valid day)
 *  today <= expiry_date <= today + N days     -> EXPIRING  (N = setting documents.expiring_days)
 *  expiry_date >  today + N days              -> ACTIVE
 */
final class DocumentStatusCalculator
{
    public function derive(?string $expiryDate, string $today, int $expiringDays): DocumentStatus
    {
        if ($expiryDate === null || $expiryDate === '') {
            return DocumentStatus::Active;
        }

        $days = $this->daysUntilExpiry($expiryDate, $today);

        if ($days < 0) {
            return DocumentStatus::Expired;
        }

        return $days <= max(0, $expiringDays) ? DocumentStatus::Expiring : DocumentStatus::Active;
    }

    public function resolve(bool $archived, ?string $expiryDate, string $today, int $expiringDays): DocumentStatus
    {
        return $archived ? DocumentStatus::Archived : $this->derive($expiryDate, $today, $expiringDays);
    }

    /** Whole calendar days from today to the expiry date (0 = expires today, negative = already expired). */
    public function daysUntilExpiry(string $expiryDate, string $today): int
    {
        return (int) $this->date($today)->diffInDays($this->date($expiryDate), false);
    }

    /**
     * Allowed state changes. Derived states move freely among themselves (time passing, expiry edits);
     * only an explicit archive enters ARCHIVED and only an explicit restore leaves it.
     */
    public function canTransition(DocumentStatus $from, DocumentStatus $to, bool $manual): bool
    {
        if ($from === $to) {
            return false;
        }
        if ($from === DocumentStatus::Archived || $to === DocumentStatus::Archived) {
            return $manual;
        }

        return true;
    }

    private function date(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), 'UTC');
        if ($date === false) {
            throw new InvalidArgumentException("Invalid date [{$value}].");
        }

        return $date;
    }
}
