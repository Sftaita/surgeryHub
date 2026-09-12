<?php

namespace App\Service;

/**
 * Task 11 — normalisation pure (casse, accents, ponctuation, espaces) utilisée pour le
 * rapprochement de doublons potentiels. Ne sert jamais à décider seul d'une fusion — voir
 * InterventionTypeSimilarityService et docs/decisions.md.
 *
 * Stabilisation pré-déploiement D-118 (2026-09-12) — correctif indépendant, sans rapport
 * avec D-118 : `iconv('UTF-8', 'ASCII//TRANSLIT', ...)` dépend des tables de translittération
 * de la glibc/libc de la machine hôte, qui varient réellement d'un environnement à l'autre.
 * Reproduit et vérifié : sur cette machine, "Prothèse" translittère en "proth ese" (espace
 * parasite là où "è" devrait donner "e"), cassant toute comparaison censée être insensible
 * aux accents — de façon déterministe ici, pas seulement de façon intermittente en suite
 * complète. `Normalizer::normalize()` (ICU, ext-intl) décompose en NFD (lettre de base +
 * marque diacritique séparée), puis \p{Mn} retire uniquement les marques diacritiques —
 * comportement garanti identique sur toute plateforme, ICU étant embarqué avec l'extension
 * plutôt que lu depuis les tables système. ext-intl est déjà installée en dev et en
 * production (voir docker/php/Dockerfile{,.prod}), donc aucune nouvelle dépendance
 * d'infrastructure.
 */
final class InterventionTypeTextNormalizer
{
    public function normalize(string $text): string
    {
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
        $ascii = $decomposed === false ? $text : preg_replace('/\p{Mn}/u', '', $decomposed);
        $ascii = strtolower($ascii ?? $text);
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? $ascii;

        return trim(preg_replace('/\s+/', ' ', $ascii) ?? $ascii);
    }
}
