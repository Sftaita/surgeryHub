<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\FinancialCalculation;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionStatus;
use App\Repository\EncodingTrackingRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-172 — ce que le manager lit d'une mission à côté de son statut de planification : l'état
 * de son encodage (EncodingStateResolver, seule dérivation), qui l'a finalisé et validé
 * (journal d'audit), et chacun de ses verrous RÉELS, par niveau :
 *
 *  - ENCODING              : encodage validé (encodingLockedAt, MissionEncodingGuard) — réouvrable ;
 *  - FINANCIAL_CALCULATION : un calcul financier actif est LOCKED — montants définitifs ;
 *  - DOCUMENT              : une facture firme ou un décompte instrumentiste émis porte la mission
 *                            (ou invoiceGeneratedAt, ancien flux) ;
 *  - CLOSED                : mission clôturée, réouverture impossible.
 *
 * Aucun verrou n'est déduit d'un statut : chacun correspond à un fait en base. Lecture seule ;
 * un nombre fixe de requêtes par page (jamais une par mission).
 */
final class MissionEncodingOverviewService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EncodingTrackingRepository $tracking,
        private readonly EncodingStateResolver $resolver,
    ) {}

    /**
     * @param list<Mission> $missions
     * @return array<int, array<string, mixed>> indexé par mission id
     */
    public function overviewOf(array $missions, \DateTimeImmutable $now): array
    {
        $ids = array_values(array_filter(array_map(static fn (Mission $m) => $m->getId(), $missions)));
        if ($ids === []) {
            return [];
        }

        $facts = $this->tracking->fetchFactsForIds($ids);
        $events = $this->lastWorkflowActors($ids);
        $lockedCalculations = $this->lockedCalculationDates($ids);
        $documents = $this->issuedDocumentCounts($ids);

        $overview = [];
        foreach ($missions as $mission) {
            $id = (int) $mission->getId();
            $state = isset($facts[$id]) ? $this->resolver->resolve($facts[$id], $now) : $this->resolver->resolveForMission($mission, $now);
            $finalizedBy = $events[$id][AuditEventType::MISSION_ENCODING_COMPLETED->value] ?? null;
            $validated = in_array($mission->getStatus(), [MissionStatus::VALIDATED, MissionStatus::CLOSED], true)
                ? ($events[$id][AuditEventType::MISSION_ENCODING_VALIDATED->value] ?? null)
                : null;

            $overview[$id] = [
                'state'       => $state->value,
                'stateLabel'  => $state->label(),
                'finalizedBy' => $finalizedBy !== null ? $this->actor($mission, $finalizedBy['user'], $finalizedBy['at']) : null,
                'validatedBy' => $validated !== null ? $this->actor($mission, $validated['user'], $validated['at']) : null,
                'locks'       => $this->locks($mission, $lockedCalculations[$id] ?? null, $documents[$id] ?? 0),
            ];
        }

        return $overview;
    }

    /** @return array<string, mixed> */
    public function overviewOfMission(Mission $mission, \DateTimeImmutable $now): array
    {
        return $this->overviewOf([$mission], $now)[(int) $mission->getId()] ?? [];
    }

    /** @return list<array{level: string, label: string, reason: string}> */
    private function locks(Mission $mission, ?\DateTimeImmutable $calculationLockedAt, int $issuedDocuments): array
    {
        $locks = [];
        if ($mission->getEncodingLockedAt() !== null) {
            $hours = MissionEncodingCorrectionPolicy::hoursPendingAfterValidation($mission)
                ? ' (seules les heures, encore manquantes, restent déclarables)'
                : '';
            $locks[] = [
                'level'  => 'ENCODING',
                'label'  => 'Encodage verrouillé',
                'reason' => sprintf(
                    "Encodage validé le %s : interventions, matériel et heures ne sont plus modifiables%s. Un manager peut le rouvrir tant qu'aucun calcul financier n'est verrouillé.",
                    $mission->getEncodingLockedAt()->format('d/m/Y'), $hours,
                ),
            ];
        }
        if ($calculationLockedAt !== null) {
            $locks[] = [
                'level'  => 'FINANCIAL_CALCULATION',
                'label'  => 'Calcul financier verrouillé',
                'reason' => sprintf(
                    "Calcul financier verrouillé le %s : ses montants sont définitifs et l'encodage ne peut plus être rouvert.",
                    $calculationLockedAt->format('d/m/Y'),
                ),
            ];
        }
        if ($issuedDocuments > 0 || $mission->getInvoiceGeneratedAt() !== null) {
            $locks[] = [
                'level'  => 'DOCUMENT',
                'label'  => 'Document émis',
                'reason' => 'Une facture firme ou un décompte instrumentiste émis porte cette mission : toute correction passe par la procédure de correction du document.',
            ];
        }
        if ($mission->getStatus() === MissionStatus::CLOSED) {
            $locks[] = [
                'level'  => 'CLOSED',
                'label'  => 'Mission clôturée',
                'reason' => 'Mission clôturée : son encodage ne peut plus être rouvert.',
            ];
        }

        return $locks;
    }

    /** @return array{name: ?string, role: ?string, at: string} */
    private function actor(Mission $mission, User $user, \DateTimeImmutable $at): array
    {
        $name = $user->getId() === $mission->getSurgeon()?->getId()
            ? $user->getDrName()
            : (trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? '')) ?: $user->getEmail());

        return ['name' => $name, 'role' => EncodingTrackingService::roleOnMission($mission, $user), 'at' => $at->format(\DateTimeInterface::ATOM)];
    }

    /**
     * Dernier auteur de MISSION_ENCODING_COMPLETED et de MISSION_ENCODING_VALIDATED par mission.
     *
     * @param list<int> $ids
     * @return array<int, array<string, array{user: User, at: \DateTimeImmutable}>>
     */
    private function lastWorkflowActors(array $ids): array
    {
        /** @var AuditEvent[] $events */
        $events = $this->em->createQueryBuilder()
            ->select('a', 'u')
            ->from(AuditEvent::class, 'a')
            ->join('a.actor', 'u')
            ->where('a.mission IN (:ids)')
            ->andWhere('a.eventType IN (:types)')
            ->setParameter('ids', $ids)
            ->setParameter('types', [AuditEventType::MISSION_ENCODING_COMPLETED, AuditEventType::MISSION_ENCODING_VALIDATED])
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        $actors = [];
        foreach ($events as $event) {
            $actors[(int) $event->getMission()?->getId()][$event->getEventType()->value] = ['user' => $event->getActor(), 'at' => $event->getCreatedAt() ?? new \DateTimeImmutable()];
        }

        return $actors;
    }

    /**
     * @param list<int> $ids
     * @return array<int, \DateTimeImmutable>
     */
    private function lockedCalculationDates(array $ids): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(c.mission) AS missionId', 'MAX(c.lockedAt) AS lockedAt')
            ->from(FinancialCalculation::class, 'c')
            ->where('c.mission IN (:ids)')
            ->andWhere('c.status = :locked')
            ->setParameter('ids', $ids)
            ->setParameter('locked', FinancialCalculationStatus::LOCKED)
            ->groupBy('c.mission')
            ->getQuery()
            ->getArrayResult();

        $dates = [];
        foreach ($rows as $row) {
            $dates[(int) $row['missionId']] = new \DateTimeImmutable((string) $row['lockedAt']);
        }

        return $dates;
    }

    /**
     * Lignes de documents émis (facture firme, décompte instrumentiste ; SENT ou PAID) —
     * même définition que EncodingTrackingRepository::fetchFinancialFacts().
     *
     * @param list<int> $ids
     * @return array<int, int>
     */
    private function issuedDocumentCounts(array $ids): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT x.mission_id AS missionId, COUNT(*) AS n FROM (
                SELECT fil.mission_id FROM firm_invoice_line fil
                  INNER JOIN firm_invoice fi ON fi.id = fil.invoice_id
                 WHERE fil.mission_id IN (:ids) AND fi.status IN ('SENT','PAID')
                UNION ALL
                SELECT isl.mission_id FROM instrumentist_statement_line isl
                  INNER JOIN instrumentist_statement ist ON ist.id = isl.statement_id
                 WHERE isl.mission_id IN (:ids) AND ist.status IN ('SENT','PAID')
             ) x GROUP BY x.mission_id",
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['missionId']] = (int) $row['n'];
        }

        return $counts;
    }
}
