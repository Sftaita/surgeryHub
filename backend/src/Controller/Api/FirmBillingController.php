<?php

namespace App\Controller\Api;

use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\PricingRuleType;
use App\Security\Voter\BillingVoter;
use App\Service\PricingRuleVersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Lecture, validation du payload HTTP et sérialisation uniquement — toute mutation de
 * PricingRule est déléguée à PricingRuleVersioningService, seul point d'écriture
 * autorisé (D-072, Lot 2 — remplace l'appel direct à PricingRuleWriteService : ce
 * dernier reste le primitif bas niveau, plus appelé depuis un contrôleur).
 *
 * Contrats HTTP conservés à l'identique (D-072 §10) : mêmes URLs, mêmes corps de
 * requête pour create/list. PATCH/DELETE sont désormais restreints aux règles futures
 * (validFrom > aujourd'hui) — une règle déjà applicable ou passée renvoie 409
 * PRICING_RULE_IMMUTABLE. Nouveau POST .../replace pour le cas "remplacer le tarif
 * actuel à partir d'une date".
 */
#[Route('/api/firms/{firmId}')]
class FirmBillingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PricingRuleVersioningService $versioningService,
    ) {}

    // ── Billing contact ───────────────────────────────────────────────

    #[Route('/billing-contact', name: 'api_firm_billing_contact_update', methods: ['PATCH'])]
    public function updateBillingContact(int $firmId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) return $firm;

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('billingEmail', $data)) {
            $firm->setBillingEmail($data['billingEmail'] ?: null);
        }
        if (array_key_exists('billingEmailCc', $data)) {
            $cc = $data['billingEmailCc'];
            $firm->setBillingEmailCc(is_array($cc) && count($cc) > 0 ? $cc : null);
        }

        $this->em->flush();

        return $this->json([
            'id' => $firm->getId(),
            'billingEmail' => $firm->getBillingEmail(),
            'billingEmailCc' => $firm->getBillingEmailCc() ?? [],
        ]);
    }

    // ── Pricing rules ─────────────────────────────────────────────────

    #[Route('/pricing-rules', name: 'api_firm_pricing_rules_list', methods: ['GET'])]
    public function listRules(int $firmId): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) return $firm;

        $rules = $this->em->createQueryBuilder()
            ->select('r', 'mi', 'it')
            ->from(PricingRule::class, 'r')
            ->leftJoin('r.materialItem', 'mi')
            ->leftJoin('r.interventionType', 'it')
            ->where('r.firm = :firm')
            ->orderBy('r.ruleType', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setParameter('firm', $firm)
            ->getQuery()
            ->getResult();

        return $this->json(array_map(fn($r) => $this->serializeRule($r), $rules));
    }

    #[Route('/pricing-rules', name: 'api_firm_pricing_rules_create', methods: ['POST'])]
    public function createRule(int $firmId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) return $firm;

        $data = json_decode($request->getContent(), true) ?? [];
        $ruleTypeStr = $data['ruleType'] ?? null;
        $unitPrice = $data['unitPrice'] ?? null;

        if (!$ruleTypeStr || $unitPrice === null) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'ruleType et unitPrice sont requis.']], 422);
        }

        try {
            $ruleType = PricingRuleType::from($ruleTypeStr);
        } catch (\ValueError) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'ruleType invalide (INTERVENTION_FEE ou MATERIAL_FEE).']], 422);
        }

        if ((float) $unitPrice < 0) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'unitPrice doit être >= 0.']], 422);
        }

        [$validFrom, $validTo, $dateError] = $this->parseValidityDates($data);
        if ($dateError !== null) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => $dateError]], 422);
        }

        $currency = isset($data['currency']) ? (string) $data['currency'] : 'EUR';

        $interventionType = null;
        $materialItem = null;

        if ($ruleType === PricingRuleType::INTERVENTION_FEE) {
            $interventionTypeId = $data['interventionTypeId'] ?? null;
            if (!$interventionTypeId) {
                return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'interventionTypeId requis pour INTERVENTION_FEE.']], 422);
            }
            $interventionType = $this->em->find(InterventionType::class, (int) $interventionTypeId);
            if (!$interventionType instanceof InterventionType) {
                return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Type d\'intervention introuvable.']], 404);
            }
        } elseif ($ruleType === PricingRuleType::MATERIAL_FEE) {
            $materialItemId = $data['materialItemId'] ?? null;
            if (!$materialItemId) {
                return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'materialItemId requis pour MATERIAL_FEE.']], 422);
            }
            $materialItem = $this->em->find(MaterialItem::class, $materialItemId);
            if (!$materialItem) {
                return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'MaterialItem introuvable.']], 404);
            }
            if ($materialItem->getFirm()->getId() !== $firm->getId()) {
                return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Le MaterialItem doit appartenir à cette firme.']], 422);
            }
        }

        // Chevauchement de périodes de validité = refus bloquant, jamais un simple
        // avertissement — vérifié et écrit atomiquement sous verrou par
        // PricingRuleWriteService (via PricingRuleVersioningService), throw
        // PricingRulePeriodOverlapException → 409 en cas de conflit.
        $today = new \DateTimeImmutable('today');
        $rule = ($validFrom !== null && $validFrom > $today)
            ? $this->versioningService->scheduleRule($firm, $ruleType, $interventionType, $materialItem, (string) $unitPrice, $currency, $validFrom, $validTo, $actor)
            : $this->versioningService->createInitialRule($firm, $ruleType, $interventionType, $materialItem, (string) $unitPrice, $currency, $validFrom, $validTo, $actor);

        return $this->json($this->serializeRule($rule), 201);
    }

    /** D-072 §10 — restreint aux règles futures (validFrom > aujourd'hui), jamais une règle déjà applicable. */
    #[Route('/pricing-rules/{ruleId}', name: 'api_firm_pricing_rules_update', methods: ['PATCH'])]
    public function updateRule(int $firmId, int $ruleId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) return $firm;

        $rule = $this->em->find(PricingRule::class, $ruleId);
        if (!$rule || $rule->getFirm()->getId() !== $firm->getId()) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Règle introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('unitPrice', $data) && (float) $data['unitPrice'] < 0) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'unitPrice doit être >= 0.']], 422);
        }

        [$validFrom, $validTo, $dateError] = $this->parseValidityDates($data, $rule, keepExistingAsDefault: false);
        if ($dateError !== null) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => $dateError]], 422);
        }

        $updated = $this->versioningService->updateFutureRule(
            $rule,
            array_key_exists('unitPrice', $data) ? (string) $data['unitPrice'] : null,
            array_key_exists('currency', $data) ? (string) $data['currency'] : null,
            $validFrom,
            $validTo,
            $actor,
        );

        return $this->json($this->serializeRule($updated));
    }

    /** D-072 §10 — restreint aux règles futures, jamais une suppression physique d'une règle déjà applicable. */
    #[Route('/pricing-rules/{ruleId}', name: 'api_firm_pricing_rules_delete', methods: ['DELETE'])]
    public function deleteRule(int $firmId, int $ruleId, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) return $firm;

        $rule = $this->em->find(PricingRule::class, $ruleId);
        if (!$rule || $rule->getFirm()->getId() !== $firm->getId()) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Règle introuvable.']], 404);
        }

        $this->versioningService->cancelFutureRule($rule, $actor);

        return $this->json(['id' => $ruleId, 'deleted' => true]);
    }

    /**
     * D-072 §7 — le cas principal : remplacer le tarif actuellement en vigueur à partir
     * d'une date. Ferme l'ancienne règle + ouvre la nouvelle, atomique.
     */
    #[Route('/pricing-rules/{ruleId}/replace', name: 'api_firm_pricing_rules_replace', methods: ['POST'])]
    public function replaceRule(int $firmId, int $ruleId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) return $firm;

        $rule = $this->em->find(PricingRule::class, $ruleId);
        if (!$rule || $rule->getFirm()->getId() !== $firm->getId()) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Règle introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $unitPrice = $data['unitPrice'] ?? null;
        $effectiveFromRaw = $data['effectiveFrom'] ?? null;

        if ($unitPrice === null || $effectiveFromRaw === null) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'unitPrice et effectiveFrom sont requis.']], 422);
        }
        try {
            $effectiveFrom = new \DateTimeImmutable((string) $effectiveFromRaw);
        } catch (\Exception) {
            return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'effectiveFrom invalide.']], 422);
        }

        $currency = isset($data['currency']) ? (string) $data['currency'] : $rule->getCurrency();

        $newRule = $this->versioningService->replaceCurrentRuleFrom($rule, (string) $unitPrice, $currency, $effectiveFrom, $actor);

        return $this->json($this->serializeRule($newRule), 201);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function getFirmOr404(int $id): Firm|JsonResponse
    {
        $firm = $this->em->find(Firm::class, $id);
        if (!$firm) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Firme introuvable.']], 404);
        }
        return $firm;
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?string} [validFrom, validTo, erreur]
     */
    private function parseValidityDates(array $data, ?PricingRule $existing = null, bool $keepExistingAsDefault = true): array
    {
        try {
            $validFrom = array_key_exists('validFrom', $data)
                ? ($data['validFrom'] !== null ? new \DateTimeImmutable((string) $data['validFrom']) : null)
                : ($keepExistingAsDefault ? $existing?->getValidFrom() : null);
            $validTo = array_key_exists('validTo', $data)
                ? ($data['validTo'] !== null ? new \DateTimeImmutable((string) $data['validTo']) : null)
                : ($keepExistingAsDefault ? $existing?->getValidTo() : null);
        } catch (\Exception) {
            return [null, null, 'Dates de validité invalides.'];
        }

        if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
            return [null, null, 'validTo doit être postérieure ou égale à validFrom.'];
        }

        return [$validFrom, $validTo, null];
    }

    private function serializeRule(PricingRule $r): array
    {
        return [
            'id' => $r->getId(),
            'ruleType' => $r->getRuleType()->value,
            'interventionType' => $r->getInterventionType() ? [
                'id' => $r->getInterventionType()->getId(),
                'code' => $r->getInterventionType()->getCode(),
                'label' => $r->getInterventionType()->getLabel(),
            ] : null,
            'materialItem' => $r->getMaterialItem() ? [
                'id' => $r->getMaterialItem()->getId(),
                'label' => $r->getMaterialItem()->getLabel(),
                'referenceCode' => $r->getMaterialItem()->getReferenceCode(),
                'firm' => ['id' => $r->getMaterialItem()->getFirm()->getId(), 'name' => $r->getMaterialItem()->getFirm()->getName()],
            ] : null,
            'unitPrice' => $r->getUnitPrice(),
            'currency' => $r->getCurrency(),
            'validFrom' => $r->getValidFrom()?->format('Y-m-d'),
            'validTo' => $r->getValidTo()?->format('Y-m-d'),
            'active' => $r->isActive(),
        ];
    }
}
