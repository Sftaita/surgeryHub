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

    public function getFilters(): array
    {
        return [
            new TwigFilter('french_day', [$this, 'frenchDay']),
        ];
    }

    /** @param \DateTimeInterface|string $date */
    public function frenchDay($date): string
    {
        $d = $date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable((string) $date);

        return self::DAYS_FR[$d->format('l')] ?? $d->format('l');
    }
}
