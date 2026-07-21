<?php

namespace App\Service;

use App\Entity\Firm;
use App\Entity\InterventionTypeRequest;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Exception\DraftAlreadyExistsException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Revue instrumentiste, Lot 3 — seul point de création/mutation métier d'un
 * MissionInterventionDraft (revue de conception : agrégat, pas une entité Doctrine
 * manipulable librement). Aucun contrôleur ni autre service ne doit construire un
 * MissionInterventionDraft directement ou muter son statut/resolvedMissionIntervention
 * en dehors de ce service.
 *
 * Ce commit n'introduit que createForRequest() — resolve(), ignore() et le repointage
 * de matériel arrivent dans des commits séparés (voir découpage validé).
 */
final class MissionInterventionDraftService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionEntryOrderAllocator $orderAllocator,
        private readonly AuditService $audit,
    ) {}

    /**
     * $request est construit par l'appelant (label/mission/createdBy déjà renseignés)
     * mais pas nécessairement encore persisté — cette méthode persiste la demande ET le
     * draft dans une seule transaction qu'elle ouvre elle-même (InterventionTypeRequestController
     * ne fait plus deux flush() indépendants : la création de la demande et celle du
     * draft sont atomiques — si l'une échoue, ni l'une ni l'autre ne subsiste).
     *
     * Un seul AuditEvent (MISSION_INTERVENTION_DRAFT_CREATED) couvre toute l'action —
     * voir le docblock du cas d'enum : aucune obligation d'audit distincte ne
     * pré-existait pour la création d'une InterventionTypeRequest seule.
     */
    public function createForRequest(
        InterventionTypeRequest $request,
        ?Firm $requestedFirm,
        User $actor,
    ): MissionInterventionDraft {
        $draft = null;

        $this->em->wrapInTransaction(function () use (&$draft, $request, $requestedFirm, $actor): void {
            $mission = $request->getMission();
            if ($mission === null) {
                throw new \LogicException('InterventionTypeRequest must belong to a Mission before a draft can be created for it.');
            }

            if ($request->getDraft() !== null) {
                throw new DraftAlreadyExistsException(sprintf(
                    'InterventionTypeRequest #%s already has a MissionInterventionDraft.',
                    $request->getId() ?? 'new',
                ));
            }

            $orderIndex = $this->orderAllocator->nextIndexForNewEntry($mission);

            $draft = new MissionInterventionDraft();
            $draft
                ->setMission($mission)
                ->setInterventionTypeRequest($request)
                ->setLabel($request->getLabel())
                ->setRequestedFirm($requestedFirm)
                ->setRequestedFirmNameSnapshot($requestedFirm?->getName())
                ->setOrderIndex($orderIndex)
                ->setStatus(MissionInterventionDraft::STATUS_OPEN)
                ->setCreatedBy($actor);

            // Cohérence en mémoire du côté inverse — voir InterventionTypeRequest::setDraft().
            $request->setDraft($draft);

            $this->em->persist($request);
            $this->em->persist($draft);
            // Flush intermédiaire : draft/request doivent avoir un id avant de pouvoir
            // les référencer dans le payload d'audit ci-dessous. Toujours dans la même
            // transaction (un seul commit) — flush() synchronise, ne valide pas.
            $this->em->flush();

            $this->audit->record($mission, $actor, AuditEventType::MISSION_INTERVENTION_DRAFT_CREATED, [
                'interventionTypeRequestId' => $request->getId(),
                'draftId' => $draft->getId(),
                'label' => $draft->getLabel(),
                'requestedFirmId' => $requestedFirm?->getId(),
                'requestedFirmNameSnapshot' => $draft->getRequestedFirmNameSnapshot(),
                'orderIndex' => $draft->getOrderIndex(),
            ]);
            $this->em->flush();
        });

        return $draft;
    }
}
