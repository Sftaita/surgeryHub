<?php

namespace App\Service;

use App\Dto\Request\MaterialItemRequestCreateRequest;
use App\Entity\Firm;
use App\Entity\MaterialItem;
use App\Entity\MaterialItemRequest;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\MissionInterventionDraft;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\CatalogueRequestIgnoreReason;
use App\Exception\CatalogueRequestException;
use App\Exception\MaterialItemRequestAlreadyProcessedException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class MaterialItemRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MaterialAttachmentResolver $attachmentResolver,
        private readonly AuditService $audit,
        private readonly ActiveFirmResolver $firmResolver,
        private readonly CatalogueFactory $catalogue,
        private readonly CatalogueResolutionGuard $resolutionGuard,
        private readonly ChoiceMaterialExclusivityService $exclusivity,
    ) {}

    /**
     * EPIC Revue instrumentiste, Lot 3, commit 4 — même principe que
     * InterventionService::createMaterialLine() : la cible (missionInterventionId OU
     * interventionDraftId) est résolue via MaterialAttachmentResolver, dans la même
     * transaction que la persistance (verrou pessimiste éventuel sur un draft).
     *
     * D-146 bis — la demande conserve la firme fabricante (existante OU nom libre, exactement
     * l'une des deux) et la quantité (strictement positive). Une firme proposée en texte
     * libre n'est JAMAIS créée : elle reste une proposition jusqu'à la décision du manager.
     */
    public function create(Mission $mission, MaterialItemRequestCreateRequest $dto, User $createdBy): MaterialItemRequest
    {
        if (CatalogueFactory::normalizeName($dto->label) === '') {
            throw new CatalogueRequestException(422, CatalogueRequestException::LABEL_REQUIRED, 'Le nom du matériel est obligatoire.');
        }
        $proposedFirmName = CatalogueFactory::normalizeName($dto->proposedFirmName);
        if ($dto->firmId !== null && $proposedFirmName !== '') {
            throw new CatalogueRequestException(422, CatalogueRequestException::FIRM_AMBIGUOUS, 'Choisissez une firme existante OU proposez une nouvelle firme, pas les deux.');
        }
        if ($dto->firmId === null && $proposedFirmName === '') {
            throw new CatalogueRequestException(422, CatalogueRequestException::FIRM_REQUIRED, 'Indiquez la firme fabricante du matériel (ou « Ma firme n\'est pas dans la liste »).');
        }
        // Lecture seule avant la transaction : rien n'est persisté si la firme est invalide.
        $firm = $dto->firmId !== null ? $this->firmResolver->resolveActive($dto->firmId) : null;
        $quantity = self::normalizeQuantity($dto->quantity);

        $req = null;

        $this->em->wrapInTransaction(function () use (&$req, $mission, $dto, $createdBy, $firm, $proposedFirmName, $quantity): void {
            $target = $this->attachmentResolver->resolve($mission, $dto->missionInterventionId, $dto->interventionDraftId);

            $req = new MaterialItemRequest();
            $req
                ->setMission($mission)
                ->setLabel(CatalogueFactory::normalizeName($dto->label))
                ->setReferenceCode(CatalogueFactory::normalizeName($dto->referenceCode) ?: null)
                ->setComment($dto->comment)
                ->setProposedFirm($firm)
                ->setProposedFirmName($firm === null ? $proposedFirmName : null)
                ->setQuantity($quantity)
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
     * D-146 bis — résolution ATOMIQUE en une seule transaction (verrou pessimiste sur la
     * demande, même principe que MissionInterventionDraftService::resolve()) : création
     * éventuelle de la firme, création éventuelle du matériel (`UNSPECIFIED`, aucun tarif),
     * rattachement à la cible de la demande avec la quantité validée, résolution et
     * traçabilité. Le moindre refus annule tout — jamais un matériel créé sans ligne, ni
     * une demande résolue sans matériel.
     *
     * $input :
     *  - `quantity` : quantité validée par le manager (défaut : celle de la demande ;
     *    obligatoire pour une demande antérieure à D-146 bis qui n'en portait pas) ;
     *  - `materialItemId` : associer un matériel EXISTANT (sa firme et ses tarifs réels
     *    sont repris tels quels, jamais modifiés) ; OU
     *  - `newItem` {label, unit, referenceCode?, isImplant?, firmId? | newFirmName?} :
     *    créer le matériel avec la firme validée (existante ou créée ici après vérification) ;
     *  - `confirmPossibleDuplicate` : confirmer la création malgré des doublons possibles.
     *
     * Jamais sur une mission verrouillée (CatalogueResolutionGuard) ; exclusivité matérielle
     * vérifiée comme pour une ligne encodée (ChoiceMaterialExclusivityService) ; la firme de
     * l'intervention n'est jamais modifiée (le matériel garde la sienne).
     *
     * @param array<string, mixed> $input
     * @return array{0: MaterialItemRequest, 1: MaterialLine, 2: array{createdFirm: bool, createdItem: bool}}
     */
    public function resolve(MaterialItemRequest $req, array $input, User $actor): array
    {
        $line = null;
        $created = ['createdFirm' => false, 'createdItem' => false];

        $this->em->wrapInTransaction(function () use (&$line, &$created, $req, $input, $actor): void {
            $this->em->lock($req, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($req);

            if ($req->getStatus() !== MaterialItemRequest::STATUS_PENDING) {
                throw new MaterialItemRequestAlreadyProcessedException(sprintf(
                    'MaterialItemRequest #%d is %s, not PENDING — cannot be resolved (again).',
                    $req->getId(),
                    $req->getStatus(),
                ));
            }

            $this->resolutionGuard->assertResolutionAllowed($req->getMission());

            $rawQuantity = array_key_exists('quantity', $input) && $input['quantity'] !== null && $input['quantity'] !== ''
                ? $input['quantity']
                : $req->getQuantity();
            $quantity = self::normalizeQuantity($rawQuantity);

            $materialItem = $this->resolveMaterialItem($req, $input, $created);

            $target = $req->attachmentTarget();
            if ($target instanceof MissionIntervention) {
                // Même règle que pour une ligne encodée à la main (D-111) : un matériel lié à
                // une AUTRE option du système choisi est refusé (409), jamais ajouté.
                $this->exclusivity->assertMaterialCompatible($target, $materialItem);
            }

            $req->setMaterialItem($materialItem);
            $req->setStatus(MaterialItemRequest::STATUS_RESOLVED);
            $req->setDecidedBy($actor);
            $req->setDecidedAt(new \DateTimeImmutable());

            // Attachée à la MÊME cible que la demande (attachmentTarget(), jamais
            // getMissionIntervention() seul) : si la demande est encore rattachée à un
            // draft ouvert, la ligne doit l'être aussi, sinon elle ne serait jamais
            // reprise par MissionInterventionDraftService::repointMaterial() lors de la
            // résolution ultérieure du draft — elle resterait orpheline.
            $line = new MaterialLine();
            $line->setMission($req->getMission());
            if ($target instanceof MissionIntervention) {
                $line->setMissionIntervention($target);
            } elseif ($target instanceof MissionInterventionDraft) {
                $line->setInterventionDraft($target);
            }
            $line->setItem($materialItem);
            $line->setQuantity($quantity);
            $line->setComment($req->getComment());
            $line->setCreatedBy($actor);

            $this->em->persist($line);
            $this->em->flush();

            $this->audit->record($req->getMission(), $actor, AuditEventType::MATERIAL_ITEM_REQUEST_RESOLVED, [
                'materialItemRequestId' => $req->getId(),
                'materialItemId' => $materialItem->getId(),
                'materialLineId' => $line->getId(),
                'label' => $req->getLabel(),
                'quantity' => $quantity,
                'requestedQuantity' => $req->getQuantity(),
                'firmId' => $materialItem->getFirm()?->getId(),
                'proposedFirmId' => $req->getProposedFirm()?->getId(),
                'proposedFirmName' => $req->getProposedFirmName(),
                'createdFirm' => $created['createdFirm'],
                'createdMaterialItem' => $created['createdItem'],
            ]);

            $this->em->flush();
        });

        return [$req, $line, $created];
    }

    /**
     * D-146 bis — aide à la détection des doublons pour le manager : matériels actifs dont
     * la référence ou le nom ressemble à la demande (toutes firmes), la firme proposée en
     * premier. Lecture seule, jamais bloquant en soi.
     *
     * @return list<MaterialItem>
     */
    public function duplicateCandidates(MaterialItemRequest $req, int $limit = 10): array
    {
        $terms = array_values(array_filter([
            CatalogueFactory::normalizeName($req->getReferenceCode()),
            CatalogueFactory::normalizeName($req->getLabel()),
        ]));
        if ($terms === []) {
            return [];
        }

        $qb = $this->em->getRepository(MaterialItem::class)->createQueryBuilder('mi')
            ->leftJoin('mi.firm', 'f')->addSelect('f')
            ->where('mi.active = true');
        $or = [];
        foreach ($terms as $k => $term) {
            $or[] = sprintf('LOWER(mi.referenceCode) LIKE :t%d OR LOWER(mi.label) LIKE :t%d', $k, $k);
            $qb->setParameter('t' . $k, '%' . mb_strtolower($term) . '%');
        }
        // Mots significatifs du nom (≥ 4 lettres) : « Ultrabutton Adjustable » retrouve
        // « Ultrabutton Tib » — c'est une aide, le manager tranche.
        $k = count($terms);
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $req->getLabel())) ?: [] as $word) {
            if (mb_strlen($word) >= 4) {
                $or[] = sprintf('LOWER(mi.label) LIKE :t%d', $k);
                $qb->setParameter('t' . $k, '%' . $word . '%');
                ++$k;
            }
        }
        $qb->andWhere('(' . implode(' OR ', $or) . ')')->setMaxResults(50);

        /** @var MaterialItem[] $items */
        $items = $qb->getQuery()->getResult();
        $proposedFirmId = $req->getProposedFirm()?->getId();
        usort($items, static fn (MaterialItem $a, MaterialItem $b) => [(int) ($b->getFirm()?->getId() === $proposedFirmId), $a->getLabel()]
            <=> [(int) ($a->getFirm()?->getId() === $proposedFirmId), $b->getLabel()]);

        return array_slice($items, 0, $limit);
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

    /**
     * Quantité strictement positive, deux décimales au plus (précision de
     * MaterialLine::$quantity). Jamais une valeur par défaut inventée.
     *
     * @throws CatalogueRequestException 422 QUANTITY_REQUIRED
     */
    public static function normalizeQuantity(mixed $raw): string
    {
        $value = is_string($raw) ? str_replace(',', '.', trim($raw)) : $raw;
        $number = (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value) ? (float) $value : null;
        if ($number === null || !is_finite($number) || $number <= 0 || abs(round($number, 2) - $number) > 1e-9 || $number >= 1e8) {
            throw new CatalogueRequestException(422, CatalogueRequestException::QUANTITY_REQUIRED, 'La quantité utilisée est obligatoire et doit être strictement positive (deux décimales au plus).');
        }

        return number_format($number, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $input
     * @param array{createdFirm: bool, createdItem: bool} $created
     */
    private function resolveMaterialItem(MaterialItemRequest $req, array $input, array &$created): MaterialItem
    {
        $existingId = $input['materialItemId'] ?? null;
        $newItem = is_array($input['newItem'] ?? null) ? $input['newItem'] : null;

        if ($existingId !== null && $newItem !== null) {
            throw new CatalogueRequestException(422, CatalogueRequestException::MATERIAL_ITEM_REQUIRED, 'Associez un matériel existant OU créez-en un nouveau, pas les deux.');
        }

        if ($existingId !== null) {
            $item = $this->em->find(MaterialItem::class, (int) $existingId);
            if (!$item instanceof MaterialItem) {
                throw new CatalogueRequestException(422, CatalogueRequestException::MATERIAL_ITEM_REQUIRED, 'Matériel introuvable.');
            }

            // Matériel existant : sa firme réelle et ses règles de facturation sont reprises
            // telles quelles — jamais modifiées par la résolution.
            return $item;
        }

        if ($newItem === null) {
            throw new CatalogueRequestException(422, CatalogueRequestException::MATERIAL_ITEM_REQUIRED, 'Choisissez un matériel existant ou créez la nouvelle référence.');
        }

        $firm = $this->resolveFirmForNewItem($newItem, $created);

        $label = CatalogueFactory::normalizeName((string) ($newItem['label'] ?? $req->getLabel()));
        $reference = CatalogueFactory::normalizeName((string) ($newItem['referenceCode'] ?? ''));

        // Doublon certain : même firme + même référence (contrainte d'unicité du catalogue).
        if ($firm->getId() !== null) {
            $sameRef = $this->em->getRepository(MaterialItem::class)->findOneBy(['firm' => $firm, 'referenceCode' => $reference]);
            if ($sameRef instanceof MaterialItem) {
                throw new CatalogueRequestException(409, CatalogueRequestException::MATERIAL_ITEM_DUPLICATE, $reference === ''
                    ? sprintf('La firme %s a déjà un matériel sans référence (« %s ») : renseignez la référence du nouveau matériel, ou associez ce matériel existant.', $firm->getName(), $sameRef->getLabel())
                    : sprintf('La référence « %s » existe déjà chez %s (« %s ») : associez ce matériel existant plutôt que d\'en créer un doublon.', $reference, $firm->getName(), $sameRef->getLabel()),
                    ['existingMaterialItem' => self::itemSummary($sameRef)]);
            }
        }

        // Doublons possibles (aide, jamais bloquant si le manager confirme) : même nom chez
        // la même firme, ou même référence chez une autre firme.
        if (!($input['confirmPossibleDuplicate'] ?? false)) {
            $candidates = $this->possibleDuplicates($firm, $label, $reference);
            if ($candidates !== []) {
                throw new CatalogueRequestException(409, CatalogueRequestException::MATERIAL_ITEM_POSSIBLE_DUPLICATE,
                    'Un matériel semblable existe déjà : vérifiez avant de créer une nouvelle référence (ou confirmez la création).',
                    ['candidates' => array_map(self::itemSummary(...), $candidates)]);
            }
        }

        $item = $this->catalogue->createMaterialItem(
            $firm,
            $label,
            CatalogueFactory::normalizeName((string) ($newItem['unit'] ?? '')) ?: 'pièce',
            $reference,
            (bool) ($newItem['isImplant'] ?? false),
        );
        $created['createdItem'] = true;

        return $item;
    }

    /**
     * @param array<string, mixed> $newItem
     * @param array{createdFirm: bool, createdItem: bool} $created
     */
    private function resolveFirmForNewItem(array $newItem, array &$created): Firm
    {
        $firmId = $newItem['firmId'] ?? null;
        $newFirmName = CatalogueFactory::normalizeName((string) ($newItem['newFirmName'] ?? ''));

        if ($firmId !== null && $newFirmName !== '') {
            throw new CatalogueRequestException(422, CatalogueRequestException::FIRM_AMBIGUOUS, 'Choisissez une firme existante OU créez une nouvelle firme, pas les deux.');
        }
        if ($firmId !== null) {
            return $this->firmResolver->resolveActive((int) $firmId);
        }
        if ($newFirmName === '') {
            throw new CatalogueRequestException(422, CatalogueRequestException::FIRM_REQUIRED, 'La firme du nouveau matériel est obligatoire.');
        }

        $firm = $this->catalogue->createFirm($newFirmName);
        $created['createdFirm'] = true;

        return $firm;
    }

    /** @return list<MaterialItem> */
    private function possibleDuplicates(Firm $firm, string $label, string $reference): array
    {
        $found = [];
        if ($firm->getId() !== null && $label !== '') {
            foreach ($this->em->getRepository(MaterialItem::class)->createQueryBuilder('mi')
                ->where('mi.firm = :firm')->andWhere('LOWER(mi.label) = :label')
                ->setParameter('firm', $firm)->setParameter('label', mb_strtolower($label))
                ->getQuery()->getResult() as $item) {
                $found[$item->getId()] = $item;
            }
        }
        if ($reference !== '') {
            foreach ($this->em->getRepository(MaterialItem::class)->createQueryBuilder('mi')
                ->where('LOWER(mi.referenceCode) = :ref')
                ->setParameter('ref', mb_strtolower($reference))
                ->getQuery()->getResult() as $item) {
                $found[$item->getId()] = $item;
            }
        }

        return array_values($found);
    }

    /** @return array{id: ?int, label: ?string, referenceCode: ?string, firm: ?array{id: ?int, name: ?string}, billingStatus: string, active: bool} */
    public static function itemSummary(MaterialItem $item): array
    {
        return [
            'id' => $item->getId(),
            'label' => $item->getLabel(),
            'referenceCode' => $item->getReferenceCode() ?: null,
            'firm' => $item->getFirm() ? ['id' => $item->getFirm()->getId(), 'name' => $item->getFirm()->getName()] : null,
            'billingStatus' => $item->getBillingStatus()->value,
            'active' => $item->isActive(),
        ];
    }
}
