<?php

namespace App\Service;

use App\Entity\Mission;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-171 — sérialise les écritures d'un encodage (interventions, matériel, demandes de catalogue,
 * heures réelles) et ses transitions (start, complete, validate, reject, reopen,
 * complete-and-validate) sur la ligne `mission` (SELECT … FOR UPDATE), de la vérification à la
 * validation de la transaction.
 *
 * Les droits (voters, MissionEncodingGuard) sont évalués sur la mission lue au début de la
 * requête ; sous le verrou, l'état réellement validé en base est relu et comparé : statut,
 * instrumentiste, verrou d'encodage, facturation, activité sans instrumentiste. Un écart signifie
 * qu'une transition a été validée entre-temps (soumission, validation, réouverture, affectation)
 * → 409, rien n'est écrit. Une écriture ne peut donc jamais aboutir sur un encodage validé après
 * coup, et une validation attend la fin d'une écriture en cours (puis la voit : la révision
 * attendue, D-171, ne correspond plus).
 *
 * $verify : contrôle supplémentaire exécuté sous le verrou (ex. heures encore manquantes).
 */
final class MissionEncodingLock
{
    public const CONFLICT_MESSAGE = "La mission a été modifiée entre-temps (encodage soumis, validé, rouvert ou réaffecté) : rien n'a été enregistré. Rechargez-la avant de recommencer.";

    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @template T
     * @param callable(): T $change
     * @param (callable(): void)|null $verify
     * @return T
     */
    public function run(Mission $mission, callable $change, ?callable $verify = null): mixed
    {
        $connection = $this->em->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $this->lockAndVerify($mission);
            if ($verify !== null) {
                $verify();
            }
            $result = $change(); // flushes its own writes, inside this transaction
            if ($ownsTransaction) {
                $connection->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    private function lockAndVerify(Mission $mission): void
    {
        if ($mission->getId() === null) {
            return;
        }

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, instrumentist_id, encoding_locked_at, invoice_generated_at, executed_without_instrumentist_at
             FROM mission WHERE id = ? FOR UPDATE',
            [$mission->getId()],
            [ParameterType::INTEGER],
        );
        if (!is_array($row)) {
            throw new ConflictHttpException(self::CONFLICT_MESSAGE);
        }

        $instrumentistId = $row['instrumentist_id'] !== null ? (int) $row['instrumentist_id'] : null;
        if ($row['status'] !== $mission->getStatus()->value
            || $instrumentistId !== $mission->getInstrumentist()?->getId()
            || ($row['encoding_locked_at'] !== null) !== ($mission->getEncodingLockedAt() !== null)
            || ($row['invoice_generated_at'] !== null) !== ($mission->getInvoiceGeneratedAt() !== null)
            || ($row['executed_without_instrumentist_at'] !== null) !== $mission->isExecutedWithoutInstrumentist()) {
            throw new ConflictHttpException(self::CONFLICT_MESSAGE);
        }
    }

    /**
     * Heures déclarées après une validation sans heures (D-171) : sous le verrou, elles doivent
     * toujours manquer en base — jamais deux déclarations concurrentes dont la seconde écraserait
     * des heures déjà verrouillées.
     */
    public function assertHoursStillMissing(Mission $mission): void
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT actual_start_at, actual_end_at, actual_duration_minutes FROM mission_execution WHERE mission_id = ? FOR UPDATE',
            [$mission->getId()],
            [ParameterType::INTEGER],
        );
        if (is_array($row) && (($row['actual_start_at'] !== null && $row['actual_end_at'] !== null) || $row['actual_duration_minutes'] !== null)) {
            throw new ConflictHttpException("Les heures prestées viennent d'être déclarées par une autre personne : elles sont désormais verrouillées. Rechargez la mission.");
        }
    }
}
