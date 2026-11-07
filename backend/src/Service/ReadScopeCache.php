<?php

namespace App\Service;

use Symfony\Contracts\Service\ResetInterface;

/**
 * D-142 — mémorisation des lectures de configuration tarifaire, active UNIQUEMENT pendant
 * un appel explicite à within() (lecture seule, ex. la vue de régularisation qui rejoue
 * FinancialCalculationService::evaluate() sur des dizaines de missions).
 *
 * Hors de within(), remember() exécute toujours la requête : calculate(), recalculate(),
 * les écritures du catalogue et le worker Messenger ne voient jamais de valeur mémorisée.
 * Le cache est vidé à la sortie de within() (et par reset() entre deux requêtes/messages).
 * Ce n'est pas un second moteur : les mêmes résolveurs exécutent les mêmes requêtes, une
 * seule fois par clé au lieu d'une fois par ligne.
 */
final class ReadScopeCache implements ResetInterface
{
    /** @var array<string, mixed>|null null = aucune portée active */
    private ?array $entries = null;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function within(callable $fn): mixed
    {
        if ($this->entries !== null) {
            return $fn();
        }
        $this->entries = [];
        try {
            return $fn();
        } finally {
            $this->entries = null;
        }
    }

    /**
     * @template T
     * @param callable(): T $load
     * @return T
     */
    public function remember(?string $key, callable $load): mixed
    {
        if ($this->entries === null || $key === null) {
            return $load();
        }
        if (!array_key_exists($key, $this->entries)) {
            $this->entries[$key] = $load();
        }

        return $this->entries[$key];
    }

    public function isActive(): bool
    {
        return $this->entries !== null;
    }

    public function reset(): void
    {
        $this->entries = null;
    }
}
