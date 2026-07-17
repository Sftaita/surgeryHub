<?php

namespace App\Controller\Api;

use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Enum\PricingRuleType;
use App\Security\Voter\BillingVoter;
use App\Service\PricingRuleWriteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lecture, validation du payload HTTP et sérialisation uniquement — toute mutation de
 * PricingRule (verrouillage de cible, anti-chevauchement, persist/flush) est déléguée à
 * PricingRuleWriteService, seul point d'écriture autorisé (voir D-067).
 */
#[Route('/api/firms/{firmId}')]
class FirmBillingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PricingRuleWriteService $writeService,
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
    public function createRule(int $firmId, Request $request): JsonResponse
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

        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType($ruleType);
        $rule->setUnitPrice((string) $unitPrice);
        $rule->setCurrency(isset($data['currency']) ? (string) $data['currency'] : 'EUR');
        $rule->setValidFrom($validFrom);
        $rule->setValidTo($validTo);
        $rule->setActive(true);

        if ($ruleType === PricingRuleType::INTERVENTION_FEE) {
            $interventionTypeId = $data['interventionTypeId'] ?? null;
            if (!$interventionTypeId) {
                return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'interventionTypeId requis pour INTERVENTION_FEE.']], 422);
            }
            $type = $this->em->find(InterventionType::class, (int) $interventionTypeId);
            if (!$type instanceof InterventionType) {
                return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Type d\'intervention introuvable.']], 404);
            }
            $rule->setInterventionType($type);
        } elseif ($ruleType === PricingRuleType::MATERIAL_FEE) {
            $materialItemId = $data['materialItemId'] ?? null;
            if (!$materialItemId) {
                return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'materialItemId requis pour MATERIAL_FEE.']], 422);
            }
            $item = $this->em->find(MaterialItem::class, $materialItemId);
            if (!$item) {
                return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'MaterialItem introuvable.']], 404);
            }
            if ($item->getFirm()->getId() !== $firm->getId()) {
                return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'Le MaterialItem doit appartenir à cette firme.']], 422);
            }
            $rule->setMaterialItem($item);
        }

        // Chevauchement de périodes de validité = refus bloquant, jamais un simple
        // avertissement (voir docs/decisions.md) — vérifié et écrit atomiquement sous
        // verrou par PricingRuleWriteService (throw PricingRulePeriodOverlapException
        // → 409 PRICING_RULE_PERIOD_OVERLAP via ApiExceptionSubscriber en cas de conflit).
        $this->writeService->create($rule);

        return $this->json($this->serializeRule($rule), 201);
    }

    #[Route('/pricing-rules/{ruleId}', name: 'api_firm_pricing_rules_update', methods: ['PATCH'])]
    public function updateRule(int $firmId, int $ruleId, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) return $firm;

        $rule = $this->em->find(PricingRule::class, $ruleId);
        if (!$rule || $rule->getFirm()->getId() !== $firm->getId()) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Règle introuvable.']], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('unitPrice', $data)) {
            if ((float) $data['unitPrice'] < 0) {
                return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => 'unitPrice doit être >= 0.']], 422);
            }
            $rule->setUnitPrice((string) $data['unitPrice']);
        }
        if (array_key_exists('currency', $data)) {
            $rule->setCurrency((string) $data['currency']);
        }

        if (array_key_exists('validFrom', $data) || array_key_exists('validTo', $data)) {
            [$validFrom, $validTo, $dateError] = $this->parseValidityDates($data, $rule);
            if ($dateError !== null) {
                return $this->json(['error' => ['status' => 422, 'code' => 'VALIDATION_FAILED', 'message' => $dateError]], 422);
            }
            if (array_key_exists('validFrom', $data)) {
                $rule->setValidFrom($validFrom);
            }
            if (array_key_exists('validTo', $data)) {
                $rule->setValidTo($validTo);
            }
        }

        if (array_key_exists('active', $data)) {
            $rule->setActive((bool) $data['active']);
        }

        $this->writeService->update($rule);
        return $this->json($this->serializeRule($rule));
    }

    #[Route('/pricing-rules/{ruleId}', name: 'api_firm_pricing_rules_delete', methods: ['DELETE'])]
    public function deleteRule(int $firmId, int $ruleId): JsonResponse
    {
        $this->denyAccessUnlessGranted(BillingVoter::MANAGE);

        $firm = $this->getFirmOr404($firmId);
        if ($firm instanceof JsonResponse) return $firm;

        $rule = $this->em->find(PricingRule::class, $ruleId);
        if (!$rule || $rule->getFirm()->getId() !== $firm->getId()) {
            return $this->json(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Règle introuvable.']], 404);
        }

        $this->writeService->delete($rule);

        return $this->json(['id' => $ruleId, 'deleted' => true]);
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
    private function parseValidityDates(array $data, ?PricingRule $existing = null): array
    {
        try {
            $validFrom = array_key_exists('validFrom', $data)
                ? ($data['validFrom'] !== null ? new \DateTimeImmutable((string) $data['validFrom']) : null)
                : $existing?->getValidFrom();
            $validTo = array_key_exists('validTo', $data)
                ? ($data['validTo'] !== null ? new \DateTimeImmutable((string) $data['validTo']) : null)
                : $existing?->getValidTo();
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
