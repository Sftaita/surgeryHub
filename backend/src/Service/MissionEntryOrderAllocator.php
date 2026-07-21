<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Revue instrumentiste, Lot 3 — unique propriétaire de l'allocation d'une position
 * pour une nouvelle entrée (intervention réelle ou draft) dans la liste ordonnée d'une
 * mission. Aucun autre composant ne doit reproduire ce calcul (auparavant : aucune
 * allocation serveur n'existait — orderIndex était entièrement choisi et envoyé par le
 * client, voir revue de conception / InterventionController::create()).
 *
 * `MAX(orderIndex)+1` sur l'union interventions réelles + drafts d'une mission, sous
 * verrou pessimiste sur la mission — garantit qu'un draft résolu reprend exactement sa
 * position d'origine (le slot lui a été réservé dès sa création, jamais réattribué
 * depuis) et qu'aucune collision n'est possible entre les deux types d'entrées.
 */
final class MissionEntryOrderAllocator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Doit être appelée à l'intérieur d'une transaction déjà ouverte par l'appelant —
     * EntityManager::lock() avec PESSIMISTIC_WRITE lève TransactionRequiredException
     * sinon (Doctrine applique lui-même cette exigence, pas de vérification manuelle
     * ici). N'appelle jamais flush() et ne crée aucune entité : uniquement la lecture
     * verrouillée et le calcul de la prochaine position.
     */
    public function nextIndexForNewEntry(Mission $mission): int
    {
        $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);

        $maxIntervention = $this->em->createQueryBuilder()
            ->select('MAX(i.orderIndex)')
            ->from(MissionIntervention::class, 'i')
            ->where('i.mission = :mission')
            ->setParameter('mission', $mission)
            ->getQuery()
            ->getSingleScalarResult();

        $maxDraft = $this->em->createQueryBuilder()
            ->select('MAX(d.orderIndex)')
            ->from(MissionInterventionDraft::class, 'd')
            ->where('d.mission = :mission')
            ->setParameter('mission', $mission)
            ->getQuery()
            ->getSingleScalarResult();

        $max = max(
            $maxIntervention !== null ? (int) $maxIntervention : -1,
            $maxDraft !== null ? (int) $maxDraft : -1,
        );

        return $max + 1;
    }
}
