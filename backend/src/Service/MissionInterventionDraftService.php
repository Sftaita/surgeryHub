<?php

namespace App\Service;

use App\Entity\Firm;
use App\Entity\InterventionTypeRequest;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
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
    ) {}

    /**
     * $request est construit par l'appelant (label/mission/createdBy déjà renseignés)
     * mais pas nécessairement encore persisté — cette méthode persiste la demande ET le
     * draft dans une seule transaction qu'elle ouvre elle-même : pas encore de
     * contrôleur HTTP qui l'appelle dans ce commit, donc pas de transaction ambiante à
     * réutiliser. Le commit qui branche InterventionTypeRequestController::create()
     * devra composer avec cette transaction unique plutôt que d'en ouvrir une seconde.
     *
     * N'écrit pas d'AuditEvent ici : ajouté dans le commit qui branche cette méthode au
     * workflow HTTP réel, pour ne pas introduire un événement d'audit consommé par rien
     * avant que le flux existe.
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
            $this->em->flush();
        });

        return $draft;
    }
}
