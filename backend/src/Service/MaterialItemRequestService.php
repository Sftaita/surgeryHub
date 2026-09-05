<?php

namespace App\Service;

use App\Dto\Request\MaterialItemRequestCreateRequest;
use App\Entity\MaterialItem;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\CatalogueRequestIgnoreReason;
use App\Exception\MaterialItemRequestAlreadyProcessedException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class MaterialItemRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialAttachmentResolver $attachmentResolver,
        private readonly AuditService $audit,
    ) {}

    /**
     * EPIC Revue instrumentiste, Lot 3, commit 4 — même principe que
     * InterventionService::createMaterialLine() : la cible (missionInterventionId OU
     * interventionDraftId) est résolue via MaterialAttachmentResolver, dans la même
     * transaction que la persistance (verrou pessimiste éventuel sur un draft).
     */
    public function create(Mission $mission, MaterialItemRequestCreateRequest $dto, User $createdBy): MaterialItemRequest
    {
        $req = null;

        $this->em->wrapInTransaction(function () use (&$req, $mission, $dto, $createdBy): void {
            $target = $this->attachmentResolver->resolve($mission, $dto->missionInterventionId, $dto->interventionDraftId);

            $req = new MaterialItemRequest();
            $req
                ->setMission($mission)
                ->setLabel($dto->label)
                ->setReferenceCode($dto->referenceCode)
                ->setComment($dto->comment)
                ->setCreatedBy($createdBy);

            if ($target instanceof MissionIntervention) {
                $req->setMissionIntervention($target);
            } elseif ($target instanceof MissionInterventionDraft) {
                $req->setInterventionDraft($target);
            }

            $this->em->persist($req);
            $this->em->flush();
        });

        return $req;
    }

    /**
     * Correctif workflow Demandes Catalogue (2026-09-04) — déplacé depuis
     * MaterialItemRequestManagerController::resolve() pour bénéficier du même verrouillage
     * pessimiste que MissionInterventionDraftService::resolve()/ignore() (section
     * "Concurrence et robustesse" de l'audit : deux managers ouvrant simultanément la
     * même demande ne doivent jamais produire deux MaterialLine). Comportement observable
     * inchangé — voir MaterialItemRequestManagerControllerTest pour la non-régression.
     *
     * @return array{0: MaterialItemRequest, 1: MaterialLine}
     */
    public function resolve(MaterialItemRequest $req, MaterialItem $materialItem, User $actor): array
    {
        $line = null;

        $this->em->wrapInTransaction(function () use (&$line, $req, $materialItem, $actor): void {
            $this->em->lock($req, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($req);

            if ($req->getStatus() !== MaterialItemRequest::STATUS_PENDING) {
                throw new MaterialItemRequestAlreadyProcessedException(sprintf(
                    'MaterialItemRequest #%d is %s, not PENDING — cannot be resolved (again).',
                    $req->getId(),
                    $req->getStatus(),
                ));
            }

            $req->setMaterialItem($materialItem);
            $req->setStatus(MaterialItemRequest::STATUS_RESOLVED);

            // Attachée à la MÊME cible que la demande (attachmentTarget(), jamais
            // getMissionIntervention() seul) : si la demande est encore rattachée à un
            // draft ouvert, la ligne doit l'être aussi, sinon elle ne serait jamais
            // reprise par MissionInterventionDraftService::repointMaterial() lors de la
            // résolution ultérieure du draft — elle resterait orpheline.
            $target = $req->attachmentTarget();
            $line = new MaterialLine();
            $line->setMission($req->getMission());
            if ($target instanceof MissionIntervention) {
                $line->setMissionIntervention($target);
            } elseif ($target instanceof MissionInterventionDraft) {
                $line->setInterventionDraft($target);
            }
            $line->setItem($materialItem);
            $line->setQuantity('1.00');
            $line->setComment($req->getComment());
            $line->setCreatedBy($actor);

            $this->em->persist($line);
            $this->em->flush();
        });

        return [$req, $line];
    }

    /**
     * Correctif workflow Demandes Catalogue (2026-09-04) — motif structuré + explication
     * toujours obligatoires (validés en amont par le controller), décideur/date posés
     * pour l'historique consultable. Un seul AuditEvent (MATERIAL_ITEM_REQUEST_IGNORED) —
     * jusqu'ici aucune obligation d'audit ne portait sur MaterialItemRequest.
     */
    public function ignore(MaterialItemRequest $req, CatalogueRequestIgnoreReason $reason, string $comment, User $actor): MaterialItemRequest
    {
        $this->em->wrapInTransaction(function () use ($req, $reason, $comment, $actor): void {
            $this->em->lock($req, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($req);

            if ($req->getStatus() !== MaterialItemRequest::STATUS_PENDING) {
                throw new MaterialItemRequestAlreadyProcessedException(sprintf(
                    'MaterialItemRequest #%d is %s, not PENDING — cannot be ignored (again).',
                    $req->getId(),
                    $req->getStatus(),
                ));
            }

            $req->setStatus(MaterialItemRequest::STATUS_IGNORED);
            $req->setIgnoreReason($reason);
            $req->setIgnoreComment($comment);
            $req->setDecidedBy($actor);
            $req->setDecidedAt(new \DateTimeImmutable());

            $this->em->flush();

            $this->audit->record($req->getMission(), $actor, AuditEventType::MATERIAL_ITEM_REQUEST_IGNORED, [
                'materialItemRequestId' => $req->getId(),
                'reason' => $reason->value,
                'comment' => mb_substr($comment, 0, 500),
                'label' => $req->getLabel(),
            ]);

            $this->em->flush();
        });

        return $req;
    }
}
