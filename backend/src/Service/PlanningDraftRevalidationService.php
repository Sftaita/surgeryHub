<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Revalidates every DRAFT mission of a PlanningVersion against CURRENT absence AND
 * cross-site-conflict data, right before deploy() publishes it — D-090/D-091. A draft's
 * assignment was correct against absences and other missions at the moment
 * PlanningGeneratorServiceV2::preview()/generate() ran; nothing re-checks it if an absence
 * is created, or another mission changes, afterward but before the manager clicks
 * Déployer (DRAFT is deliberately never touched by AbsenceMissionReactionService, which
 * only reacts to already-published ASSIGNED/OPEN missions — see its class docblock). This
 * service closes that gap for both causes.
 *
 * Absence outcomes, by design (see docs/decisions.md D-090):
 *   - Surgeon absent → the mission's own activity is cancelled; there is no "vacant post"
 *     left to fill, so the mission is neutralized (CANCELLED) rather than published as OPEN.
 *     This mirrors AbsenceMissionReactionService::processSurgeonAbsence()'s existing rule
 *     for already-published missions, extended to the DRAFT stage — never a new status.
 *   - Instrumentist absent (surgeon present) → the assignment itself is what's wrong, and
 *     dropping it silently would publish a plan the manager never actually reviewed. This
 *     blocks the ENTIRE deploy (see PlanningDraftConflictException) rather than excluding
 *     just the affected missions — a partially-published plan without explicit manager
 *     intent is the exact failure mode D-090 exists to close.
 *
 * Cross-site conflict outcome (D-091) — always blocking, for either person: unlike an
 * absence, there is no safe automatic resolution (neutralizing would silently drop a
 * commitment the manager never reviewed either). Checked for BOTH the surgeon and the
 * instrumentist of every DRAFT mission, using PlanningConflictDetectionService — the same
 * cross-site, end-exclusive overlap rule used everywhere else in Planning V2.
 *
 * A mission whose surgeon AND instrumentist are both absent is reported only as
 * "neutralized" (surgeon check runs first) — once the surgeon's own activity is cancelled,
 * whichever instrumentist was attached to it is no longer a meaningful conflict to block on.
 * Conflict checks run only for missions that passed the absence check unneutralized —
 * a mission about to be neutralized for surgeon absence is never also reported as
 * conflicting, since it will not be published either way.
 */
class PlanningDraftRevalidationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbsenceOverlapService $absenceOverlap,
        private readonly MissionPostDeployService $postDeploy,
        private readonly PlanningConflictDetectionService $conflictDetection,
    ) {}

    /**
     * Read-only pass — never mutates anything. Call this first; only call neutralize()
     * afterward, and only if blockingConflicts is empty.
     *
     * @return array{
     *   blockingConflicts: list<array{type:string,missionId:int,date:string,siteId:?int,siteName:?string,surgeonId:?int,surgeonName:?string,instrumentistId:?int,instrumentistName:?string,reason:string,conflictingMissionId?:int,conflictingSiteId?:?int,conflictingSiteName?:?string,conflictingStartAt?:string,conflictingEndAt?:string}>,
     *   neutralized: list<array{missionId:int,date:string,siteId:?int,siteName:?string,surgeonId:int,surgeonName:string,previousInstrumentistId:?int,previousInstrumentistName:?string,reason:string}>,
     * }
     */
    public function revalidate(PlanningVersion $version): array
    {
        /** @var Mission[] $missions */
        $missions = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.planningVersion = :version')
            ->andWhere('m.status = :draft')
            ->setParameter('version', $version)
            ->setParameter('draft', MissionStatus::DRAFT)
            ->getQuery()
            ->getResult();

        $blockingConflicts = [];
        $neutralized        = [];

        foreach ($missions as $mission) {
            $start         = $mission->getStartAt();
            $end           = $mission->getEndAt();
            $surgeon       = $mission->getSurgeon();
            $instrumentist = $mission->getInstrumentist();

            if ($start === null || $end === null) {
                continue; // defensive — never actually null on a persisted Mission
            }

            if ($surgeon !== null && $this->absenceOverlap->isUserAbsentDuring($surgeon, $start, $end)) {
                $neutralized[] = [
                    'missionId'                 => $mission->getId(),
                    'date'                      => $start->format('Y-m-d'),
                    'siteId'                    => $mission->getSite()?->getId(),
                    'siteName'                  => $mission->getSite()?->getName(),
                    'surgeonId'                 => $surgeon->getId(),
                    'surgeonName'               => self::displayName($surgeon),
                    'previousInstrumentistId'   => $instrumentist?->getId(),
                    'previousInstrumentistName' => $instrumentist !== null ? self::displayName($instrumentist) : null,
                    'reason'                    => sprintf(
                        'Chirurgien %s absent le %s — poste neutralisé automatiquement (revalidation au déploiement).',
                        self::displayName($surgeon),
                        $start->format('d/m/Y'),
                    ),
                ];
                continue;
            }

            if ($instrumentist !== null && $this->absenceOverlap->isUserAbsentDuring($instrumentist, $start, $end)) {
                $blockingConflicts[] = [
                    'type'              => 'ABSENCE',
                    'missionId'         => $mission->getId(),
                    'date'              => $start->format('Y-m-d'),
                    'siteId'            => $mission->getSite()?->getId(),
                    'siteName'          => $mission->getSite()?->getName(),
                    'surgeonId'         => $surgeon?->getId(),
                    'surgeonName'       => $surgeon !== null ? self::displayName($surgeon) : null,
                    'instrumentistId'   => $instrumentist->getId(),
                    'instrumentistName' => self::displayName($instrumentist),
                    'reason'            => sprintf(
                        'Instrumentiste %s absent le %s — déploiement bloqué.',
                        self::displayName($instrumentist),
                        $start->format('d/m/Y'),
                    ),
                ];
                continue;
            }

            // D-091 — cross-site conflict, checked for both people. Runs only for missions
            // that reached here unneutralized (surgeon present and not absent). Real-time,
            // per-mission queries are acceptable here: this loop is bounded by one
            // PlanningVersion's DRAFT missions (at most a few hundred), not a full month of
            // every site combined.
            foreach ([
                ['person' => $surgeon,       'role' => 'SURGEON'],
                ['person' => $instrumentist, 'role' => 'INSTRUMENTIST'],
            ] as ['person' => $person, 'role' => $role]) {
                if ($person === null) {
                    continue;
                }
                $other = $this->conflictDetection->findConflict($person, $start, $end, $mission->getId());
                if ($other === null) {
                    continue;
                }

                $blockingConflicts[] = [
                    'type'                 => 'CROSS_SITE_CONFLICT',
                    'missionId'            => $mission->getId(),
                    'date'                 => $start->format('Y-m-d'),
                    'siteId'               => $mission->getSite()?->getId(),
                    'siteName'             => $mission->getSite()?->getName(),
                    'surgeonId'            => $surgeon?->getId(),
                    'surgeonName'          => $surgeon !== null ? self::displayName($surgeon) : null,
                    'instrumentistId'      => $instrumentist?->getId(),
                    'instrumentistName'    => $instrumentist !== null ? self::displayName($instrumentist) : null,
                    'conflictingMissionId' => $other->getId(),
                    'conflictingSiteId'    => $other->getSite()?->getId(),
                    'conflictingSiteName'  => $other->getSite()?->getName(),
                    'conflictingStartAt'   => $other->getStartAt()->format(\DateTimeInterface::ATOM),
                    'conflictingEndAt'     => $other->getEndAt()->format(\DateTimeInterface::ATOM),
                    'reason'               => sprintf(
                        '%s %s déjà prévu(e) sur %s (%s–%s) — chevauche %s (%s–%s) sur %s : déploiement bloqué.',
                        $role === 'SURGEON' ? 'Chirurgien' : 'Instrumentiste',
                        self::displayName($person),
                        $other->getSite()?->getName() ?? 'un autre site',
                        $other->getStartAt()->format('H:i'),
                        $other->getEndAt()->format('H:i'),
                        $start->format('d/m/Y'),
                        $start->format('H:i'),
                        $end->format('H:i'),
                        $mission->getSite()?->getName() ?? 'ce site',
                    ),
                ];
            }
        }

        return ['blockingConflicts' => $blockingConflicts, 'neutralized' => $neutralized];
    }

    /**
     * Applies the "neutralized" outcomes from revalidate() — cancels each mission via the
     * existing, already-audited/notified MissionPostDeployService::cancel() path (never a
     * bespoke mutation here). notify:true deliberately — reuses the existing
     * MissionLifecycleChangedMessage → MissionLifecycleChangedMessageHandler pipeline so a
     * previously-assigned instrumentist IS told their assignment was just cancelled, without
     * this service having to reimplement that notification.
     *
     * Only ever call this after confirming revalidate()'s blockingConflicts is empty —
     * deploy() must never publish a plan while also silently dropping conflicting instrumentist
     * assignments.
     *
     * @param list<array{missionId:int,reason:string,...}> $neutralized
     */
    public function neutralize(array $neutralized, User $actor): void
    {
        foreach ($neutralized as $entry) {
            $mission = $this->em->find(Mission::class, $entry['missionId']);
            if ($mission === null || $mission->getStatus() !== MissionStatus::DRAFT) {
                continue; // concurrent change since revalidate() ran — skip rather than mutate an unexpected state
            }

            $this->postDeploy->cancel($mission, $actor, reason: $entry['reason'], notify: true);
        }
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
