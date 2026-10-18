<?php

namespace App\Service\Prestation;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Mission;
use App\Entity\User;
use App\Entity\WorkedPresence;
use App\Exception\PrestationConflictException;
use App\Service\MissionActionsService;
use App\Service\MissionEncodingWorkflowService;
use App\Service\MissionExecutionService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-156 — encodage commun d'une prestation regroupée multi-salles : vue unique (missions
 * distinctes, union planifiée, présence réelle, parts imputées, états) et soumission
 * commune. L'autorisation mission par mission (Voters) reste dans le contrôleur ; ici, la
 * cohérence et l'atomicité.
 *
 * Protection contre les modifications concurrentes : la vue porte une EMPREINTE
 * (`fingerprint`) de tout ce qui conditionne la soumission — composition, statuts,
 * horaires planifiés, révision de la présence, heures propres, nombre d'interventions et de
 * lignes de matériel de chaque mission. Le client la renvoie ; si elle a changé (même à
 * composition identique), l'opération est refusée (409 PRESTATION_CHANGED) et le client
 * propose un rafraîchissement.
 */
final class PrestationEncodingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrestationGroupResolver $groups,
        private readonly MissionActionsService $actions,
        private readonly MissionEncodingWorkflowService $workflow,
    ) {}

    /** @return list<Mission> */
    public function groupOf(Mission $anchor): array
    {
        return $this->groups->groupOf($anchor);
    }

    /**
     * @param list<Mission> $group
     * @param array{editPresence: bool, submit: bool} $groupRights droits déjà évalués par les Voters
     * @return array<string,mixed>
     */
    public function view(array $group, User $viewer, array $groupRights): array
    {
        PrestationGrouping::sort($group);
        $first = $group[0];
        $presence = $this->presenceOf($group);
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $count = count($group);

        $missions = [];
        foreach ($group as $i => $m) {
            $execution = $m->getExecution();
            $effective = MissionExecutionService::resolveDuration($m);
            $activeMaterial = 0;
            foreach ($m->getMaterialLines() as $line) {
                if ((float) $line->getQuantity() > 0) {
                    ++$activeMaterial;
                }
            }
            $missions[] = [
                'id' => $m->getId(),
                'ordinal' => $i + 1,
                'label' => self::label($m, $i + 1, $count),
                'roomLabel' => $m->getRoomLabel(),
                'startAt' => $m->getStartAt()?->format(\DateTimeInterface::ATOM),
                'endAt' => $m->getEndAt()?->format(\DateTimeInterface::ATOM),
                'plannedMinutes' => (int) round(($m->getEndAt()->getTimestamp() - $m->getStartAt()->getTimestamp()) / 60),
                'status' => $m->getStatus()?->value,
                'imputedMinutes' => $execution?->getPresence() !== null ? $execution->getActualDurationMinutes() : null,
                'effectiveMinutes' => $effective->minutes,
                'effectiveSource' => $effective->source->value,
                'linkedToPresence' => $execution?->getPresence() !== null,
                'hasOwnHours' => self::hasOwnHours($m),
                'interventionCount' => $m->getInterventions()->count(),
                'activeMaterialLineCount' => $activeMaterial,
                'noMaterialComment' => $m->getNoMaterialComment(),
                'allowedActions' => $this->actions->allowedActions($m, $viewer),
            ];
        }

        $statuses = array_values(array_unique(array_map(static fn (Mission $m) => $m->getStatus()?->value, $group)));

        return [
            'key' => PrestationGrouping::key($group),
            'size' => $count,
            'missionIds' => PrestationGrouping::ids($group),
            'missions' => $missions,
            'date' => PrestationGrouping::localDate($first),
            'instrumentist' => self::person($first->getInstrumentist()),
            'surgeon' => self::person($first->getSurgeon(), true),
            'site' => ['id' => $first->getSite()?->getId(), 'name' => $first->getSite()?->getName()],
            'planned' => [
                'startAt' => min(array_map(static fn (Mission $m) => $m->getStartAt(), $group))->setTimezone($tz)->format(\DateTimeInterface::ATOM),
                'endAt' => max(array_map(static fn (Mission $m) => $m->getEndAt(), $group))->setTimezone($tz)->format(\DateTimeInterface::ATOM),
                'unionMinutes' => PrestationGrouping::plannedUnionMinutes($group),
            ],
            'presence' => $presence === null ? null : [
                'id' => $presence->getId(),
                'revision' => $presence->getRevision(),
                'actualStartAt' => $presence->getActualStartAt()?->format(\DateTimeInterface::ATOM),
                'actualEndAt' => $presence->getActualEndAt()?->format(\DateTimeInterface::ATOM),
                'totalMinutes' => $presence->getTotalMinutes(),
                'missionIds' => PrestationGrouping::ids($presence->getMissions()),
                'allocationRule' => PresenceAllocator::RULE,
            ],
            'unlinkedMissionIds' => $presence === null ? [] : array_values(array_map(
                static fn (Mission $m) => (int) $m->getId(),
                array_filter($group, static fn (Mission $m) => $m->getExecution()?->getPresence() === null),
            )),
            'ownHoursMissionIds' => array_values(array_map(static fn (Mission $m) => (int) $m->getId(), array_filter($group, [self::class, 'hasOwnHours']))),
            'statuses' => $statuses,
            'globalStatus' => count($statuses) === 1 ? $statuses[0] : 'MIXED',
            'fingerprint' => self::fingerprint($group),
            'allowedActions' => array_values(array_filter([
                $groupRights['editPresence'] ? 'edit_presence' : null,
                $groupRights['submit'] ? 'submit' : null,
            ])),
        ];
    }

    /**
     * Soumission commune : composition et état vérifiés SOUS VERROU (missions du groupe,
     * ordre d'id), puis MissionEncodingWorkflowService::completePrestation() — tout ou rien.
     *
     * @return list<Mission> le groupe (rechargé)
     */
    public function submit(Mission $anchor, User $actor, string $expectedFingerprint, ?string $noMaterialComment): array
    {
        $resolved = [];
        $lockAndResolve = function () use ($anchor, $expectedFingerprint, &$resolved): array {
            $group = $this->groups->groupOf($anchor);
            $byId = $group;
            usort($byId, static fn (Mission $a, Mission $b) => (int) $a->getId() <=> (int) $b->getId());
            foreach ($byId as $m) {
                $this->em->lock($m, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($m);
                if ($m->getExecution() !== null) {
                    $this->em->refresh($m->getExecution());
                }
            }
            $group = $this->groups->groupOf($anchor);
            $this->assertFingerprint($group, $expectedFingerprint);
            PrestationGrouping::sort($group);
            $resolved = $group;

            return $group;
        };

        $this->workflow->completePrestation($lockAndResolve, $actor, $noMaterialComment, PrestationGrouping::key($this->groups->groupOf($anchor)));

        return $resolved;
    }

    /** @param list<Mission> $group */
    public function assertFingerprint(array $group, string $expected): void
    {
        $current = self::fingerprint($group);
        if (!hash_equals($current, $expected)) {
            throw new PrestationConflictException(
                PrestationConflictException::PRESTATION_CHANGED,
                "La prestation a changé depuis l'ouverture de l'écran (missions, statuts, horaires ou encodage). Rafraîchissez avant de soumettre.",
                ['currentMissionIds' => PrestationGrouping::ids($group)],
            );
        }
    }

    /** @param list<Mission> $group */
    public static function fingerprint(array $group): string
    {
        $parts = [];
        $sorted = $group;
        usort($sorted, static fn (Mission $a, Mission $b) => (int) $a->getId() <=> (int) $b->getId());
        foreach ($sorted as $m) {
            $e = $m->getExecution();
            $parts[] = implode('|', [
                $m->getId(),
                $m->getStatus()?->value,
                $m->getStartAt()?->getTimestamp(),
                $m->getEndAt()?->getTimestamp(),
                (int) $m->getInstrumentist()?->getId(),
                $e?->getPresence()?->getId() ?? '-',
                $e?->getPresence()?->getRevision() ?? '-',
                $e?->getPresence()?->getActualStartAt()?->getTimestamp() ?? '-',
                $e?->getPresence()?->getActualEndAt()?->getTimestamp() ?? '-',
                $e?->getActualStartAt()?->getTimestamp() ?? '-',
                $e?->getActualEndAt()?->getTimestamp() ?? '-',
                $e?->getActualDurationMinutes() ?? '-',
                $m->getInterventions()->count(),
                $m->getMaterialLines()->count(),
            ]);
        }

        return hash('sha256', implode(';', $parts));
    }

    /**
     * Identification non ambiguë d'une mission dans sa prestation, même à horaires identiques :
     * libellé de salle s'il existe, sinon « Mission n/N » ; toujours horaires + numéro.
     */
    public static function label(Mission $m, int $ordinal, int $count): string
    {
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $hours = sprintf('%s–%s', $m->getStartAt()?->setTimezone($tz)->format('H\hi'), $m->getEndAt()?->setTimezone($tz)->format('H\hi'));
        $name = $m->getRoomLabel() !== null && trim($m->getRoomLabel()) !== ''
            ? trim($m->getRoomLabel())
            : sprintf('Mission %d/%d', $ordinal, $count);

        return sprintf('%s · %s · n° %d', $name, $hours, $m->getId());
    }

    /** @param list<Mission> $group */
    private function presenceOf(array $group): ?WorkedPresence
    {
        foreach ($group as $m) {
            if ($m->getExecution()?->getPresence() !== null) {
                return $m->getExecution()->getPresence();
            }
        }

        return null;
    }

    public static function hasOwnHours(Mission $m): bool
    {
        $e = $m->getExecution();

        return $e !== null && $e->getPresence() === null
            && ($e->getActualStartAt() !== null || $e->getActualEndAt() !== null || $e->getActualDurationMinutes() !== null);
    }

    /** @return array{id:?int,name:string}|null */
    private static function person(?User $u, bool $doctor = false): ?array
    {
        if ($u === null) {
            return null;
        }

        return [
            'id' => $u->getId(),
            'name' => $doctor ? $u->getDrName() : trim(($u->getFirstname() ?? '') . ' ' . ($u->getLastname() ?? '')),
        ];
    }
}
