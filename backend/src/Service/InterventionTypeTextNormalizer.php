<?php

namespace App\Service;

/**
 * Task 11 — normalisation pure (casse, accents, ponctuation, espaces) utilisée pour le
 * rapprochement de doublons potentiels. Ne sert jamais à décider seul d'une fusion — voir
 * InterventionTypeSimilarityService et docs/decisions.md.
 */
final class InterventionTypeTextNormalizer
{
    public function normalize(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if ($ascii === false) {
            $ascii = $text;
        }
        $ascii = strtolower($ascii);
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? $ascii;

        return trim(preg_replace('/\s+/', ' ', $ascii) ?? $ascii);
    }
}
