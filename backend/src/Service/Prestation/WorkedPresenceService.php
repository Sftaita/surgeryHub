<?php

namespace App\Service\Prestation;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\FinancialCalculation;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Entity\WorkedPresence;
use App\Enum\AuditEventType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\HoursSource;
use App\Exception\PrestationConflictException;
use App\Service\AuditService;
use App\Service\MissionExecutionService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D-156 — seul point d'écriture de la présence réelle partagée (WorkedPresence) et des
 * parts imputées qui en dérivent (MissionExecution.actualDurationMinutes des missions
 * liées). L'autorisation (Voters) est vérifiée par le contrôleur, mission par mission ;
 * ce service applique les invariants métier et l'atomicité.
 *
 * Invariants :
 * - une mission liée n'a jamais ses propres horaires réels (CHECK en base) ;
 * - toutes les missions d'une présence appartiennent au même instrumentiste / chirurgien /
 *   site / date, celui de la présence ;
 * - aucune présence orpheline (au moins une mission liée) ;
 * - aucune modification si une mission liée est verrouillée/facturée, ou a un calcul
 *   financier approuvé ou verrouillé : les données arrêtées ne bougent jamais en silence ;
 * - chaque écriture est transactionnelle, sous verrou pessimiste des missions du groupe
 *   (ordre d'id croissant) et de la présence, avec contrôle de la composition attendue et
 *   de la révision attendue : un contexte changé = 409 explicite, jamais une écriture
 *   partielle.
 */
final class WorkedPresenceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrestationGroupResolver $groups,
        private readonly MissionExecutionService $executions,
        private readonly AuditService $audit,
    ) {}

    /**
     * Déclare (ou corrige) les heures réelles d'une prestation, une seule fois pour toutes
     * ses missions. Prestation d'une seule mission sans présence : comportement historique
     * inchangé (MissionExecutionService::updateActuals()).
     *
     * @param list<int> $expectedMissionIds composition vue par le client
     * @param bool $convertOwnHours confirmation explicite de reprendre dans la présence
     *        commune les heures propres déjà saisies sur une mission du groupe
     */
    public function declare(
        Mission $anchor,
        User $actor,
        ?\DateTimeImmutable $actualStartAt,
        ?\DateTimeImmutable $actualEndAt,
        array $expectedMissionIds,
        ?int $expectedRevision = null,
        bool $convertOwnHours = false,
    ): ?WorkedPresence {
        if ($actualStartAt === null && $actualEndAt === null) {
            throw new UnprocessableEntityHttpException('Au moins une heure réelle (début) est requise.');
        }
        if ($actualStartAt !== null && $actualEndAt !== null && $actualEndAt <= $actualStartAt) {
            throw new UnprocessableEntityHttpException("L'heure de fin doit être postérieure à l'heure de début.");
        }

        return $this->em->wrapInTransaction(function () use ($anchor, $actor, $actualStartAt, $actualEndAt, $expectedMissionIds, $expectedRevision, $convertOwnHours): ?WorkedPresence {
            $group = $this->lockedGroup($anchor);
            $this->assertExpectedComposition($group, $expectedMissionIds);

            $presence = $this->singlePresenceOf($group);

            if (count($group) === 1 && $presence === null) {
                $this->executions->updateActuals($anchor, $actor, $actualStartAt, $actualEndAt, null, $this->hoursSourceFor($actor));
                return null;
            }

            if ($presence !== null) {
                $this->em->lock($presence, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($presence);
                if ($expectedRevision !== null && $presence->getRevision() !== $expectedRevision) {
                    throw new PrestationConflictException(
                        PrestationConflictException::PRESENCE_REVISION_CHANGED,
                        'Les heures de cette prestation ont été modifiées entre-temps. Rafraîchissez avant de les corriger.',
                        ['presenceId' => $presence->getId(), 'currentRevision' => $presence->getRevision()],
                    );
                }
                foreach ($presence->getMissions() as $linked) {
                    if (!in_array($linked, $group, true)) {
                        throw new PrestationConflictException(
                            PrestationConflictException::PRESTATION_PRESENCE_CONFLICT,
                            'Une mission liée à cette présence ne fait plus partie de la prestation : une correction par un manager est nécessaire.',
                            ['presenceId' => $presence->getId(), 'missionId' => $linked->getId()],
                        );
                    }
                }
            }

            $this->assertNotSettled($group);
            $this->handleOwnHours($group, $actor, $convertOwnHours);

            $before = $presence !== null ? self::snapshotTimes($presence) : null;
            $isNew = $presence === null;
            if ($isNew) {
                $first = $group[0];
                $presence = (new WorkedPresence())
                    ->setInstrumentist($first->getInstrumentist())
                    ->setSurgeon($first->getSurgeon())
                    ->setSite($first->getSite())
                    ->setServiceDate(new \DateTimeImmutable(PrestationGrouping::localDate($first)))
                    ->setDeclaredBy($actor);
                $this->em->persist($presence);
            }

            $presence->setActualStartAt($actualStartAt ?? $presence->getActualStartAt());
            $presence->setActualEndAt($actualEndAt ?? $presence->getActualEndAt());
            if ($presence->getActualStartAt() !== null && $presence->getActualEndAt() !== null && $presence->getActualEndAt() <= $presence->getActualStartAt()) {
                throw new UnprocessableEntityHttpException("L'heure de fin doit être postérieure à l'heure de début.");
            }
            if (!$isNew) {
                $presence->setUpdatedBy($actor);
            }

            $newlyLinked = [];
            foreach ($group as $mission) {
                $execution = $this->executions->findOrCreateExecution($mission, $actor);
                if ($execution->getPresence() !== $presence) {
                    $execution->setPresence($presence);
                    $presence->getExecutions()->add($execution);
                    $newlyLinked[] = $mission;
                }
                $execution->setHoursSource($this->hoursSourceFor($actor));
            }

            $this->recomputeShares($presence);
            $this->em->flush();

            $after = self::snapshotTimes($presence);
            $timesChanged = $before !== $after;
            $memberIds = PrestationGrouping::ids($group);
            foreach ($group as $mission) {
                $payload = [
                    'presenceId' => $presence->getId(),
                    'revision' => $presence->getRevision(),
                    'memberMissionIds' => $memberIds,
                    'before' => $before,
                    'after' => $after,
                    'imputedMinutes' => $mission->getExecution()?->getActualDurationMinutes(),
                    'allocationRule' => PresenceAllocator::RULE,
                ];
                if ($isNew) {
                    $this->audit->record($mission, $actor, AuditEventType::MISSION_EXECUTION_PRESENCE_DECLARED, $payload);
                } elseif ($timesChanged) {
                    $this->audit->record($mission, $actor, AuditEventType::MISSION_EXECUTION_PRESENCE_UPDATED, $payload);
                }
                if (!$isNew && in_array($mission, $newlyLinked, true)) {
                    $this->audit->record($mission, $actor, AuditEventType::MISSION_EXECUTION_PRESENCE_LINKED, $payload);
                }
            }
            $this->em->flush();

            return $presence;
        });
    }

    /**
     * Correction contrôlée (manager) : retire une mission de sa présence partagée, sans
     * transférer ni effacer quoi que ce soit — la mission n'a plus d'heures réelles (elles
     * appartiennent à l'instrumentiste de la présence), les autres missions sont
     * réimputées, l'historique garde l'avant/après. Interdit si la mission ou une mission
     * sœur est verrouillée ou financièrement arrêtée, ou si la présence deviendrait orpheline.
     */
    public function detach(Mission $mission, User $manager, string $reason): WorkedPresence
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new UnprocessableEntityHttpException('Un motif est obligatoire pour détacher une mission de sa présence commune.');
        }

        return $this->em->wrapInTransaction(function () use ($mission, $manager, $reason): WorkedPresence {
            $presence = $mission->getExecution()?->getPresence();
            if ($presence === null) {
                throw new UnprocessableEntityHttpException("Cette mission n'est liée à aucune présence commune.");
            }
            $this->em->lock($presence, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($presence);
            $members = $presence->getMissions();
            foreach ($members as $m) {
                $this->em->lock($m, LockMode::PESSIMISTIC_WRITE);
            }
            if (count($members) <= 1) {
                throw new PrestationConflictException(
                    PrestationConflictException::PRESENCE_LAST_MISSION,
                    "C'est la seule mission de cette présence : elle ne peut pas en être détachée (la présence deviendrait orpheline).",
                    ['presenceId' => $presence->getId()],
                );
            }
            $this->assertNotSettled($members);

            $before = self::snapshotTimes($presence);
            $execution = $mission->getExecution();
            $previousShare = $execution->getActualDurationMinutes();
            $execution->setPresence(null);
            $execution->setActualDurationMinutes(null);
            $presence->getExecutions()->removeElement($execution);
            $presence->setUpdatedBy($manager);

            $this->recomputeShares($presence);
            $this->em->flush();

            $remainingIds = PrestationGrouping::ids($presence->getMissions());
            $this->audit->record($mission, $manager, AuditEventType::MISSION_EXECUTION_PRESENCE_DETACHED, [
                'presenceId' => $presence->getId(),
                'reason' => $reason,
                'previousImputedMinutes' => $previousShare,
                'presence' => $before,
                'remainingMissionIds' => $remainingIds,
            ]);
            foreach ($presence->getMissions() as $remaining) {
                $this->audit->record($remaining, $manager, AuditEventType::MISSION_EXECUTION_PRESENCE_UPDATED, [
                    'presenceId' => $presence->getId(),
                    'revision' => $presence->getRevision(),
                    'memberMissionIds' => $remainingIds,
                    'detachedMissionId' => $mission->getId(),
                    'reason' => $reason,
                    'imputedMinutes' => $remaining->getExecution()?->getActualDurationMinutes(),
                    'allocationRule' => PresenceAllocator::RULE,
                ]);
            }
            $this->em->flush();

            return $presence;
        });
    }

    /**
     * Réécrit la part imputée de chaque mission liée (null tant que la présence n'a pas
     * début ET fin). Seul endroit qui écrit ces parts.
     */
    public function recomputeShares(WorkedPresence $presence): void
    {
        $missions = $presence->getMissions();
        $shares = self::sharesFor($presence, $missions);
        foreach ($missions as $mission) {
            $execution = $mission->getExecution();
            $execution->setActualStartAt(null);
            $execution->setActualEndAt(null);
            $execution->setActualDurationMinutes($shares[(int) $mission->getId()] ?? null);
        }
    }

    /**
     * Parts imputées d'une présence (minutes par id de mission), recalculées depuis la
     * présence et les horaires planifiés de ses missions — utilisé aussi par le moteur
     * financier pour vérifier que la part enregistrée n'est pas obsolète.
     *
     * @param list<Mission>|null $missions
     * @return array<int,int>|null null tant que la présence est incomplète
     */
    public static function sharesFor(WorkedPresence $presence, ?array $missions = null): ?array
    {
        $missions ??= $presence->getMissions();
        if (!$presence->hasCompleteTimes() || $missions === []) {
            return null;
        }

        return PresenceAllocator::allocateMinutes(
            PrestationGrouping::allocationMembers($missions),
            $presence->getActualStartAt()->getTimestamp(),
            $presence->getActualEndAt()->getTimestamp(),
        );
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @return list<Mission> le groupe courant, missions verrouillées (ordre d'id) puis rechargées */
    private function lockedGroup(Mission $anchor): array
    {
        $group = $this->groups->groupOf($anchor);
        $byId = $group;
        usort($byId, static fn (Mission $a, Mission $b) => (int) $a->getId() <=> (int) $b->getId());
        foreach ($byId as $m) {
            $this->em->lock($m, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($m);
        }

        // Re-resolve after locking: the composition read before the lock may be stale.
        $group = $this->groups->groupOf($anchor);
        PrestationGrouping::sort($group);

        return $group;
    }

    /** @param list<Mission> $group @param list<int> $expected */
    private function assertExpectedComposition(array $group, array $expected): void
    {
        $current = PrestationGrouping::ids($group);
        $expected = array_values(array_unique(array_map('intval', $expected)));
        sort($expected);
        if ($current !== $expected) {
            throw new PrestationConflictException(
                PrestationConflictException::PRESTATION_CHANGED,
                "La prestation a changé depuis l'ouverture de l'écran (missions ajoutées, retirées ou réaffectées). Rafraîchissez avant d'enregistrer.",
                ['currentMissionIds' => $current, 'expectedMissionIds' => $expected],
            );
        }
    }

    /** @param list<Mission> $group */
    private function singlePresenceOf(array $group): ?WorkedPresence
    {
        $presences = [];
        foreach ($group as $m) {
            $p = $m->getExecution()?->getPresence();
            if ($p !== null) {
                $presences[(int) $p->getId()] = $p;
            }
        }
        if (count($presences) > 1) {
            throw new PrestationConflictException(
                PrestationConflictException::PRESTATION_PRESENCE_CONFLICT,
                'Les missions de cette prestation sont liées à des présences différentes : une correction par un manager est nécessaire.',
                ['presenceIds' => array_keys($presences)],
            );
        }

        return $presences === [] ? null : array_values($presences)[0];
    }

    /** @param list<Mission> $missions */
    private function assertNotSettled(array $missions): void
    {
        foreach ($missions as $m) {
            if ($m->isEncodingLocked()) {
                throw new PrestationConflictException(
                    PrestationConflictException::PRESENCE_ENCODING_LOCKED,
                    "Une mission de cette prestation est validée ou facturée : ses heures ne peuvent plus être modifiées sans réouverture contrôlée.",
                    ['missionId' => $m->getId()],
                );
            }
        }

        $settled = $this->em->createQueryBuilder()
            ->select('IDENTITY(fc.mission) AS missionId', 'fc.status AS status')
            ->from(FinancialCalculation::class, 'fc')
            ->andWhere('fc.mission IN (:missions)')
            ->andWhere('fc.status IN (:statuses)')
            ->setParameter('missions', $missions)
            ->setParameter('statuses', [FinancialCalculationStatus::APPROVED, FinancialCalculationStatus::LOCKED])
            ->getQuery()
            ->getArrayResult();
        if ($settled !== []) {
            throw new PrestationConflictException(
                PrestationConflictException::PRESENCE_FINANCIALLY_SETTLED,
                'Une mission de cette prestation a un calcul financier approuvé ou verrouillé : les heures communes ne peuvent pas être modifiées.',
                ['missionIds' => array_values(array_unique(array_map(static fn (array $r) => (int) $r['missionId'], $settled)))],
            );
        }
    }

    /** @param list<Mission> $group */
    private function handleOwnHours(array $group, User $actor, bool $convert): void
    {
        $withOwn = array_values(array_filter($group, static function (Mission $m): bool {
            $e = $m->getExecution();
            return $e !== null && $e->getPresence() === null
                && ($e->getActualStartAt() !== null || $e->getActualEndAt() !== null || $e->getActualDurationMinutes() !== null);
        }));
        if ($withOwn === []) {
            return;
        }
        if (!$convert) {
            throw new PrestationConflictException(
                PrestationConflictException::PRESTATION_HAS_OWN_HOURS,
                'Des heures ont déjà été saisies sur une mission de cette prestation. Confirmez pour les remplacer par les heures communes (les anciennes valeurs restent dans l\'historique).',
                ['missionIds' => array_map(static fn (Mission $m) => $m->getId(), $withOwn)],
            );
        }
        foreach ($withOwn as $m) {
            $e = $m->getExecution();
            $this->audit->record($m, $actor, AuditEventType::MISSION_EXECUTION_OWN_HOURS_CONVERTED, [
                'previous' => [
                    'actualStartAt' => $e->getActualStartAt()?->format(\DateTimeInterface::ATOM),
                    'actualEndAt' => $e->getActualEndAt()?->format(\DateTimeInterface::ATOM),
                    'actualDurationMinutes' => $e->getActualDurationMinutes(),
                ],
            ]);
            $e->setActualStartAt(null)->setActualEndAt(null)->setActualDurationMinutes(null);
        }
    }

    private function hoursSourceFor(User $actor): HoursSource
    {
        $roles = $actor->getRoles();

        return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true)
            ? HoursSource::MANAGER
            : HoursSource::INSTRUMENTIST;
    }

    /** @return array{actualStartAt: ?string, actualEndAt: ?string} */
    private static function snapshotTimes(WorkedPresence $presence): array
    {
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);

        return [
            'actualStartAt' => $presence->getActualStartAt()?->setTimezone($tz)->format(\DateTimeInterface::ATOM),
            'actualEndAt' => $presence->getActualEndAt()?->setTimezone($tz)->format(\DateTimeInterface::ATOM),
        ];
    }
}
