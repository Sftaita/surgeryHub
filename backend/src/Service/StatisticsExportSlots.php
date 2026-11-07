<?php

namespace App\Service;

use App\Exception\StatisticsExportException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-187 — nombre d'exports PDF des statistiques financières générés en même temps.
 *
 * Un export proche du plafond D-186 (1 000 lignes) relève sa mémoire à 512 Mo ; php-fpm a 5
 * processus et le serveur aucune limite mémoire par conteneur : cinq gros exports simultanés
 * pourraient saturer la RAM. Chaque export occupe un « emplacement » : un fichier verrouillé par
 * flock() (LOCK_EX | LOCK_NB), sans attente. Tous occupés → refus immédiat et explicite (429),
 * jamais de file d'attente qui immobiliserait d'autres processus php-fpm.
 *
 * Pourquoi flock : aucune dépendance (ni symfony/lock, ni Redis), et le verrou appartient au
 * descripteur de fichier — fermé en fin de run(), en fin de requête (fatal error, dépassement
 * mémoire compris) ou à la mort du processus. Un verrou ne peut donc pas rester bloqué. Les exports
 * ne sont servis que par le conteneur php (le worker n'en génère pas) : un verrou local suffit ;
 * avec plusieurs conteneurs php, il faudrait un verrou partagé (MySQL GET_LOCK).
 *
 * Seule la génération est limitée : la consultation des statistiques n'est jamais concernée.
 */
final class StatisticsExportSlots
{
    public const CAPACITY = 2;
    public const RETRY_AFTER_SECONDS = 30;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/lock/statistics-export')]
        private readonly string $directory,
        private readonly int $capacity = self::CAPACITY,
    ) {
    }

    /**
     * Exécute $work en occupant un emplacement, libéré dans tous les cas (succès ou exception).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws StatisticsExportException capacité atteinte (STATISTICS_EXPORT_BUSY, 429)
     */
    public function run(callable $work): mixed
    {
        $slot = $this->acquire() ?? throw StatisticsExportException::busy($this->capacity, self::RETRY_AFTER_SECONDS);
        try {
            return $work();
        } finally {
            flock($slot, LOCK_UN);
            fclose($slot);
        }
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    /** @return resource|null */
    private function acquire()
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Répertoire des verrous d\'export inaccessible : %s', $this->directory));
        }
        for ($i = 1; $i <= $this->capacity; ++$i) {
            $handle = @fopen(sprintf('%s/slot-%d.lock', $this->directory, $i), 'c');
            if ($handle === false) {
                throw new \RuntimeException(sprintf('Verrou d\'export inaccessible dans %s', $this->directory));
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            fclose($handle);
        }

        return null;
    }
}
