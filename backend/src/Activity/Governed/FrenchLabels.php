<?php

namespace App\Activity\Governed;

/** H5b (D-132) — French wording of the enum values the journal sentences mention. */
final class FrenchLabels
{
    public const WEEKDAYS = ['1' => 'lundi', '2' => 'mardi', '3' => 'mercredi', '4' => 'jeudi', '5' => 'vendredi', '6' => 'samedi', '7' => 'dimanche'];
    public const PERIODS = ['MATIN' => 'matin', 'APRES_MIDI' => 'après-midi', 'JOURNEE' => 'journée'];
    public const MISSION_TYPES = ['BLOCK' => 'bloc opératoire', 'CONSULTATION' => 'consultation'];
    public const FREQUENCIES = ['WEEKLY' => 'hebdomadaire', 'MONTHLY' => 'mensuelle'];
    public const EXCEPTION_TYPES = [
        'CANCELLED' => 'occurrence annulée', 'MOVED' => 'occurrence déplacée',
        'INSTRUMENTIST_OVERRIDE' => 'instrumentiste remplacée', 'TIME_OVERRIDE' => 'horaire modifié',
    ];
    public const ALERT_TYPES = [
        'SURGEON_ABSENCE' => 'absence chirurgien', 'INSTRUMENTIST_ABSENCE' => 'absence instrumentiste',
        'SURGEON_CONFLICT' => 'conflit chirurgien', 'INSTRUMENTIST_CONFLICT' => 'conflit instrumentiste',
        'REASSIGNMENT_REQUIRED' => 'réassignation requise', 'OCCURRENCE_CANCELLED' => 'occurrence annulée',
        'INSTRUMENTIST_INACTIVE' => 'instrumentiste inactive',
    ];

    /** "1, 5" or [1, 5] → "lundi et vendredi" */
    public static function weekdays(mixed $value): string
    {
        $days = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string) $value)), static fn ($d) => $d !== '');
        $names = array_values(array_map(static fn ($d) => self::WEEKDAYS[(string) $d] ?? (string) $d, $days));

        return match (count($names)) {
            0 => '—',
            1 => $names[0],
            default => implode(', ', array_slice($names, 0, -1)) . ' et ' . end($names),
        };
    }

    public static function date(?\DateTimeInterface $d): string
    {
        return $d?->format('d/m/Y') ?? '—';
    }

    /** "2027-06-11" → "11/06/2027" (values as normalized in a GovernedChange) */
    public static function isoDate(mixed $v): string
    {
        if (!is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) !== 1) {
            return $v === null ? '—' : (string) $v;
        }

        return sprintf('%s/%s/%s', $m[3], $m[2], $m[1]);
    }
}
