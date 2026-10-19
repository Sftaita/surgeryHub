<?php

namespace App\Service\Prestation;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;

/**
 * D-156 — LA règle de regroupement multi-salles, en fonctions pures (aucun accès base),
 * partagée par l'encodage, les conflits, les statistiques, les rappels et la finance.
 * Jamais recopiée ailleurs, jamais côté client.
 *
 * Deux missions forment une « double salle » (même prestation de travail) quand elles sont
 * toutes deux de type BLOC, ont le même instrumentiste, le même chirurgien, le même site,
 * la même date locale de début (Europe/Brussels) et un chevauchement STRICT (se toucher à
 * une heure de transition n'est pas se chevaucher). Le groupe d'une mission est la
 * composante connexe de chevauchement (chevauchements transitifs : A∩B, B∩C ⇒ {A,B,C}).
 *
 * Le regroupement ne dépend jamais de l'origine de la mission (génération Planning V2,
 * création manuelle, ajout post-déploiement, demande chirurgien, reprise de salle,
 * déclaration) ni d'un rattachement à une PlanningVersion : il est recalculé à chaque
 * lecture depuis les missions opérationnelles réelles — rien n'est figé à l'attribution.
 */
final class PrestationGrouping
{
    /**
     * Missions effectivement attribuées dont le travail est (ou sera) encodé. Exclues :
     * DRAFT (non publiée), OPEN (non attribuée), CANCELLED, REJECTED.
     */
    public const GROUPABLE_STATUSES = [
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
        MissionStatus::DECLARED,
        MissionStatus::SUBMITTED,
        MissionStatus::VALIDATED,
        MissionStatus::CLOSED,
    ];

    public static function isGroupable(Mission $mission): bool
    {
        return $mission->getInstrumentist() !== null
            && $mission->getType() === MissionType::BLOCK
            && in_array($mission->getStatus(), self::GROUPABLE_STATUSES, true)
            && $mission->getStartAt() !== null
            && $mission->getEndAt() !== null;
    }

    /** Date locale de début (Y-m-d, Europe/Brussels) — la « date de prestation ». */
    public static function localDate(Mission $mission): string
    {
        return $mission->getStartAt()
            ->setTimezone(new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE))
            ->format('Y-m-d');
    }

    /**
     * Même contexte « double salle », indépendamment du statut (utilisé aussi par la
     * détection de conflits sur des brouillons) : BLOC des deux côtés, même instrumentiste
     * renseigné, même chirurgien, même site, même date locale.
     */
    public static function sharesMultiRoomContext(Mission $a, Mission $b): bool
    {
        return $a->getType() === MissionType::BLOCK
            && $b->getType() === MissionType::BLOCK
            && $a->getInstrumentist() !== null
            && $a->getInstrumentist()->getId() === $b->getInstrumentist()?->getId()
            && $a->getSurgeon()?->getId() === $b->getSurgeon()?->getId()
            && $a->getSite()?->getId() === $b->getSite()?->getId()
            && $a->getStartAt() !== null && $b->getStartAt() !== null
            && self::localDate($a) === self::localDate($b);
    }

    public static function overlapStrictly(Mission $a, Mission $b): bool
    {
        return $a->getStartAt() < $b->getEndAt() && $b->getStartAt() < $a->getEndAt();
    }

    /** Paire « double salle » légitime : même contexte ET chevauchement strict. */
    public static function isMultiRoomPair(Mission $a, Mission $b): bool
    {
        return self::sharesMultiRoomContext($a, $b) && self::overlapStrictly($a, $b);
    }

    /**
     * Le chevauchement entre une mission existante de $instrumentist et un créneau cible
     * (mission à attribuer, à déplacer, ligne de planning) est-il une « double salle »
     * légitime plutôt qu'un conflit ? Même règle que isMultiRoomPair(), pour un créneau
     * qui n'est pas (encore) une mission attribuée à cette personne. Sans chirurgien ou type
     * connu pour le créneau, jamais (le conflit reste signalé).
     */
    public static function isMultiRoomSlot(
        Mission $existing,
        User $instrumentist,
        ?User $surgeon,
        ?Hospital $site,
        ?MissionType $type,
        \DateTimeImmutable $startAt,
        \DateTimeImmutable $endAt,
    ): bool {
        if ($surgeon === null || $site === null || $type !== MissionType::BLOCK) {
            return false;
        }
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);

        return $existing->getType() === MissionType::BLOCK
            && $existing->getInstrumentist()?->getId() === $instrumentist->getId()
            && $existing->getSurgeon()?->getId() === $surgeon->getId()
            && $existing->getSite()?->getId() === $site->getId()
            && $existing->getStartAt() !== null && $existing->getEndAt() !== null
            && self::localDate($existing) === $startAt->setTimezone($tz)->format('Y-m-d')
            && $existing->getStartAt() < $endAt && $startAt < $existing->getEndAt();
    }

    /**
     * Partitionne des missions en prestations : chaque mission regroupable rejoint la
     * composante de chevauchement de son contexte ; toute autre mission est seule.
     * Chaque groupe est trié (début, fin, id) ; l'ordre des groupes suit leur première mission.
     *
     * @param iterable<Mission> $missions
     * @return list<list<Mission>>
     */
    public static function components(iterable $missions): array
    {
        $byContext = [];
        $groups = [];
        $seen = [];
        foreach ($missions as $mission) {
            $oid = spl_object_id($mission);
            if (isset($seen[$oid])) {
                continue;
            }
            $seen[$oid] = true;
            if (!self::isGroupable($mission)) {
                $groups[] = [$mission];
                continue;
            }
            $byContext[self::contextKey($mission)][] = $mission;
        }

        foreach ($byContext as $contextMissions) {
            self::sort($contextMissions);
            $current = [];
            $currentEnd = null;
            foreach ($contextMissions as $mission) {
                // Strict overlap with the running component (touching at a boundary ≠ overlap).
                if ($current !== [] && $mission->getStartAt() < $currentEnd) {
                    $current[] = $mission;
                    if ($mission->getEndAt() > $currentEnd) {
                        $currentEnd = $mission->getEndAt();
                    }
                    continue;
                }
                if ($current !== []) {
                    $groups[] = $current;
                }
                $current = [$mission];
                $currentEnd = $mission->getEndAt();
            }
            if ($current !== []) {
                $groups[] = $current;
            }
        }

        usort($groups, static fn (array $a, array $b) => self::compare($a[0], $b[0]));

        return $groups;
    }

    /** instrumentiste|chirurgien|site|date locale — le contexte hors chevauchement. */
    public static function contextKey(Mission $mission): string
    {
        return sprintf('%d|%d|%d|%s',
            (int) $mission->getInstrumentist()?->getId(),
            (int) $mission->getSurgeon()?->getId(),
            (int) $mission->getSite()?->getId(),
            self::localDate($mission),
        );
    }

    /**
     * Clé stable d'un groupe (dérivée de sa composition, jamais persistée).
     *
     * @param list<Mission> $group
     */
    public static function key(array $group): string
    {
        return 'p' . implode('-', self::ids($group));
    }

    /**
     * @param list<Mission> $group
     * @return list<int> ids triés
     */
    public static function ids(array $group): array
    {
        $ids = array_map(static fn (Mission $m) => (int) $m->getId(), $group);
        sort($ids);

        return $ids;
    }

    /** @param list<Mission> $missions */
    public static function sort(array &$missions): void
    {
        usort($missions, [self::class, 'compare']);
    }

    public static function compare(Mission $a, Mission $b): int
    {
        return [$a->getStartAt(), $a->getEndAt(), (int) $a->getId()] <=> [$b->getStartAt(), $b->getEndAt(), (int) $b->getId()];
    }

    /**
     * Union des horaires planifiés (minutes) — jamais la somme brute des durées, jamais
     * « dernière fin − premier début » quand il existe une interruption.
     *
     * @param list<Mission> $missions
     */
    public static function plannedUnionMinutes(array $missions): int
    {
        return PresenceAllocator::unionMinutes(array_map(static fn (Mission $m) => [
            'start' => $m->getStartAt()->getTimestamp(),
            'end'   => $m->getEndAt()->getTimestamp(),
        ], $missions));
    }

    /**
     * @param list<Mission> $missions
     * @return list<array{id:int,start:int,end:int}> entrée de PresenceAllocator
     */
    public static function allocationMembers(array $missions): array
    {
        return array_map(static fn (Mission $m) => [
            'id'    => (int) $m->getId(),
            'start' => $m->getStartAt()->getTimestamp(),
            'end'   => $m->getEndAt()->getTimestamp(),
        ], $missions);
    }

    /**
     * D-156 — informative only (no validation, no status change): marks each Planning V2
     * preview line that forms, with other lines of the same person, one multi-room
     * prestation (same rule as isMultiRoomPair(), on preview lines: BLOC, same
     * instrumentist, surgeon, site and date, strict overlap, transitive). Sets
     * `multiRoomCount` to the size of the group (null when alone). SKIPPED and CONFLICT
     * lines never join a group. Idempotent: recomputed from scratch on every call.
     *
     * @param array<int, array<string, mixed>> &$lines
     */
    public static function annotatePreviewLines(array &$lines): void
    {
        $buckets = [];
        foreach ($lines as $i => $line) {
            $lines[$i]['multiRoomCount'] = null;
            if (($line['instrumentistId'] ?? null) === null
                || ($line['missionType'] ?? null) !== MissionType::BLOCK->value
                || in_array($line['status'] ?? null, ['SKIPPED', 'CONFLICT'], true)
                || ($line['siteId'] ?? null) === null) {
                continue;
            }
            $buckets[implode('|', [$line['instrumentistId'], $line['date'], $line['surgeonId'], $line['siteId']])][] = $i;
        }

        foreach ($buckets as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            // Sweep by start time: a line overlapping the running group's end joins it.
            usort($indexes, static fn (int $a, int $b) => [$lines[$a]['startTime'], $lines[$a]['endTime']] <=> [$lines[$b]['startTime'], $lines[$b]['endTime']]);
            $groups = [];
            $groupEnd = null;
            foreach ($indexes as $i) {
                if ($groupEnd !== null && $lines[$i]['startTime'] < $groupEnd) {
                    $groups[count($groups) - 1][] = $i;
                    $groupEnd = max($groupEnd, $lines[$i]['endTime']);
                } else {
                    $groups[] = [$i];
                    $groupEnd = $lines[$i]['endTime'];
                }
            }
            foreach ($groups as $group) {
                if (count($group) > 1) {
                    foreach ($group as $i) {
                        $lines[$i]['multiRoomCount'] = count($group);
                    }
                }
            }
        }
    }
}
