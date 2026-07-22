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
use App\Enum\MissionInterventionDraftIgnoreStrategy;
use App\Exception\DraftAlreadyExistsException;
use App\Exception\DraftAlreadyResolvedException;
use App\Exception\MaterialAttachmentTargetNotFoundException;
use App\Exception\MissingIgnoreStrategyException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Revue instrumentiste, Lot 3 — seul point de création/mutation métier d'un
 * MissionInterventionDraft (revue de conception : agrégat, pas une entité Doctrine
 * manipulable librement). Aucun contrôleur ni autre service ne doit construire un
 * MissionInterventionDraft directement ou muter son statut/resolvedMissionIntervention
 * en dehors de ce service.
 *
 * Commit 5 a introduit resolve() (résolution positive). Commit 6 introduit ignore() —
 * KEEP_AS_HISTORY et REASSIGN, seules deux stratégies restantes pour clore un draft OPEN.
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
     * EPIC Revue instrumentiste, Lot 3, commit 6 — ignorance d'une demande : deux
     * dénouements possibles pour son draft, jamais une suppression.
     *
     * - $strategy === null : autorisé UNIQUEMENT si le draft ne porte aucun matériel —
     *   équivaut alors implicitement à KEEP_AS_HISTORY (rien à conserver ni à repointer).
     *   Si du matériel existe, refusé explicitement (MissingIgnoreStrategyException,
     *   422) : perdre la trace du matériel déjà déclaré par l'instrumentiste sans un
     *   choix explicite du manager serait un effet de bord silencieux.
     * - KEEP_AS_HISTORY : le matériel reste attaché au draft, AUCUNE ligne n'est
     *   modifiée ni déplacée — seul le statut du draft change. acceptsNewMaterial()
     *   devient false (nouvelles écritures rejetées en 409 par MaterialAttachmentResolver,
     *   commit 4, sans changement nécessaire ici) et billingEligibility() devient
     *   HISTORY_ONLY (voir MissionInterventionDraft, déjà câblé depuis le commit 2).
     * - REASSIGN : tout le matériel du draft est repointé (bulk UPDATE, voir
     *   repointMaterial() — même méthode que resolve(), généralisée à n'importe quelle
     *   MissionIntervention cible) vers $reassignTarget, qui DOIT appartenir à la même
     *   mission que le draft (vérifié ici sous verrou, jamais délégué uniquement à
     *   l'appelant — même invariant défensif que la cohérence draft/request de
     *   resolve()) ; sinon MaterialAttachmentTargetNotFoundException (404, même
     *   convention de non-divulgation qu'un target de matériel introuvable, commit 4).
     *   redirectTarget() du draft pointe alors vers $reassignTarget, donc toute écriture
     *   tardive sur l'ancien draftId continue d'être transparente redirigée (déjà câblé
     *   par MaterialAttachmentResolver::resolveDraft(), commit 4 — rien à changer ici).
     *
     * Dans les deux cas : InterventionTypeRequest → IGNORED (jamais RESOLVED, aucune
     * MissionIntervention n'est créée par cette méthode). Un seul AuditEvent distinct par
     * dénouement réel (voir AuditEventType) — jamais un seul type paramétré par un champ
     * "strategy" dans le payload.
     */
    public function ignore(
        MissionInterventionDraft $draft,
        ?MissionInterventionDraftIgnoreStrategy $strategy,
        ?MissionIntervention $reassignTarget,
        User $actor,
    ): MissionInterventionDraft {
        $this->em->wrapInTransaction(function () use ($draft, $strategy, $reassignTarget, $actor): void {
            $this->em->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($draft);

            if ($draft->getStatus() !== MissionInterventionDraft::STATUS_OPEN) {
                throw new DraftAlreadyResolvedException(sprintf(
                    'MissionInterventionDraft #%d is %s, not OPEN — cannot be ignored (again).',
                    $draft->getId(),
                    $draft->getStatus(),
                ));
            }

            $request = $draft->getInterventionTypeRequest();
            if ($request->getStatus() !== InterventionTypeRequest::STATUS_PENDING) {
                throw new DraftAlreadyResolvedException(sprintf(
                    'InterventionTypeRequest #%d is %s, not PENDING — cannot be ignored (again).',
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

            $materialCount = $this->countMaterial($draft);
            $hasMaterial = $materialCount['lines'] > 0 || $materialCount['materialItemRequests'] > 0;

            $effectiveStrategy = $strategy;
            if ($effectiveStrategy === null) {
                if ($hasMaterial) {
                    throw new MissingIgnoreStrategyException(sprintf(
                        'MissionInterventionDraft #%d has attached material — an explicit strategy (KEEP_AS_HISTORY or REASSIGN) is required.',
                        $draft->getId(),
                    ));
                }
                $effectiveStrategy = MissionInterventionDraftIgnoreStrategy::KEEP_AS_HISTORY;
            }

            if ($effectiveStrategy === MissionInterventionDraftIgnoreStrategy::REASSIGN) {
                if ($reassignTarget === null) {
                    throw new \LogicException('REASSIGN strategy requires a target MissionIntervention.');
                }
                if ($reassignTarget->getMission()->getId() !== $mission->getId()) {
                    throw new MaterialAttachmentTargetNotFoundException(
                        'Mission intervention not found on this mission.',
                    );
                }

                $moved = $this->repointMaterial($draft, $reassignTarget);

                $draft->setStatus(MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED);
                $draft->setResolvedMissionIntervention($reassignTarget);
                $request->setStatus(InterventionTypeRequest::STATUS_IGNORED);

                $this->em->flush();

                $this->audit->record($mission, $actor, AuditEventType::MISSION_INTERVENTION_DRAFT_MATERIAL_REASSIGNED, [
                    'interventionTypeRequestId' => $request->getId(),
                    'draftId' => $draft->getId(),
                    'strategy' => $effectiveStrategy->value,
                    'missionInterventionId' => $reassignTarget->getId(),
                    'requestedFirmId' => $draft->getRequestedFirm()?->getId(),
                    'requestedFirmNameSnapshot' => $draft->getRequestedFirmNameSnapshot(),
                    'label' => $draft->getLabel(),
                    'materialLinesCount' => $moved['lines'],
                    'materialItemRequestsCount' => $moved['materialItemRequests'],
                ]);
            } else {
                // KEEP_AS_HISTORY — le matériel reste attaché au draft, rien n'est
                // modifié sur MaterialLine/MaterialItemRequest, seul le statut du draft
                // change.
                $draft->setStatus(MissionInterventionDraft::STATUS_KEPT_AS_HISTORY);
                $request->setStatus(InterventionTypeRequest::STATUS_IGNORED);

                $this->em->flush();

                $this->audit->record($mission, $actor, AuditEventType::MISSION_INTERVENTION_DRAFT_IGNORED_AS_HISTORY, [
                    'interventionTypeRequestId' => $request->getId(),
                    'draftId' => $draft->getId(),
                    'strategy' => $effectiveStrategy->value,
                    'missionInterventionId' => null,
                    'requestedFirmId' => $draft->getRequestedFirm()?->getId(),
                    'requestedFirmNameSnapshot' => $draft->getRequestedFirmNameSnapshot(),
                    'label' => $draft->getLabel(),
                    'materialLinesCount' => $materialCount['lines'],
                    'materialItemRequestsCount' => $materialCount['materialItemRequests'],
                ]);
            }

            $this->em->flush();
        });

        return $draft;
    }

    /**
     * Compte le matériel attaché au draft sans hydrater les collections — sert à la fois
     * à décider si une stratégie explicite est requise (ignore()) et à renseigner
     * l'audit KEEP_AS_HISTORY (où repointMaterial() n'est jamais appelée, donc aucun
     * compte d'affectation Query::execute() disponible autrement).
     *
     * @return array{lines: int, materialItemRequests: int}
     */
    private function countMaterial(MissionInterventionDraft $draft): array
    {
        $lines = (int) $this->em->createQuery(sprintf(
            'SELECT COUNT(l.id) FROM %s l WHERE l.interventionDraft = :draft',
            MaterialLine::class,
        ))->setParameter('draft', $draft)->getSingleScalarResult();

        $materialItemRequests = (int) $this->em->createQuery(sprintf(
            'SELECT COUNT(r.id) FROM %s r WHERE r.interventionDraft = :draft',
            MaterialItemRequest::class,
        ))->setParameter('draft', $draft)->getSingleScalarResult();

        return ['lines' => $lines, 'materialItemRequests' => $materialItemRequests];
    }

    /**
     * UPDATE en masse (DQL, jamais un flush() par ligne) — repointe toute MaterialLine/
     * MaterialItemRequest du draft vers la nouvelle intervention. Après cette méthode,
     * aucune ligne ne référence plus le draft. Le compte de lignes affectées vient
     * directement de Query::execute() (nombre de lignes SQL modifiées), pas d'un second
     * aller-retour de comptage.
     *
     * Commit 6 — désormais appelée depuis deux points (resolve() et ignore(REASSIGN)),
     * généralisée dès le départ à n'importe quelle MissionIntervention cible (jamais
     * spécifique à une intervention nouvellement créée). Reste une méthode privée de ce
     * service (toujours aucun appelant en dehors de MissionInterventionDraftService) —
     * pas de raison de l'extraire tant qu'aucun consommateur externe ne se confirme.
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
