<?php

namespace App\Service\Prestation;

use App\Entity\Mission;
use App\Exception\PrestationConflictException;

/**
 * D-156 — une mission qui porte déjà des données d'encodage (heures réelles propres,
 * présence partagée, interventions, matériel, demandes d'intervention) ne change jamais
 * d'instrumentiste, de créneau, ni ne disparaît (annulation / remise au pool) en silence :
 * ces données appartiennent à la personne qui les a saisies (heures) ou à la salle
 * (interventions, matériel) — jamais transférées ni effacées implicitement.
 *
 * Une mission sans aucune donnée d'encodage n'est pas concernée : tout reste aussi simple
 * qu'avant (Sophie → Salve avant l'opération, aucune manipulation supplémentaire).
 *
 * Corrections contrôlées et auditées :
 * - heures communes d'une prestation : POST /api/missions/{id}/encoding/detach-presence ;
 * - interventions / matériel seuls : réaffectation explicitement confirmée
 *   (`confirmEncodingData` + motif — MissionPostDeployService::reassign()), qui laisse ces
 *   données sur la mission, auteurs d'origine (createdBy) conservés.
 */
final class MissionEncodingDataGuard
{
    /** @return array{hasOwnHours: bool, presenceId: ?int, interventionCount: int, materialLineCount: int, interventionRequestCount: int} */
    public static function facts(Mission $mission): array
    {
        $execution = $mission->getExecution();

        return [
            'hasOwnHours' => $execution !== null && $execution->getPresence() === null && (
                $execution->getActualStartAt() !== null
                || $execution->getActualEndAt() !== null
                || $execution->getActualDurationMinutes() !== null
            ),
            'presenceId' => $execution?->getPresence()?->getId(),
            'interventionCount' => $mission->getInterventions()->count(),
            'materialLineCount' => $mission->getMaterialLines()->count(),
            'interventionRequestCount' => $mission->getMissionInterventionDrafts()->count(),
        ];
    }

    public static function hasEncodingData(Mission $mission): bool
    {
        $f = self::facts($mission);

        return $f['hasOwnHours'] || $f['presenceId'] !== null
            || $f['interventionCount'] > 0 || $f['materialLineCount'] > 0 || $f['interventionRequestCount'] > 0;
    }

    /**
     * @param string $operation libellé de l'opération refusée (« réaffecter », « annuler »…)
     * @param bool $contentConfirmed la réaffectation a été explicitement confirmée : seules
     *        les heures (propres ou partagées) bloquent encore — elles ne suivent jamais la mission
     */
    public static function assertNone(Mission $mission, string $operation, bool $contentConfirmed = false): void
    {
        $f = self::facts($mission);
        $hoursBlock = $f['hasOwnHours'] || $f['presenceId'] !== null;
        $contentBlock = $f['interventionCount'] > 0 || $f['materialLineCount'] > 0 || $f['interventionRequestCount'] > 0;

        if (!$hoursBlock && (!$contentBlock || $contentConfirmed)) {
            return;
        }

        // « d'annuler », « de réaffecter » : élision devant une voyelle.
        $impossible = preg_match('/^[aeiouéèê]/iu', $operation) === 1 ? "Impossible d'" : 'Impossible de ';

        throw new PrestationConflictException(
            PrestationConflictException::MISSION_HAS_ENCODING_DATA,
            $hoursBlock
                ? sprintf("%s%s cette mission : des heures réelles y sont déjà enregistrées pour l'instrumentiste actuelle. Elles ne sont jamais transférées ni effacées automatiquement — %s.", $impossible, $operation,
                    $f['presenceId'] !== null ? 'détachez d\'abord la mission de la présence commune (correction contrôlée)' : 'faites d\'abord corriger l\'encodage')
                : sprintf("%s%s cette mission sans confirmation : elle porte déjà des interventions ou du matériel encodés, qui resteront attachés à la mission.", $impossible, $operation),
            ['missionId' => $mission->getId(), 'operation' => $operation, 'confirmable' => !$hoursBlock] + $f,
        );
    }
}
