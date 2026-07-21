<?php

namespace App\Service;

use App\Entity\MaterialAttachmentTarget;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Exception\ConflictingMaterialAttachmentInputException;
use App\Exception\MaterialAttachmentTargetClosedException;
use App\Exception\MaterialAttachmentTargetNotFoundException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 4 — seul point de résolution d'une cible
 * d'attachement de matériel à partir d'identifiants entrants (HTTP). Distinct de
 * MaterialLine::attachmentTarget()/MaterialItemRequest::attachmentTarget() (commit 2,
 * lisent une cible déjà persistée sur une entité déjà construite) : ce résolveur
 * construit la cible à partir de rien, avec verrouillage — les contrôleurs ne doivent
 * jamais interpréter eux-mêmes le statut d'un draft, uniquement consommer le résultat
 * de resolve().
 *
 * Doit être appelé À L'INTÉRIEUR d'une transaction déjà ouverte par l'appelant — le
 * verrou pessimiste posé sur un draft l'exige (même contrat que
 * MissionEntryOrderAllocator::nextIndexForNewEntry(), Doctrine lève
 * TransactionRequiredException sinon).
 */
final class MaterialAttachmentResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @throws ConflictingMaterialAttachmentInputException les deux ids sont fournis
     * @throws MaterialAttachmentTargetNotFoundException la cible n'existe pas ou n'appartient pas à $mission
     * @throws MaterialAttachmentTargetClosedException le draft est KEPT_AS_HISTORY
     * @throws \App\Exception\InvalidDraftResolutionStateException le draft est CONVERTED/MATERIAL_REASSIGNED sans resolvedMissionIntervention (état invalide, jamais une création partielle)
     */
    public function resolve(
        Mission $mission,
        ?int $missionInterventionId,
        ?int $interventionDraftId,
    ): ?MaterialAttachmentTarget {
        if ($missionInterventionId !== null && $interventionDraftId !== null) {
            throw new ConflictingMaterialAttachmentInputException(
                'missionInterventionId et interventionDraftId ne peuvent pas être fournis simultanément.',
            );
        }

        if ($missionInterventionId !== null) {
            return $this->resolveRealIntervention($mission, $missionInterventionId);
        }

        if ($interventionDraftId !== null) {
            return $this->resolveDraft($mission, $interventionDraftId);
        }

        // Aucune cible fournie — autorisé (matériel non rattaché à une intervention
        // précise, sémantique préexistante inchangée, voir docblock de
        // MaterialLine::$missionIntervention).
        return null;
    }

    private function resolveRealIntervention(Mission $mission, int $missionInterventionId): MissionIntervention
    {
        $intervention = $this->em->find(MissionIntervention::class, $missionInterventionId);
        if (!$intervention instanceof MissionIntervention || $intervention->getMission()?->getId() !== $mission->getId()) {
            throw new MaterialAttachmentTargetNotFoundException('Mission intervention not found on this mission.');
        }

        return $intervention;
    }

    private function resolveDraft(Mission $mission, int $interventionDraftId): MaterialAttachmentTarget
    {
        $draft = $this->em->find(MissionInterventionDraft::class, $interventionDraftId);
        if (!$draft instanceof MissionInterventionDraft || $draft->getMission()?->getId() !== $mission->getId()) {
            throw new MaterialAttachmentTargetNotFoundException('Mission intervention draft not found on this mission.');
        }

        // Verrouille AVANT de lire le statut — puis rafraîchit l'entité : lock() pose le
        // verrou DB (SELECT ... FOR UPDATE) mais ne réhydrate pas automatiquement les
        // champs déjà chargés en mémoire. Sans refresh(), une transition concurrente
        // committée entre le find() et le lock() resterait invisible ici.
        $this->em->lock($draft, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($draft);

        if ($draft->acceptsNewMaterial()) {
            return $draft;
        }

        // redirectTarget() : non-null pour CONVERTED/MATERIAL_REASSIGNED (lève
        // InvalidDraftResolutionStateException si l'état est incohérent — jamais
        // rattrapé ici, une création partielle serait pire qu'un 500 explicite) ; null
        // pour KEPT_AS_HISTORY, seul cas encore bloquant.
        $redirect = $draft->redirectTarget();
        if ($redirect !== null) {
            return $redirect;
        }

        throw new MaterialAttachmentTargetClosedException(
            'This intervention draft has been closed as uncatalogued history and no longer accepts new material.',
        );
    }
}
