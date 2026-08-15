<?php

namespace App\Controller\Api;

use App\Entity\ChoiceOption;
use App\Entity\Firm;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\RequiredChoiceGroup;
use App\Entity\SuggestedMaterial;
use App\Security\Voter\BillingVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Prestations" (nom technique : FirmServiceOffering) + leurs matériels suggérés.
 * Accélérateur de saisie uniquement — jamais lu par le moteur financier
 * (PricingRuleResolver). Voir docs/decisions.md.
 */
#[Route('/api/firms/{firmId}/service-offerings')]
final class FirmServiceOfferingController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'api_firm_offerings_list', methods: ['GET'])]
    public function list(int $firmId): JsonResponse
    {
        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) {
            return $firm;
        }

        $offerings = $this->em->getRepository(FirmServiceOffering::class)->createQueryBuilder('o')
            ->leftJoin('o.interventionType', 'it')->addSelect('it')
            ->andWhere('o.firm = :firm')->setParameter('firm', $firm)
            ->orderBy('it.label', 'ASC')
            ->getQuery()->getResult();

        return $this->json(array_map(fn (FirmServiceOffering $o) => $this->serialize($o), $offerings));
    }

    /**
     * Création explicite uniquement — jamais implicite en arrière-plan (voir prompt Lot 1).
     */
    #[Route('', name: 'api_firm_offerings_create', methods: ['POST'])]
    public function create(int $firmId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) {
            return $firm;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $interventionTypeId = $data['interventionTypeId'] ?? null;

        if (!$interventionTypeId) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'interventionTypeId est requis.']], 422);
        }

        $type = $this->em->find(InterventionType::class, (int) $interventionTypeId);
        if (!$type instanceof InterventionType) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Type d\'intervention introuvable.']], 404);
        }

        $offering = new FirmServiceOffering();
        $offering->setFirm($firm);
        $offering->setInterventionType($type);
        $offering->setLabel(isset($data['label']) ? trim((string) $data['label']) ?: null : null);
        $offering->setActive(true);
        $this->applyPolicyFields($offering, $data);

        $this->em->persist($offering);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json([
                'error' => [
                    'status' => 409,
                    'code' => 'CONFLICT',
                    'message' => 'Une prestation existe déjà pour cette firme et ce type d\'intervention.',
                ],
            ], 409);
        }

        return $this->json($this->serialize($offering), Response::HTTP_CREATED);
    }

    #[Route('/{offeringId}', name: 'api_firm_offerings_update', methods: ['PATCH'], requirements: ['offeringId' => '\d+'])]
    public function update(int $firmId, int $offeringId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('label', $data)) {
            $offering->setLabel($data['label'] !== null ? trim((string) $data['label']) ?: null : null);
        }
        if (array_key_exists('active', $data)) {
            $offering->setActive((bool) $data['active']);
        }
        $this->applyPolicyFields($offering, $data);

        $this->em->flush();

        return $this->json($this->serialize($offering));
    }

    // ── Matériels suggérés ───────────────────────────────────────────────

    #[Route('/{offeringId}/suggested-materials', name: 'api_firm_offering_suggestions_create', methods: ['POST'], requirements: ['offeringId' => '\d+'])]
    public function addSuggestedMaterial(int $firmId, int $offeringId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $materialItemId = $data['materialItemId'] ?? null;
        if (!$materialItemId) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'materialItemId est requis.']], 422);
        }

        $item = $this->em->find(MaterialItem::class, (int) $materialItemId);
        if (!$item instanceof MaterialItem) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Matériel introuvable.']], 404);
        }

        // Garde applicatif — doublé par la contrainte FK composée en base (voir migration).
        if ($item->getFirm()?->getId() !== $offering->getFirm()->getId()) {
            return $this->json([
                'error' => [
                    'status' => 422,
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Le matériel suggéré doit appartenir à la même firme que la prestation.',
                ],
            ], 422);
        }

        $maxOrder = $this->em->getRepository(SuggestedMaterial::class)->createQueryBuilder('sm')
            ->select('MAX(sm.displayOrder)')
            ->andWhere('sm.firmServiceOffering = :o')->setParameter('o', $offering)
            ->getQuery()->getSingleScalarResult();
        $nextOrder = $maxOrder === null ? 0 : ((int) $maxOrder + 1);

        $suggestion = new SuggestedMaterial();
        $suggestion->setFirmServiceOffering($offering);
        $suggestion->setFirm($offering->getFirm());
        $suggestion->setMaterialItem($item);
        $suggestion->setDisplayOrder($nextOrder);

        $this->em->persist($suggestion);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => ['status' => 409, 'code' => 'CONFLICT', 'message' => 'Ce matériel est déjà suggéré pour cette prestation.']], 409);
        }

        return $this->json($this->serializeSuggestion($suggestion), Response::HTTP_CREATED);
    }

    /**
     * Réordonnancement complet (glisser-déposer côté frontend) — évite les mises à jour
     * partielles d'un seul displayOrder qui laisseraient la liste dans un état incohérent.
     */
    #[Route('/{offeringId}/suggested-materials/reorder', name: 'api_firm_offering_suggestions_reorder', methods: ['PATCH'], requirements: ['offeringId' => '\d+'])]
    public function reorderSuggestedMaterials(int $firmId, int $offeringId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $orderedIds = $data['orderedIds'] ?? null;
        if (!is_array($orderedIds)) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'orderedIds est requis (liste d\'identifiants).']], 422);
        }

        $suggestions = $offering->getSuggestedMaterials();
        $byId = [];
        foreach ($suggestions as $s) {
            $byId[$s->getId()] = $s;
        }

        if (count($orderedIds) !== count($byId) || array_diff(array_map('intval', $orderedIds), array_keys($byId)) !== []) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'orderedIds doit contenir exactement les suggestions de cette prestation.']], 422);
        }

        foreach (array_values($orderedIds) as $index => $id) {
            $byId[(int) $id]->setDisplayOrder($index);
        }

        $this->em->flush();

        return $this->json(array_map(fn (SuggestedMaterial $s) => $this->serializeSuggestion($s), iterator_to_array($offering->getSuggestedMaterials())));
    }

    #[Route('/{offeringId}/suggested-materials/{suggestionId}', name: 'api_firm_offering_suggestions_delete', methods: ['DELETE'], requirements: ['offeringId' => '\d+', 'suggestionId' => '\d+'])]
    public function deleteSuggestedMaterial(int $firmId, int $offeringId, int $suggestionId): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $suggestion = $this->em->find(SuggestedMaterial::class, $suggestionId);
        if (!$suggestion instanceof SuggestedMaterial || $suggestion->getFirmServiceOffering()->getId() !== $offering->getId()) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Suggestion introuvable.']], 404);
        }

        // Suppression toujours physique : aucune incidence historique ou financière.
        $this->em->remove($suggestion);
        $this->em->flush();

        return $this->json(['id' => $suggestionId, 'deleted' => true]);
    }

    // ── Tarification firme conditionnée à un choix obligatoire ─────────
    // RequiredChoiceGroup/ChoiceOption — jamais de montant ici (voir docblock de classe) :
    // le forfait par option vit exclusivement dans PricingRule.choiceOption, configuré
    // via FirmBillingController. V1 : un seul groupe actif par prestation (voir
    // docblock de RequiredChoiceGroup) — ce contrôleur applique cet invariant.

    /**
     * Crée le groupe actif de cette prestation (mode "Selon un choix obligatoire") ou met
     * à jour sa question s'il en existe déjà un. Ne crée jamais d'option — voir
     * addChoiceOption().
     */
    #[Route('/{offeringId}/choice-group', name: 'api_firm_offering_choice_group_upsert', methods: ['PUT'], requirements: ['offeringId' => '\d+'])]
    public function upsertChoiceGroup(int $firmId, int $offeringId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $question = isset($data['question']) ? trim((string) $data['question']) : '';
        if ($question === '') {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'question est requise.']], 422);
        }

        // V1 — un seul groupe par prestation (actif ou non) : une bascule "Forfait unique"
        // → "Selon un choix obligatoire" réutilise et réactive toujours le même groupe
        // (question/options déjà configurées conservées), jamais une recréation qui
        // perdrait silencieusement l'historique de configuration à chaque aller-retour.
        $group = $offering->getGroup();
        if ($group === null) {
            $group = new RequiredChoiceGroup();
            $group->setOffering($offering);
            $this->em->persist($group);
        }
        $group->setActive(true);
        $group->setQuestion($question);

        $this->em->flush();

        return $this->json($this->serializeChoiceGroup($group));
    }

    /**
     * Revient au mode "Forfait unique" — désactive le groupe (jamais de suppression) :
     * les options, les PricingRule liées et l'historique des sélections instrumentiste
     * restent intacts.
     */
    #[Route('/{offeringId}/choice-group', name: 'api_firm_offering_choice_group_deactivate', methods: ['DELETE'], requirements: ['offeringId' => '\d+'])]
    public function deactivateChoiceGroup(int $firmId, int $offeringId): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $group = $offering->getActiveChoiceGroup();
        if ($group !== null) {
            $group->setActive(false);
            $this->em->flush();
        }

        return $this->json(['deactivated' => true]);
    }

    #[Route('/{offeringId}/choice-group/options', name: 'api_firm_offering_choice_option_create', methods: ['POST'], requirements: ['offeringId' => '\d+'])]
    public function addChoiceOption(int $firmId, int $offeringId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $group = $offering->getActiveChoiceGroup();
        if ($group === null) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Configurez d\'abord la question du groupe (PUT .../choice-group).']], 422);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $label = isset($data['label']) ? trim((string) $data['label']) : '';
        if ($label === '') {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'label est requis.']], 422);
        }

        $materialItem = null;
        if (!empty($data['materialItemId'])) {
            $materialItem = $this->em->find(MaterialItem::class, (int) $data['materialItemId']);
            if (!$materialItem instanceof MaterialItem) {
                return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Matériel introuvable.']], 404);
            }
            if ($materialItem->getFirm()?->getId() !== $offering->getFirm()->getId()) {
                return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Le matériel associé doit appartenir à la même firme que la prestation.']], 422);
            }
            foreach ($group->getOptions() as $existing) {
                if ($existing->isActive() && $existing->getMaterialItem()?->getId() === $materialItem->getId()) {
                    return $this->json(['error' => ['status' => 409, 'code' => 'CONFLICT', 'message' => 'Ce matériel est déjà associé à une autre option de ce groupe.']], 409);
                }
            }
        }

        $maxOrder = 0;
        foreach ($group->getOptions() as $existing) {
            $maxOrder = max($maxOrder, $existing->getDisplayOrder() + 1);
        }

        $option = new ChoiceOption();
        $option->setGroup($group);
        $option->setLabel($label);
        $option->setMaterialItem($materialItem);
        $option->setDisplayOrder($maxOrder);

        $this->em->persist($option);
        $this->em->flush();

        return $this->json($this->serializeChoiceOption($option), Response::HTTP_CREATED);
    }

    #[Route('/{offeringId}/choice-group/options/{optionId}', name: 'api_firm_offering_choice_option_update', methods: ['PATCH'], requirements: ['offeringId' => '\d+', 'optionId' => '\d+'])]
    public function updateChoiceOption(int $firmId, int $offeringId, int $optionId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $option = $this->getChoiceOptionOr404($offering, $optionId);
        if ($option instanceof JsonResponse) {
            return $option;
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('label', $data)) {
            $label = trim((string) $data['label']);
            if ($label === '') {
                return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'label ne peut pas être vide.']], 422);
            }
            $option->setLabel($label);
        }

        if (array_key_exists('materialItemId', $data)) {
            if ($data['materialItemId'] === null) {
                $option->setMaterialItem(null);
            } else {
                $materialItem = $this->em->find(MaterialItem::class, (int) $data['materialItemId']);
                if (!$materialItem instanceof MaterialItem) {
                    return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Matériel introuvable.']], 404);
                }
                if ($materialItem->getFirm()?->getId() !== $offering->getFirm()->getId()) {
                    return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Le matériel associé doit appartenir à la même firme que la prestation.']], 422);
                }
                foreach ($option->getGroup()->getOptions() as $existing) {
                    if ($existing->getId() !== $option->getId() && $existing->isActive() && $existing->getMaterialItem()?->getId() === $materialItem->getId()) {
                        return $this->json(['error' => ['status' => 409, 'code' => 'CONFLICT', 'message' => 'Ce matériel est déjà associé à une autre option de ce groupe.']], 409);
                    }
                }
                $option->setMaterialItem($materialItem);
            }
        }

        if (array_key_exists('active', $data)) {
            $option->setActive((bool) $data['active']);
        }

        $this->em->flush();

        return $this->json($this->serializeChoiceOption($option));
    }

    /**
     * Suppression physique uniquement si l'option n'a jamais été utilisée (aucune
     * PricingRule, aucune sélection instrumentiste réelle) — sinon 409, désactivez-la
     * (active=false) à la place. Jamais de perte d'historique financier/encodage.
     */
    #[Route('/{offeringId}/choice-group/options/{optionId}', name: 'api_firm_offering_choice_option_delete', methods: ['DELETE'], requirements: ['offeringId' => '\d+', 'optionId' => '\d+'])]
    public function deleteChoiceOption(int $firmId, int $offeringId, int $optionId): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $offering = $this->getOfferingOr404($firmId, $offeringId);
        if ($offering instanceof JsonResponse) {
            return $offering;
        }

        $option = $this->getChoiceOptionOr404($offering, $optionId);
        if ($option instanceof JsonResponse) {
            return $option;
        }

        $rulesCount = (int) $this->em->getRepository(PricingRule::class)->count(['choiceOption' => $option]);
        $selectionsCount = (int) $this->em->getRepository(MissionIntervention::class)->count(['selectedChoiceOption' => $option]);
        if ($rulesCount > 0 || $selectionsCount > 0) {
            return $this->json([
                'error' => [
                    'status' => 409,
                    'code' => 'CONFLICT',
                    'message' => 'Cette option a déjà été utilisée (tarif et/ou encodage) — désactivez-la plutôt que de la supprimer.',
                ],
            ], 409);
        }

        $this->em->remove($option);
        $this->em->flush();

        return $this->json(['id' => $optionId, 'deleted' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Refonte Catalogue/Prestations (D-092) — les 4 champs de politique délégué sont
     * volontairement acceptés en création ET en mise à jour partielle (array_key_exists,
     * jamais de valeur implicite non fournie) — cohérent avec label/active ci-dessus.
     */
    private function applyPolicyFields(FirmServiceOffering $offering, array $data): void
    {
        if (array_key_exists('representativePresenceRelevant', $data)) {
            $offering->setRepresentativePresenceRelevant((bool) $data['representativePresenceRelevant']);
        }
        if (array_key_exists('representativeSuppressesInterventionFee', $data)) {
            $offering->setRepresentativeSuppressesInterventionFee((bool) $data['representativeSuppressesInterventionFee']);
        }
        if (array_key_exists('representativeSuppressesOwnMaterialFees', $data)) {
            $offering->setRepresentativeSuppressesOwnMaterialFees((bool) $data['representativeSuppressesOwnMaterialFees']);
        }
        if (array_key_exists('feeApplicable', $data)) {
            $offering->setFeeApplicable((bool) $data['feeApplicable']);
        }
    }

    private function getFirmOr404(int $id): Firm|JsonResponse
    {
        $firm = $this->em->find(Firm::class, $id);
        if (!$firm instanceof Firm) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Firme introuvable.']], 404);
        }
        return $firm;
    }

    private function getOfferingOr404(int $firmId, int $offeringId): FirmServiceOffering|JsonResponse
    {
        $offering = $this->em->find(FirmServiceOffering::class, $offeringId);
        if (!$offering instanceof FirmServiceOffering || $offering->getFirm()->getId() !== $firmId) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Prestation introuvable.']], 404);
        }
        return $offering;
    }

    private function getChoiceOptionOr404(FirmServiceOffering $offering, int $optionId): ChoiceOption|JsonResponse
    {
        $option = $this->em->find(ChoiceOption::class, $optionId);
        if (!$option instanceof ChoiceOption || $option->getGroup()->getOffering()->getId() !== $offering->getId()) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Option introuvable.']], 404);
        }
        return $option;
    }

    private function serializeChoiceGroup(RequiredChoiceGroup $group): array
    {
        return [
            'id' => $group->getId(),
            'question' => $group->getQuestion(),
            'active' => $group->isActive(),
            'operational' => $group->isOperational(),
            'options' => array_map(
                fn (ChoiceOption $o) => $this->serializeChoiceOption($o),
                iterator_to_array($group->getOptions()),
            ),
        ];
    }

    private function serializeChoiceOption(ChoiceOption $o): array
    {
        $item = $o->getMaterialItem();
        return [
            'id' => $o->getId(),
            'label' => $o->getLabel(),
            'displayOrder' => $o->getDisplayOrder(),
            'active' => $o->isActive(),
            'materialItem' => $item ? [
                'id' => $item->getId(),
                'label' => $item->getLabel(),
                'referenceCode' => $item->getReferenceCode(),
            ] : null,
        ];
    }

    /**
     * Refonte Catalogue/Prestations (D-092) — correctif revue de sécurité : `list()`
     * n'a jamais eu de garde `BillingVoter::MANAGE` (par conception — c'est cet
     * endpoint que consomme l'encodage instrumentiste via
     * `fetchFirmServiceOfferings()`, voir InterventionEncodingContextService et
     * AddInterventionDialog/EditInterventionDialog). `representativePresenceRelevant`
     * reste donc exposé à tout rôle authentifié — c'est exactement l'information dont
     * le frontend instrumentiste a besoin pour savoir si la question "délégué
     * présent ?" doit être posée (D-092 §10), jamais un montant ni une conséquence
     * financière. En revanche `representativeSuppressesInterventionFee`/
     * `representativeSuppressesOwnMaterialFees`/`feeApplicable` révèlent la
     * CONSÉQUENCE financière de cette politique (ex. "si je réponds oui, le forfait
     * de cette firme tombe à 0€") — l'instrumentiste ne doit jamais les voir
     * (contrainte explicite du prompt : "aucune conséquence financière"). Réservés
     * ici à BillingVoter::MANAGE, jamais exposés sur cet endpoint à un autre rôle.
     */
    private function serialize(FirmServiceOffering $o): array
    {
        $base = [
            'id' => $o->getId(),
            'firmId' => $o->getFirm()->getId(),
            'interventionType' => [
                'id' => $o->getInterventionType()->getId(),
                'code' => $o->getInterventionType()->getCode(),
                'label' => $o->getInterventionType()->getLabel(),
            ],
            'label' => $o->getLabel(),
            'active' => $o->isActive(),
            'representativePresenceRelevant' => $o->isRepresentativePresenceRelevant(),
            'suggestedMaterials' => array_map(
                fn (SuggestedMaterial $s) => $this->serializeSuggestion($s),
                iterator_to_array($o->getSuggestedMaterials()),
            ),
            // Tarification firme conditionnée à un choix obligatoire — question + options
            // uniquement, jamais un montant (voir docblock de RequiredChoiceGroup) :
            // c'est ce que l'écran instrumentiste consomme via ce même endpoint.
            'choiceGroup' => $o->getActiveChoiceGroup() !== null && $o->getActiveChoiceGroup()->isOperational()
                ? $this->serializeChoiceGroup($o->getActiveChoiceGroup())
                : null,
        ];

        if ($this->isGranted(BillingVoter::MANAGE)) {
            $base['representativeSuppressesInterventionFee'] = $o->isRepresentativeSuppressesInterventionFee();
            $base['representativeSuppressesOwnMaterialFees'] = $o->isRepresentativeSuppressesOwnMaterialFees();
            $base['feeApplicable'] = $o->isFeeApplicable();
            // Vue complète (y compris groupe non encore opérationnel, options
            // désactivées) — réservée au manager pour la configuration ; l'instrumentiste
            // ne voit que 'choiceGroup' ci-dessus (jamais de montant nulle part ici).
            $base['choiceGroupConfig'] = $o->getGroup() !== null
                ? $this->serializeChoiceGroup($o->getGroup())
                : null;
        }

        return $base;
    }

    private function serializeSuggestion(SuggestedMaterial $s): array
    {
        $item = $s->getMaterialItem();
        return [
            'id' => $s->getId(),
            'displayOrder' => $s->getDisplayOrder(),
            'materialItem' => [
                'id' => $item->getId(),
                'label' => $item->getLabel(),
                'referenceCode' => $item->getReferenceCode(),
                'active' => $item->isActive(),
            ],
        ];
    }
}
