<?php

namespace App\Service;

use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Exception\DraftAlreadyExistsException;
use App\Exception\DraftAlreadyResolvedException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Revue instrumentiste, Lot 3 — seul point de création/mutation métier d'un
 * MissionInterventionDraft (revue de conception : agrégat, pas une entité Doctrine
 * manipulable librement). Aucun contrôleur ni autre service ne doit construire un
 * MissionInterventionDraft directement ou muter son statut/resolvedMissionIntervention
 * en dehors de ce service.
 *
 * Commit 5 introduit resolve() (résolution positive) — ignore() et le repointage en
 * masse via MATERIAL_REASSIGNED arrivent dans des commits séparés (voir découpage validé).
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

    /**
     * EPIC Revue instrumentiste, Lot 3, commit 5 — résolution positive : crée la
     * MissionIntervention réelle, repointe tout le matériel du draft vers elle (UPDATE
     * en masse, jamais un flush() par ligne — voir repointMaterial()), transitionne
     * InterventionTypeRequest→RESOLVED et MissionInterventionDraft→CONVERTED, audite.
     *
     * $draft peut être un objet potentiellement périmé (chargé avant l'appel) — verrouillé
     * puis rafraîchi ici avant toute décision, même principe que
     * MaterialAttachmentResolver::resolveDraft() (commit 4) : lock() ne réhydrate pas
     * seul les champs déjà en mémoire.
     *
     * L'orderIndex du draft est repris TEL QUEL (jamais MissionEntryOrderAllocator) : ce
     * n'est pas une nouvelle position, mais le remplacement d'une entrée provisoire déjà
     * réservée depuis sa création — la conversion ne modifie jamais l'ordre visuel de la
     * mission (ce slot n'a jamais pu être réattribué depuis, voir MissionEntryOrderAllocator).
     *
     * $interventionType et $firm sont déjà résolus/validés par l'appelant (voir
     * ActiveInterventionTypeResolver/ActiveFirmResolver, jamais dupliqués ici) — cette
     * méthode ne fait qu'appliquer le choix du manager, jamais de validation catalogue,
     * jamais de création de type/firme.
     *
     * label/requestedFirm/requestedFirmNameSnapshot du draft ne sont jamais réécrits ici
     * (instantané figé à la création, D-068) — même si $firm diffère de
     * $draft->getRequestedFirm().
     */
    public function resolve(
        MissionInterventionDraft $draft,
        InterventionType $interventionType,
        ?Firm $firm,
        User $actor,
    ): MissionIntervention {
        $intervention = null;

        $this->em->wrapInTransaction(function () use (&$intervention, $draft, $interventionType, $firm, $actor): void {
            $this->em->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($draft);

            if ($draft->getStatus() !== MissionInterventionDraft::STATUS_OPEN) {
                throw new DraftAlreadyResolvedException(sprintf(
                    'MissionInterventionDraft #%d is %s, not OPEN — cannot be resolved (again).',
                    $draft->getId(),
                    $draft->getStatus(),
                ));
            }

            $request = $draft->getInterventionTypeRequest();
            if ($request->getStatus() !== InterventionTypeRequest::STATUS_PENDING) {
                throw new DraftAlreadyResolvedException(sprintf(
                    'InterventionTypeRequest #%d is %s, not PENDING — cannot be resolved (again).',
                    $request->getId(),
                    $request->getStatus(),
                ));
            }

            $mission = $draft->getMission();
            if ($request->getMission()?->getId() !== $mission->getId()) {
                throw new \LogicException(sprintf(
                    'MissionInterventionDraft #%d and its InterventionTypeRequest #%d belong to different missions — invalid state.',
                    $draft->getId(),
                    $request->getId(),
                ));
            }

            $intervention = new MissionIntervention();
            $intervention
                ->setMission($mission)
                ->setInterventionType($interventionType)
                ->setPrimaryFirm($firm)
                ->setCode($interventionType->getCode())
                ->setLabel($interventionType->getLabel())
                ->setOrderIndex($draft->getOrderIndex());

            $this->em->persist($intervention);
            // Flush intermédiaire : la MissionIntervention doit avoir un id avant que le
            // repointage en masse (bulk UPDATE) puisse écrire sa FK — toujours dans la
            // même transaction (un seul commit).
            $this->em->flush();

            $moved = $this->repointMaterial($draft, $intervention);

            $request->setResolvedInterventionType($interventionType);
            $request->setStatus(InterventionTypeRequest::STATUS_RESOLVED);

            $draft->setStatus(MissionInterventionDraft::STATUS_CONVERTED);
            $draft->setResolvedMissionIntervention($intervention);

            $this->em->flush();

            $this->audit->record($mission, $actor, AuditEventType::MISSION_INTERVENTION_DRAFT_RESOLVED, [
                'interventionTypeRequestId' => $request->getId(),
                'draftId' => $draft->getId(),
                'missionInterventionId' => $intervention->getId(),
                'interventionTypeId' => $interventionType->getId(),
                'interventionTypeCode' => $interventionType->getCode(),
                'finalFirmId' => $firm?->getId(),
                'requestedFirmId' => $draft->getRequestedFirm()?->getId(),
                'requestedFirmNameSnapshot' => $draft->getRequestedFirmNameSnapshot(),
                'orderIndex' => $intervention->getOrderIndex(),
                'materialLinesMovedCount' => $moved['lines'],
                'materialItemRequestsMovedCount' => $moved['materialItemRequests'],
            ]);
            $this->em->flush();
        });

        return $intervention;
    }

    /**
     * UPDATE en masse (DQL, jamais un flush() par ligne) — repointe toute MaterialLine/
     * MaterialItemRequest du draft vers la nouvelle intervention. Après cette méthode,
     * aucune ligne ne référence plus le draft. Le compte de lignes affectées vient
     * directement de Query::execute() (nombre de lignes SQL modifiées), pas d'un second
     * aller-retour de comptage.
     *
     * Centralisée ici plutôt qu'un composant séparé (pas de second appelant pour
     * l'instant) — le futur ignore(REASSIGN) (commit séparé, hors périmètre ici) aura
     * probablement besoin de la même logique : à extraire à ce moment-là si un second
     * appelant réel se confirme, pas avant.
     *
     * @return array{lines: int, materialItemRequests: int}
     */
    private function repointMaterial(MissionInterventionDraft $draft, MissionIntervention $intervention): array
    {
        $linesMoved = (int) $this->em->createQuery(sprintf(
            'UPDATE %s l SET l.missionIntervention = :intervention, l.interventionDraft = NULL WHERE l.interventionDraft = :draft',
            MaterialLine::class,
        ))
            ->setParameter('intervention', $intervention)
            ->setParameter('draft', $draft)
            ->execute();

        $requestsMoved = (int) $this->em->createQuery(sprintf(
            'UPDATE %s r SET r.missionIntervention = :intervention, r.interventionDraft = NULL WHERE r.interventionDraft = :draft',
            MaterialItemRequest::class,
        ))
            ->setParameter('intervention', $intervention)
            ->setParameter('draft', $draft)
            ->execute();

        return ['lines' => $linesMoved, 'materialItemRequests' => $requestsMoved];
    }
}
