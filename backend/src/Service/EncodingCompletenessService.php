<?php

namespace App\Service;

use App\Entity\Mission;
use App\Enum\EncodingSituation;
use App\Exception\EncodingIncompleteException;

/**
 * D-143 — définition UNIQUE de « ce qu'il manque » à un encodage :
 *  - pour le soumettre (MissionEncodingWorkflowService::complete()) ou le valider
 *    (::validate()) — refus explicite, jamais un repli silencieux ;
 *  - pour qualifier une relance (EncodingReminderService) — le message n'affirme jamais
 *    qu'il manque une intervention ou du matériel : aucune règle métier ne permet de le
 *    savoir, seules les heures réelles sont obligatoires de façon certaine (la
 *    justification « sans matériel » d'un BLOCK reste vérifiée à part par complete(),
 *    règle D-080 inchangée).
 *
 * Règle des heures : un début ET une fin réels, fin strictement postérieure au début
 * (déjà garanti à l'écriture par MissionExecutionService::updateActuals()). Une durée
 * seule (saisies antérieures à D-143) ou les horaires planifiés ne suffisent JAMAIS :
 * l'absence d'heures réelles ne signifie pas que le planning a été respecté.
 */
final class EncodingCompletenessService
{
    public const MISSING_ACTUAL_START = 'ACTUAL_START_TIME';
    public const MISSING_ACTUAL_END   = 'ACTUAL_END_TIME';
    public const INCOHERENT_ACTUAL_TIMES = 'ACTUAL_TIMES_INCOHERENT';

    private const LABELS = [
        self::MISSING_ACTUAL_START    => 'Heure de début prestée',
        self::MISSING_ACTUAL_END      => 'Heure de fin prestée',
        self::INCOHERENT_ACTUAL_TIMES => "L'heure de fin doit être postérieure à l'heure de début",
    ];

    /** @return list<string> codes des données obligatoires manquantes pour soumettre */
    public function missingForSubmission(Mission $mission): array
    {
        // D-155 — activité exécutée sans instrumentiste : il n'existe aucune heure
        // d'instrumentiste à déclarer (jamais fabriquée), donc rien n'est exigé ici ; le
        // contenu (interventions, matériel) reste contrôlé par EncodingContentCompletenessService.
        if ($mission->isExecutedWithoutInstrumentist()) {
            return [];
        }

        $execution = $mission->getExecution();
        $start = $execution?->getActualStartAt();
        $end = $execution?->getActualEndAt();

        $missing = [];
        if ($start === null) {
            $missing[] = self::MISSING_ACTUAL_START;
        }
        if ($end === null) {
            $missing[] = self::MISSING_ACTUAL_END;
        }
        if ($start !== null && $end !== null && $end <= $start) {
            $missing[] = self::INCOHERENT_ACTUAL_TIMES;
        }

        return $missing;
    }

    /**
     * @throws EncodingIncompleteException si une donnée obligatoire manque
     */
    public function assertSubmittable(Mission $mission, string $message): void
    {
        $missing = $this->missingForSubmission($mission);
        if ($missing === []) {
            return;
        }

        throw new EncodingIncompleteException($message, array_map(
            static fn (string $code) => ['code' => $code, 'label' => self::LABELS[$code], 'section' => 'hours'],
            $missing,
        ));
    }

    /**
     * Validation manager d'une mission DÉJÀ soumise : des heures réellement déclarées sont
     * exigées (jamais une valorisation sur le seul planifié), mais une durée seule saisie
     * avant D-143 reste acceptée — l'ancienne interface ne permettait rien d'autre et ces
     * déclarations sont légitimes. Seule l'absence totale d'heures réelles est refusée.
     *
     * @throws EncodingIncompleteException
     */
    public function assertHasDeclaredHours(Mission $mission, string $message): void
    {
        if ($mission->isExecutedWithoutInstrumentist()) {
            return; // D-155 — aucune heure d'instrumentiste n'existe ni ne doit exister.
        }

        $execution = $mission->getExecution();
        if ($execution !== null && ($execution->hasCompleteActualTimes() || $execution->getActualDurationMinutes() !== null)) {
            return;
        }

        $this->assertSubmittable($mission, $message);
    }

    /** Quelque chose a-t-il déjà été enregistré côté serveur pour cet encodage ? */
    public function hasAnyEncodedData(Mission $mission): bool
    {
        $execution = $mission->getExecution();
        if ($execution !== null && (
            $execution->getActualStartAt() !== null
            || $execution->getActualEndAt() !== null
            || $execution->getActualDurationMinutes() !== null
        )) {
            return true;
        }

        return !$mission->getInterventions()->isEmpty()
            || !$mission->getMaterialLines()->isEmpty()
            || !$mission->getMissionInterventionDrafts()->isEmpty();
    }

    /** Situation d'un encodage dont la soumission est encore attendue. */
    public function situation(Mission $mission): EncodingSituation
    {
        if (!$this->hasAnyEncodedData($mission) && $mission->getEncodingStartedAt() === null) {
            return EncodingSituation::NOT_STARTED;
        }

        return $this->missingForSubmission($mission) !== []
            ? EncodingSituation::HOURS_MISSING
            : EncodingSituation::NOT_SUBMITTED;
    }

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? $code;
    }
}
