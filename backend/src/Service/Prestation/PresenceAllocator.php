<?php

namespace App\Service\Prestation;

/**
 * D-156 — ventilation (imputation comptable) d'une présence réelle unique entre les
 * missions d'une prestation regroupée. Fonctions pures, déterministes, sans base.
 *
 * Règle validée (« EARLIEST_END_FIRST ») : l'axe du temps est découpé selon les horaires
 * PLANIFIÉS des missions ; un segment couvert par plusieurs missions revient à celle qui
 * termine le plus tôt, puis à celle qui commence le plus tôt, puis au plus petit id.
 * Puis chaque minute RÉELLE de la présence est imputée au propriétaire du segment où elle
 * tombe :
 * - arrivée avant le premier début planifié → propriétaire du premier segment ;
 * - dépassement après la dernière fin planifiée → propriétaire du dernier segment ;
 * - minute réelle dans une interruption non planifiée entre deux segments → propriétaire
 *   du segment qui précède l'interruption (le travail se prolonge) ;
 * - départ anticipé : les segments non atteints n'imputent rien (une mission peut recevoir
 *   0 minute, explicitement).
 * La somme des parts vaut toujours exactement la durée réelle (arrondie à la minute comme
 * MissionExecutionService::resolveDuration()) : aucune minute perdue, aucune inventée,
 * aucune comptée deux fois. Ce n'est pas une preuve de présence physique exclusive dans
 * chaque salle, seulement l'imputation comptable.
 */
final class PresenceAllocator
{
    public const RULE = 'EARLIEST_END_FIRST';

    /**
     * @param list<array{id:int,start:int,end:int}> $members horaires planifiés (timestamps)
     * @return array<int,int> minutes imputées par id de mission (toutes présentes, 0 inclus)
     */
    public static function allocateMinutes(array $members, int $realStart, int $realEnd): array
    {
        $result = [];
        foreach ($members as $m) {
            $result[$m['id']] = 0;
        }
        if ($members === [] || $realEnd <= $realStart) {
            return $result;
        }

        $timeline = self::ownerTimeline($members);
        $totalMinutes = (int) round(($realEnd - $realStart) / 60);

        $seconds = array_fill_keys(array_keys($result), 0);
        $lastIndex = count($timeline) - 1;
        foreach ($timeline as $i => $segment) {
            $from = $i === 0 ? PHP_INT_MIN : $segment['from'];
            $to   = $i === $lastIndex ? PHP_INT_MAX : $timeline[$i + 1]['from'];
            $overlap = min($to, $realEnd) - max($from, $realStart);
            if ($overlap > 0) {
                $seconds[$segment['owner']] += $overlap;
            }
        }

        return self::largestRemainder($seconds, 60, $totalMinutes, self::rankOrder($members));
    }

    /**
     * Répartit un montant total (centimes) au prorata des minutes, au centime près, sans
     * aucun écart : méthode du plus grand reste, égalités départagées par l'ordre $order.
     *
     * @param array<int,int> $minutesById
     * @param list<int>      $order
     * @return array<int,int> centimes par id
     */
    public static function allocateCents(int $totalCents, array $minutesById, array $order): array
    {
        $totalMinutes = array_sum($minutesById);
        if ($totalMinutes <= 0) {
            $result = array_fill_keys(array_keys($minutesById), 0);
            if ($order !== [] && array_key_exists($order[0], $result)) {
                $result[$order[0]] = $totalCents;
            }
            return $result;
        }

        $scaled = [];
        foreach ($minutesById as $id => $minutes) {
            $scaled[$id] = $totalCents * $minutes;
        }

        return self::largestRemainder($scaled, $totalMinutes, $totalCents, $order);
    }

    /** @param list<array{start:int,end:int}> $intervals @return int minutes de l'union */
    public static function unionMinutes(array $intervals): int
    {
        usort($intervals, static fn (array $a, array $b) => $a['start'] <=> $b['start']);
        $total = 0;
        $curStart = null;
        $curEnd = null;
        foreach ($intervals as $iv) {
            if ($curEnd === null || $iv['start'] > $curEnd) {
                if ($curEnd !== null) {
                    $total += $curEnd - $curStart;
                }
                $curStart = $iv['start'];
                $curEnd = $iv['end'];
            } elseif ($iv['end'] > $curEnd) {
                $curEnd = $iv['end'];
            }
        }
        if ($curEnd !== null) {
            $total += $curEnd - $curStart;
        }

        return (int) round($total / 60);
    }

    /**
     * Ordre de départage stable (mêmes critères que la règle : fin, début, id).
     *
     * @param list<array{id:int,start:int,end:int}> $members
     * @return list<int>
     */
    public static function rankOrder(array $members): array
    {
        usort($members, static fn (array $a, array $b) => [$a['end'], $a['start'], $a['id']] <=> [$b['end'], $b['start'], $b['id']]);

        return array_map(static fn (array $m) => $m['id'], $members);
    }

    /**
     * Propriétaire de chaque segment élémentaire de l'axe planifié, segments consécutifs de
     * même propriétaire fusionnés. Une interruption (aucune mission) prolonge le segment
     * précédent.
     *
     * @param list<array{id:int,start:int,end:int}> $members
     * @return list<array{from:int,owner:int}>
     */
    private static function ownerTimeline(array $members): array
    {
        $bounds = [];
        foreach ($members as $m) {
            $bounds[] = $m['start'];
            $bounds[] = $m['end'];
        }
        $bounds = array_values(array_unique($bounds));
        sort($bounds);

        $timeline = [];
        $previousOwner = null;
        for ($i = 0, $n = count($bounds) - 1; $i < $n; $i++) {
            $from = $bounds[$i];
            $to = $bounds[$i + 1];
            $owner = null;
            $best = null;
            foreach ($members as $m) {
                if ($m['start'] <= $from && $m['end'] >= $to) {
                    $rank = [$m['end'], $m['start'], $m['id']];
                    if ($best === null || $rank < $best) {
                        $best = $rank;
                        $owner = $m['id'];
                    }
                }
            }
            $owner ??= $previousOwner;
            if ($owner === null) {
                continue;
            }
            if ($owner !== $previousOwner) {
                $timeline[] = ['from' => $from, 'owner' => $owner];
                $previousOwner = $owner;
            }
        }

        if ($timeline === []) {
            // Degenerate input (only empty planned intervals): everything to the first by rank.
            $order = self::rankOrder($members);
            $timeline[] = ['from' => PHP_INT_MIN, 'owner' => $order[0]];
        }

        return $timeline;
    }

    /**
     * Arrondit des quotients numérateur/dénominateur à des entiers dont la somme vaut
     * exactement $target (plus grand reste ; égalités : ordre $order).
     *
     * @param array<int,int> $numerators
     * @param list<int>      $order
     * @return array<int,int>
     */
    private static function largestRemainder(array $numerators, int $denominator, int $target, array $order): array
    {
        $result = [];
        $remainders = [];
        foreach ($numerators as $id => $num) {
            $result[$id] = intdiv($num, $denominator);
            $remainders[$id] = $num % $denominator;
        }

        $rank = array_flip($order);
        $ids = array_keys($numerators);
        usort($ids, static fn (int $a, int $b) => [$remainders[$b], $rank[$a] ?? PHP_INT_MAX] <=> [$remainders[$a], $rank[$b] ?? PHP_INT_MAX]);

        $deficit = $target - array_sum($result);
        $count = count($ids);
        for ($i = 0; $deficit > 0 && $count > 0; $i++, $deficit--) {
            $result[$ids[$i % $count]]++;
        }
        // Rounding the total half-up can make $target one below the floored sum's ceiling but
        // never below the floored sum itself; stay exact anyway.
        for ($i = $count - 1; $deficit < 0 && $i >= 0; $i--) {
            if ($result[$ids[$i]] > 0) {
                $result[$ids[$i]]--;
                $deficit++;
            }
        }

        return $result;
    }
}
