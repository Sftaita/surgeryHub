<?php

namespace App\Service;

use App\Entity\InterventionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Task 11 — détection de doublons potentiels par rapprochement de texte normalisé
 * (casse/accents/espaces/ponctuation). Suggestion manuelle uniquement : ne bloque jamais
 * une création et ne fusionne jamais rien automatiquement (voir docs/decisions.md,
 * section "Fusion de doublons" — la similarité textuelle seule ne prouve pas que deux
 * interventions sont réellement identiques).
 */
final class InterventionTypeSimilarityService
{
    private const CONFIDENCE_HIGH = 'HIGH';
    private const CONFIDENCE_MEDIUM = 'MEDIUM';
    private const CONFIDENCE_LOW = 'LOW';

    /**
     * Un rapprochement manuel n'a de sens que pour une poignée de candidats — au-delà,
     * ce n'est plus une suggestion exploitable. Protège aussi contre une explosion de la
     * taille de réponse si un catalogue contient beaucoup de libellés courts/génériques
     * qui se ressemblent tous un peu (voir InterventionTypeAuditService, appelé sur
     * l'ensemble du référentiel).
     */
    private const MAX_CANDIDATES = 10;

    public function __construct(private readonly InterventionTypeTextNormalizer $normalizer) {}

    /**
     * @return list<array{type: InterventionType, confidence: string}> triés HIGH puis MEDIUM puis LOW
     */
    public function findCandidates(string $label, EntityManagerInterface $em, ?int $excludeId = null): array
    {
        $normalizedInput = $this->normalizer->normalize($label);
        if ($normalizedInput === '') {
            return [];
        }

        $qb = $em->getRepository(InterventionType::class)->createQueryBuilder('it')
            ->andWhere('it.active = true')
            ->andWhere('it.mergedInto IS NULL');
        if ($excludeId !== null) {
            $qb->andWhere('it.id != :excludeId')->setParameter('excludeId', $excludeId);
        }
        /** @var InterventionType[] $types */
        $types = $qb->getQuery()->getResult();

        return $this->rankAgainst($normalizedInput, $types);
    }

    /**
     * Variante de findCandidates() pour un usage en boucle (ex : audit global comparant
     * chaque type à tous les autres) — le candidat pool doit être chargé UNE SEULE fois
     * par l'appelant, jamais requêté à nouveau à chaque itération (O(n) hydratations
     * répétées dans une boucle O(n) = O(n²), catastrophique passé quelques centaines de
     * types — voir InterventionTypeAuditService).
     *
     * @param InterventionType[] $pool
     * @return list<array{type: InterventionType, confidence: string}>
     */
    public function findCandidatesInPool(string $label, array $pool, ?int $excludeId = null): array
    {
        $normalizedInput = $this->normalizer->normalize($label);
        if ($normalizedInput === '') {
            return [];
        }

        $filtered = $excludeId !== null
            ? array_filter($pool, fn (InterventionType $t) => $t->getId() !== $excludeId)
            : $pool;

        return $this->rankAgainst($normalizedInput, $filtered);
    }

    /**
     * @param InterventionType[] $types
     * @return list<array{type: InterventionType, confidence: string}>
     */
    private function rankAgainst(string $normalizedInput, array $types): array
    {
        $candidates = [];
        foreach ($types as $type) {
            $confidence = $this->compare($normalizedInput, $this->normalizer->normalize($type->getLabel()));
            if ($confidence !== null) {
                $candidates[] = ['type' => $type, 'confidence' => $confidence];
            }
        }

        usort($candidates, fn (array $a, array $b) => $this->rank($b['confidence']) <=> $this->rank($a['confidence']));

        return array_slice($candidates, 0, self::MAX_CANDIDATES);
    }

    /**
     * Compare deux InterventionType actifs entre eux (utilisé par l'audit global des
     * doublons — voir InterventionTypeAuditService).
     */
    public function confidenceBetween(InterventionType $a, InterventionType $b): ?string
    {
        return $this->compare($this->normalizer->normalize($a->getLabel()), $this->normalizer->normalize($b->getLabel()));
    }

    private function compare(string $a, string $b): ?string
    {
        if ($a === '' || $b === '') {
            return null;
        }
        if ($a === $b) {
            return self::CONFIDENCE_HIGH;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            return self::CONFIDENCE_MEDIUM;
        }

        similar_text($a, $b, $percent);
        if ($percent >= 75.0) {
            return self::CONFIDENCE_MEDIUM;
        }
        if ($percent >= 55.0) {
            return self::CONFIDENCE_LOW;
        }

        return null;
    }

    private function rank(string $confidence): int
    {
        return match ($confidence) {
            self::CONFIDENCE_HIGH => 3,
            self::CONFIDENCE_MEDIUM => 2,
            self::CONFIDENCE_LOW => 1,
            default => 0,
        };
    }
}
