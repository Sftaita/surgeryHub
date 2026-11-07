<?php

namespace App\Service;

use App\Entity\FinancialCalculation;
use App\Entity\Mission;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionStatus;
use App\Exception\CatalogueRequestException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-146 bis — une résolution de demande catalogue (matériel ou intervention) n'ajoute
 * JAMAIS directement une intervention ou une ligne de matériel à une mission validée,
 * clôturée, annulée/refusée, à l'encodage verrouillé ou déjà facturée : c'est la même
 * frontière que MissionEncodingGuard (encodingLockedAt / invoiceGeneratedAt), étendue aux
 * statuts terminaux, et elle s'applique aussi au manager (aucune exception de rôle).
 *
 * Le refus indique si la procédure existante de réouverture
 * (POST /api/missions/{id}/encoding/reopen, VALIDATED → ENCODING_IN_PROGRESS) est
 * disponible — jamais une réouverture automatique : c'est au manager de la déclencher
 * (mêmes règles que MissionEncodingWorkflowService::reopen(), notamment le refus en
 * présence d'un calcul financier LOCKED).
 */
final class CatalogueResolutionGuard
{
    private const TERMINAL = [MissionStatus::VALIDATED, MissionStatus::CLOSED, MissionStatus::CANCELLED, MissionStatus::REJECTED];

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function isLocked(Mission $mission): bool
    {
        return in_array($mission->getStatus(), self::TERMINAL, true)
            || $mission->getEncodingLockedAt() !== null
            || $mission->getInvoiceGeneratedAt() !== null;
    }

    /** Réouverture existante possible ? (mêmes conditions que MissionEncodingWorkflowService::reopen()). */
    public function reopenAvailable(Mission $mission): bool
    {
        if ($mission->getStatus() !== MissionStatus::VALIDATED || $mission->getInvoiceGeneratedAt() !== null) {
            return false;
        }

        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(fc.id)')
            ->from(FinancialCalculation::class, 'fc')
            ->where('fc.mission = :mission')
            ->andWhere('fc.status = :locked')
            ->setParameter('mission', $mission)
            ->setParameter('locked', FinancialCalculationStatus::LOCKED)
            ->getQuery()
            ->getSingleScalarResult() === 0;
    }

    /** @throws CatalogueRequestException 409 si la mission n'accepte plus de modification d'encodage */
    public function assertResolutionAllowed(Mission $mission): void
    {
        if (!$this->isLocked($mission)) {
            return;
        }

        $reopen = $this->reopenAvailable($mission);
        throw new CatalogueRequestException(409, CatalogueRequestException::MISSION_LOCKED, $reopen
            ? sprintf('La mission #%d est déjà validée : rien ne peut y être ajouté directement. Rouvrez d\'abord son encodage (procédure de réouverture), puis résolvez la demande.', $mission->getId())
            : sprintf('La mission #%d est verrouillée (validée, facturée ou calcul financier verrouillé) : la demande ne peut plus y être résolue.', $mission->getId()),
            ['missionId' => $mission->getId(), 'missionStatus' => $mission->getStatus()->value, 'reopenAvailable' => $reopen],
        );
    }
}
