<?php

namespace App\Service;

use App\Entity\ChoiceOption;
use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\CatalogueRequestIgnoreReason;
use App\Enum\MissionInterventionDraftIgnoreStrategy;
use App\Exception\CatalogueRequestException;
use App\Exception\DraftAlreadyExistsException;
use App\Exception\DraftAlreadyResolvedException;
use App\Exception\IncompatibleChoiceMaterialException;
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
        private readonly InterventionRequirementsService $requirements,
        private readonly CatalogueResolutionGuard $resolutionGuard,
        private readonly CatalogueFactory $catalogue,
        private readonly ActiveFirmResolver $firmResolver,
        private readonly ActiveInterventionTypeResolver $interventionTypeResolver,
    ) {}

    public const OFFERING_FEE_APPLICABLE = 'FEE_APPLICABLE';
    public const OFFERING_NO_FEE = 'NO_FEE';
    public const OFFERING_LEAVE_UNCONFIGURED = 'LEAVE_UNCONFIGURED';

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
        ?string $proposedFirmName = null,
        bool $withoutFirm = false,
    ): MissionInterventionDraft {
        $draft = null;

        $this->em->wrapInTransaction(function () use (&$draft, $request, $requestedFirm, $actor, $proposedFirmName, $withoutFirm): void {
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
                // D-146 bis — firme absente du catalogue proposée en texte libre (jamais créée).
                ->setProposedFirmName($requestedFirm === null && $proposedFirmName !== null && $proposedFirmName !== '' ? $proposedFirmName : null)
                // D-178 — « Aucune firme » choisi explicitement, conservé jusqu'à la résolution.
                ->setWithoutFirm($withoutFirm && $requestedFirm === null)
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
                'proposedFirmName' => $draft->getProposedFirmName(),
                'withoutFirm' => $draft->isWithoutFirm(),
                'orderIndex' => $draft->getOrderIndex(),
            ]);
            $this->em->flush();
        });

        return $draft;
    }

    /**
     * D-146 bis — résolution COMPLÈTE et ATOMIQUE d'une demande d'intervention par le
     * manager, en une seule transaction (remplace l'enchaînement « POST /intervention-types
     * puis /resolve » côté frontend, qui pouvait laisser un type créé sans résolution) :
     *
     *  1. type : existant (`interventionTypeId`) OU créé (`newInterventionType` {code, label}) ;
     *  2. firme (OBLIGATOIRE) : existante (`firmId`) OU créée (`newFirmName`) — la firme
     *     proposée par l'instrumentiste n'est qu'une proposition, c'est celle validée ici
     *     qui est enregistrée sur la nouvelle intervention, et sur elle seule ;
     *  3. prestation firme × type absente → décision explicite exigée
     *     (`offeringDecision` : FEE_APPLICABLE | NO_FEE | LEAVE_UNCONFIGURED, jamais « Pas
     *     de forfait » par défaut, jamais un montant) ;
     *  4. réponses obligatoires (`representativePresent`, `selectedChoiceOptionId`) validées
     *     par les mêmes règles que la création classique (InterventionRequirementsService) ;
     *  5. résolution du draft (resolve() ci-dessous : intervention, matériel, traçabilité).
     *
     * Le moindre refus (mission verrouillée, firme ou code existant, décision manquante,
     * réponse obligatoire absente, matériel incompatible) annule TOUT.
     *
     * @param array<string, mixed> $input
     * @return array{intervention: MissionIntervention, createdFirm: bool, createdInterventionType: bool, createdOffering: bool, offeringDecision: ?string}
     */
    public function resolveRequest(MissionInterventionDraft $draft, array $input, User $actor): array
    {
        $result = null;

        $this->em->wrapInTransaction(function () use (&$result, $draft, $input, $actor): void {
            $this->em->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($draft);
            $this->assertResolvable($draft);
            $this->resolutionGuard->assertResolutionAllowed($draft->getMission());

            $createdType = false;
            $typeId = $input['interventionTypeId'] ?? null;
            $newType = is_array($input['newInterventionType'] ?? null) ? $input['newInterventionType'] : null;
            if ($typeId !== null && $newType !== null) {
                throw new CatalogueRequestException(422, CatalogueRequestException::INTERVENTION_TYPE_REQUIRED, 'Choisissez une intervention existante OU créez-en une nouvelle, pas les deux.');
            }
            if ($typeId !== null) {
                $type = $this->interventionTypeResolver->resolveActive((int) $typeId);
            } elseif ($newType !== null) {
                $type = $this->catalogue->createInterventionType((string) ($newType['code'] ?? ''), (string) ($newType['label'] ?? ''));
                // D-178 — demande sans firme : le manager peut autoriser explicitement le type
                // qu'il crée à être encodé sans firme (jamais implicitement).
                if ($draft->isWithoutFirm() && ($newType['allowedWithoutFirm'] ?? false) === true) {
                    $type->setAllowedWithoutFirm(true);
                }
                $createdType = true;
            } else {
                throw new CatalogueRequestException(422, CatalogueRequestException::INTERVENTION_TYPE_REQUIRED, 'Choisissez l\'intervention existante correspondante ou créez la nouvelle intervention.');
            }

            $createdFirm = false;
            $firmId = $input['firmId'] ?? null;
            $newFirmName = CatalogueFactory::normalizeName((string) ($input['newFirmName'] ?? ''));

            // D-178 — demande faite « Aucune firme » : l'information est conservée telle quelle,
            // l'intervention est créée sans firme avec les mêmes contrôles qu'un encodage direct
            // (type autorisé sans firme, InterventionRequirementsService::assertFirmChoice()).
            if ($draft->isWithoutFirm()) {
                if ($firmId !== null || $newFirmName !== '') {
                    throw new CatalogueRequestException(422, CatalogueRequestException::FIRM_AMBIGUOUS, 'Cette demande a été faite sans firme et se résout donc sans firme. Ignorez-la si l\'intervention a en réalité été réalisée avec une firme.');
                }
                $this->em->flush();
                $intervention = $this->resolve($draft, $type, null, $actor, null, null, [
                    'createdFirm' => false,
                    'createdInterventionType' => $createdType,
                    'createdOffering' => false,
                    'offeringDecision' => null,
                    'withoutFirm' => true,
                ]);
                $result = [
                    'intervention' => $intervention,
                    'createdFirm' => false,
                    'createdInterventionType' => $createdType,
                    'createdOffering' => false,
                    'offeringDecision' => null,
                ];

                return;
            }

            if ($firmId !== null && $newFirmName !== '') {
                throw new CatalogueRequestException(422, CatalogueRequestException::FIRM_AMBIGUOUS, 'Choisissez une firme existante OU créez une nouvelle firme, pas les deux.');
            }
            if ($firmId !== null) {
                $firm = $this->firmResolver->resolveActive((int) $firmId);
            } elseif ($newFirmName !== '') {
                $firm = $this->catalogue->createFirm($newFirmName);
                $createdFirm = true;
            } else {
                throw new CatalogueRequestException(422, CatalogueRequestException::FIRM_REQUIRED, InterventionRequirementsService::FIRM_QUESTION . ' La firme est obligatoire pour résoudre la demande.');
            }

            // Ids nécessaires aux lectures de politique (prestation, délégué, choix) ci-dessous.
            $this->em->flush();

            $createdOffering = false;
            $decision = null;
            if ($this->catalogue->findOffering($firm, $type) === null) {
                $decision = $input['offeringDecision'] ?? null;
                if (!in_array($decision, [self::OFFERING_FEE_APPLICABLE, self::OFFERING_NO_FEE, self::OFFERING_LEAVE_UNCONFIGURED], true)) {
                    throw new CatalogueRequestException(422, CatalogueRequestException::OFFERING_DECISION_REQUIRED, sprintf(
                        'La prestation « %s » n\'est pas encore configurée chez %s : indiquez si un forfait est applicable (le montant se configure ensuite dans Catalogue > Prestations), si aucun forfait n\'est prévu, ou laissez-la à configurer.',
                        $type->getLabel(),
                        $firm->getName(),
                    ), ['firm' => ['id' => $firm->getId(), 'name' => $firm->getName()], 'interventionType' => ['id' => $type->getId(), 'code' => $type->getCode(), 'label' => $type->getLabel()]]);
                }
                if ($decision !== self::OFFERING_LEAVE_UNCONFIGURED) {
                    $this->catalogue->createOffering($firm, $type, $decision === self::OFFERING_FEE_APPLICABLE);
                    $createdOffering = true;
                    $this->em->flush();
                }
            }

            $representativePresent = array_key_exists('representativePresent', $input) && $input['representativePresent'] !== null
                ? (bool) $input['representativePresent']
                : null;
            $choiceId = $input['selectedChoiceOptionId'] ?? null;
            $choice = $choiceId !== null ? $this->requirements->resolveChoiceOption((int) $choiceId, $firm, $type) : null;

            $intervention = $this->resolve($draft, $type, $firm, $actor, $representativePresent, $choice, [
                'createdFirm' => $createdFirm,
                'createdInterventionType' => $createdType,
                'createdOffering' => $createdOffering,
                'offeringDecision' => $decision,
            ]);

            $result = [
                'intervention' => $intervention,
                'createdFirm' => $createdFirm,
                'createdInterventionType' => $createdType,
                'createdOffering' => $createdOffering,
                'offeringDecision' => $decision,
            ];
        });

        return $result;
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
     * ActiveInterventionTypeResolver/ActiveFirmResolver, jamais dupliqués ici) ; la
     * création éventuelle de firme/type/prestation est orchestrée par resolveRequest().
     *
     * D-146 bis — mêmes invariants métier que la création classique
     * (InterventionRequirementsService, jamais une règle divergente) :
     *  - firme OBLIGATOIRE, appliquée à la seule intervention créée (jamais aux autres
     *    interventions de la mission, jamais déduite de la marque du matériel) ;
     *  - réponse délégué et système choisi exigés quand la prestation les requiert
     *    (jamais un « Non » ni une option inventés) ;
     *  - aucun matériel transféré depuis le draft incompatible avec le système choisi
     *    (409, jamais une suppression silencieuse) ;
     *  - jamais sur une mission validée/verrouillée/facturée (CatalogueResolutionGuard).
     * Décideur/date posés sur la demande (même champs que ignore()).
     *
     * label/requestedFirm/requestedFirmNameSnapshot du draft ne sont jamais réécrits ici
     * (instantané figé à la création, D-068) — même si $firm diffère de
     * $draft->getRequestedFirm().
     *
     * @param array<string, mixed> $catalogueMeta créations faites par resolveRequest() (audit)
     */
    public function resolve(
        MissionInterventionDraft $draft,
        InterventionType $interventionType,
        ?Firm $firm,
        User $actor,
        ?bool $representativePresent = null,
        ?ChoiceOption $selectedChoiceOption = null,
        array $catalogueMeta = [],
    ): MissionIntervention {
        $intervention = null;

        $this->em->wrapInTransaction(function () use (&$intervention, $draft, $interventionType, $firm, $actor, $representativePresent, $selectedChoiceOption, $catalogueMeta): void {
            $this->em->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($draft);
            $this->assertResolvable($draft);

            $request = $draft->getInterventionTypeRequest();
            $mission = $draft->getMission();

            $this->resolutionGuard->assertResolutionAllowed($mission);
            // D-178 — même contrôle qu'un encodage direct : une firme, ou (demande faite
            // « Aucune firme ») un type que le catalogue autorise actuellement sans firme.
            $withoutFirm = $draft->isWithoutFirm();
            $this->requirements->assertFirmChoice($firm, $withoutFirm, $interventionType);
            if ($selectedChoiceOption !== null) {
                // Revalide l'appartenance de l'option à la prestation (firme × type) effective.
                $selectedChoiceOption = $this->requirements->resolveChoiceOption((int) $selectedChoiceOption->getId(), $firm, $interventionType);
            }
            $this->requirements->assertRepresentativePresenceAnswered($firm, $interventionType, $representativePresent);
            $this->requirements->assertChoiceAnswered($firm, $interventionType, $selectedChoiceOption);

            $intervention = new MissionIntervention();
            $intervention
                ->setMission($mission)
                ->setInterventionType($interventionType)
                ->setPrimaryFirm($firm)
                ->setWithoutFirm($withoutFirm)
                ->setCode($interventionType->getCode())
                ->setLabel($interventionType->getLabel())
                ->setOrderIndex($draft->getOrderIndex())
                ->setRepresentativePresent($representativePresent)
                ->setSelectedChoiceOption($selectedChoiceOption);

            $this->em->persist($intervention);
            // Flush intermédiaire : la MissionIntervention doit avoir un id avant que le
            // repointage en masse (bulk UPDATE) puisse écrire sa FK — toujours dans la
            // même transaction (un seul commit).
            $this->em->flush();

            $moved = $this->repointMaterial($draft, $intervention);
            $this->assertMovedMaterialCompatible($intervention, $selectedChoiceOption);

            $request->setResolvedInterventionType($interventionType);
            $request->setStatus(InterventionTypeRequest::STATUS_RESOLVED);
            $request->setDecidedBy($actor);
            $request->setDecidedAt(new \DateTimeImmutable());

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
                'proposedFirmName' => $draft->getProposedFirmName(),
                'representativePresent' => $representativePresent,
                'selectedChoiceOptionId' => $selectedChoiceOption?->getId(),
                'orderIndex' => $intervention->getOrderIndex(),
                'materialLinesMovedCount' => $moved['lines'],
                'materialItemRequestsMovedCount' => $moved['materialItemRequests'],
            ] + $catalogueMeta);
            $this->em->flush();
        });

        return $intervention;
    }

    private function assertResolvable(MissionInterventionDraft $draft): void
    {
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

        if ($request->getMission()?->getId() !== $draft->getMission()->getId()) {
            throw new \LogicException(sprintf(
                'MissionInterventionDraft #%d and its InterventionTypeRequest #%d belong to different missions — invalid state.',
                $draft->getId(),
                $request->getId(),
            ));
        }
    }

    /**
     * D-146 bis / D-111 — le matériel déjà déclaré sur le draft (repointé en masse, donc
     * absent de la collection en mémoire) ne doit pas contredire le système choisi : un
     * matériel lié à une AUTRE option du groupe est refusé (409, rollback), jamais retiré.
     */
    private function assertMovedMaterialCompatible(MissionIntervention $intervention, ?ChoiceOption $selected): void
    {
        if ($selected === null) {
            return;
        }

        $incompatibleItemIds = [];
        foreach ($selected->getGroup()->getOptions() as $option) {
            if ($option->getId() !== $selected->getId() && $option->getMaterialItem() !== null) {
                $incompatibleItemIds[] = $option->getMaterialItem()->getId();
            }
        }
        if ($incompatibleItemIds === []) {
            return;
        }

        $labels = $this->em->createQuery(sprintf(
            'SELECT i.label FROM %s l JOIN l.item i WHERE l.missionIntervention = :intervention AND i.id IN (:ids)',
            MaterialLine::class,
        ))->setParameter('intervention', $intervention)->setParameter('ids', $incompatibleItemIds)->getSingleColumnResult();

        if ($labels !== []) {
            throw new IncompatibleChoiceMaterialException(sprintf(
                'Le matériel « %s » déclaré sur cette demande n\'est pas compatible avec « %s » : choisissez le système correspondant, ou corrigez d\'abord le matériel.',
                $labels[0],
                $selected->getLabel(),
            ));
        }
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
        ?CatalogueRequestIgnoreReason $ignoreReason = null,
        ?string $ignoreComment = null,
    ): MissionInterventionDraft {
        $this->em->wrapInTransaction(function () use ($draft, $strategy, $reassignTarget, $actor, $ignoreReason, $ignoreComment): void {
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
                // D-146 bis — REASSIGN modifie le contenu de la mission (matériel déplacé
                // vers une intervention réelle) : jamais sur une mission verrouillée.
                // KEEP_AS_HISTORY, lui, ne touche à aucune ligne et reste possible.
                $this->resolutionGuard->assertResolutionAllowed($mission);

                $moved = $this->repointMaterial($draft, $reassignTarget);
                // Le matériel déplacé ne doit pas contredire le système déjà choisi sur
                // l'intervention cible (409, rollback du déplacement).
                $this->assertMovedMaterialCompatible($reassignTarget, $reassignTarget->getSelectedChoiceOption());

                $draft->setStatus(MissionInterventionDraft::STATUS_MATERIAL_REASSIGNED);
                $draft->setResolvedMissionIntervention($reassignTarget);
                $request->setStatus(InterventionTypeRequest::STATUS_IGNORED);
                $request->setIgnoreReason($ignoreReason);
                $request->setIgnoreComment($ignoreComment);
                $request->setDecidedBy($actor);
                $request->setDecidedAt(new \DateTimeImmutable());

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
                    // Correctif workflow Demandes Catalogue — motif/explication du
                    // rejet manager, ajoutés au payload existant plutôt qu'un nouvel
                    // AuditEvent (un seul fait métier, voir docblock de classe).
                    'ignoreReason' => $ignoreReason?->value,
                    'ignoreComment' => $ignoreComment !== null ? mb_substr($ignoreComment, 0, 500) : null,
                ]);
            } else {
                // KEEP_AS_HISTORY — le matériel reste attaché au draft, rien n'est
                // modifié sur MaterialLine/MaterialItemRequest, seul le statut du draft
                // change.
                $draft->setStatus(MissionInterventionDraft::STATUS_KEPT_AS_HISTORY);
                $request->setStatus(InterventionTypeRequest::STATUS_IGNORED);
                $request->setIgnoreReason($ignoreReason);
                $request->setIgnoreComment($ignoreComment);
                $request->setDecidedBy($actor);
                $request->setDecidedAt(new \DateTimeImmutable());

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
                    'ignoreReason' => $ignoreReason?->value,
                    'ignoreComment' => $ignoreComment !== null ? mb_substr($ignoreComment, 0, 500) : null,
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
