<?php

namespace App\Exception;

use App\Entity\PricingRule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-138 — plusieurs PricingRule actives couvrent la même cible à la même date. Le
 * PricingRuleWriteService l'interdit sous verrou ; ce cas ne peut naître que de données
 * écrites hors de l'application (SQL direct, migration, import). Le résolveur ne choisit
 * JAMAIS entre elles : il lève cette exception métier, que le moteur financier convertit
 * en anomalie CONFLICTING_FIRM_*_RATE et que les autres endpoints exposent en 409
 * PRICING_RULE_CONFLICT (ApiExceptionSubscriber) — jamais un 500.
 */
class PricingRuleConflictException extends ConflictHttpException
{
    /** @param PricingRule[] $rules */
    public function __construct(private readonly array $rules, private readonly \DateTimeImmutable $date)
    {
        parent::__construct(sprintf(
            '%d règles tarifaires actives se chevauchent pour la même cible au %s (règles %s) : aucun tarif n\'est appliqué tant que le conflit n\'est pas corrigé.',
            count($rules),
            $date->format('d/m/Y'),
            implode(', ', array_map(static fn (PricingRule $r) => '#' . $r->getId(), $rules)),
        ));
    }

    /** @return PricingRule[] */
    public function getRules(): array
    {
        return $this->rules;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    /**
     * Instantané stable des règles en conflit — repris tel quel dans le contexte audité
     * de l'anomalie (le payload d'audit ne doit pas dépendre de l'état futur des règles).
     *
     * @return list<array{id: ?int, unitPrice: ?string, currency: ?string, validFrom: ?string, validTo: ?string}>
     */
    public function rulesSnapshot(): array
    {
        return array_map(static fn (PricingRule $r) => [
            'id' => $r->getId(),
            'unitPrice' => $r->getUnitPrice(),
            'currency' => $r->getCurrency(),
            'validFrom' => $r->getValidFrom()?->format('Y-m-d'),
            'validTo' => $r->getValidTo()?->format('Y-m-d'),
        ], array_values($this->rules));
    }
}
