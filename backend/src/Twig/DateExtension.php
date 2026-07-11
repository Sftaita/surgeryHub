<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * PHP's date("l") is locale-independent and always returns English day names
 * ("Tuesday") regardless of app locale. This filter centralizes the French
 * translation so it isn't duplicated across every PDF/email template.
 */
class DateExtension extends AbstractExtension
{
    private const DAYS_FR = [
        'Monday'    => 'Lundi',
        'Tuesday'   => 'Mardi',
        'Wednesday' => 'Mercredi',
        'Thursday'  => 'Jeudi',
        'Friday'    => 'Vendredi',
        'Saturday'  => 'Samedi',
        'Sunday'    => 'Dimanche',
    ];

    private const MONTHS_FR_SHORT = [
        1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin',
        7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
    ];

    private const MONTHS_FR_LONG = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
        7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    public function getFilters(): array
    {
        return [
            new TwigFilter('french_day', [$this, 'frenchDay']),
            new TwigFilter('french_month_short', [$this, 'frenchMonthShort']),
            new TwigFilter('french_month_long', [$this, 'frenchMonthLong']),
        ];
    }

    /** @param \DateTimeInterface|string $date */
    public function frenchDay($date): string
    {
        $d = $date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable((string) $date);

        return self::DAYS_FR[$d->format('l')] ?? $d->format('l');
    }

    /** @param \DateTimeInterface|string $date */
    public function frenchMonthShort($date): string
    {
        $d = $date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable((string) $date);

        return self::MONTHS_FR_SHORT[(int) $d->format('n')] ?? $d->format('M');
    }

    /**
     * Full month name, capitalized — e.g. "Septembre". Used where an abbreviation would read
     * as too casual (email subjects), unlike frenchMonthShort()'s inline date-pill usage.
     *
     * @param \DateTimeInterface|string $date
     */
    public function frenchMonthLong($date): string
    {
        $d = $date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable((string) $date);

        return self::MONTHS_FR_LONG[(int) $d->format('n')] ?? $d->format('F');
    }
}
