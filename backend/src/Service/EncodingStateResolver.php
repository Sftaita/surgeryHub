<?php

namespace App\Service;

use App\Dto\EncodingStateFacts;
use App\Entity\Mission;
use App\Enum\EncodingState;
use App\Enum\MissionStatus;

/**
 * Suivi des encodages (D-092) — définition canonique et UNIQUE de EncodingState.
 *
 * Aucune autre couche (SQL, contrôleur, frontend) ne doit dériver cet état. Le résumé de
 * période et la liste paginée passent tous deux par resolve(), via EncodingStateFacts.
 *
 * Ne mute jamais rien, n'accède à aucun repository, ne déclenche aucun lazy-load : les
 * comptages lui sont FOURNIS (factsFromMission() est réservé aux appelants qui ont déjà
 * chargé les collections, typiquement les tests ; le chemin de production construit les
 * facts depuis des agrégats groupés, cf. EncodingTrackingRepository).
 */
final class EncodingStateResolver
{
    /**
     * Statuts qui ne participent pas au cycle d'encodage : rien n'est ni possible ni
     * attendu. Volontairement explicite (liste blanche inversée) plutôt qu'un `default`,
     * pour qu'un futur MissionStatus ajouté à l'enum force une décision consciente ici
     * au lieu de tomber silencieusement dans une catégorie par défaut.
     */
    private const NON_ENCODABLE_STATUSES = [
        MissionStatus::DRAFT,
        MissionStatus::OPEN,
        MissionStatus::REJECTED,
        MissionStatus::CANCELLED,
    ];

    /**
     * $now est TOUJOURS explicite, jamais lu à l'intérieur : l'appelant le calcule une
     * seule fois pour toute la réponse. Sans ça, une mission qui se termine pendant le
     * parcours d'une période basculerait de UPCOMING à TO_ENCODE au milieu du même
     * payload, et le résumé ne totaliserait plus la liste. Rend aussi la dérivation
     * testable sans abstraction d'horloge (le projet n'en a pas).
     */
    public function resolve(EncodingStateFacts $facts, \DateTimeImmutable $now): EncodingState
    {
        // 1. Hors cycle d'encodage — prioritaire sur tout le reste : une mission annulée
        //    ne doit jamais apparaître comme un encodage en retard, même si l'instrumentiste
        //    avait commencé à saisir avant l'annulation.
        if (in_array($facts->status, self::NON_ENCODABLE_STATUSES, true)) {
            return EncodingState::NOT_APPLICABLE;
        }

        // 2. Verrouillage réellement irréversible. invoiceGeneratedAt est le seul verrou
        //    comptable (une facture existe) ; CLOSED est terminal car reopen() le refuse
        //    (D-070). encodingLockedAt n'est délibérément PAS testé ici : validate() le
        //    pose systématiquement, donc l'utiliser ferait disparaître l'état VALIDATED.
        if ($facts->invoiceGeneratedAt !== null || $facts->status === MissionStatus::CLOSED) {
            return EncodingState::LOCKED;
        }

        if ($facts->status === MissionStatus::VALIDATED) {
            return EncodingState::VALIDATED;
        }

        if ($facts->status === MissionStatus::SUBMITTED) {
            return EncodingState::SUBMITTED;
        }

        // 3. Encodage entamé. Le statut explicite ne suffit pas : start() est optionnel
        //    (D-070), un instrumentiste peut saisir interventions/matériel/heures et
        //    soumettre directement depuis ASSIGNED. Ignorer les données déjà saisies
        //    afficherait "à encoder" sur une mission déjà largement encodée.
        if ($facts->status === MissionStatus::ENCODING_IN_PROGRESS || $this->hasEncodingEvidence($facts)) {
            return EncodingState::IN_PROGRESS;
        }

        // 4. Rien de saisi : seule la fin planifiée distingue "pas encore attendu" de
        //    "attendu et manquant". Borne stricte (endAt <= maintenant) — une mission qui
        //    se termine à l'instant précis du calcul est terminée.
        return ($facts->endAt !== null && $facts->endAt <= $now)
            ? EncodingState::TO_ENCODE
            : EncodingState::UPCOMING;
    }

    /**
     * Y a-t-il une trace d'encodage, quelle qu'en soit la forme ? encodingStartedAt seul
     * ne suffit pas (l'instrumentiste peut avoir cliqué "commencer" sans rien saisir),
     * et inversement des données peuvent exister sans qu'il ait jamais cliqué.
     */
    private function hasEncodingEvidence(EncodingStateFacts $facts): bool
    {
        return $facts->encodingStartedAt !== null
            || $facts->interventionCount > 0
            || $facts->activeMaterialLineCount > 0
            || $facts->hasExecutionActuals;
    }

    /**
     * Construit les facts depuis une entité Mission en parcourant ses collections.
     *
     * ATTENTION : déclenche un lazy-load des interventions et des lignes de matériel.
     * Réservé aux appelants unitaires (tests, traitement d'une mission isolée) — jamais
     * dans une boucle sur une liste, sous peine de N+1. Le chemin liste/résumé construit
     * les facts depuis des agrégats groupés.
     */
    public function factsFromMission(Mission $mission): EncodingStateFacts
    {
        $activeMaterialLines = 0;
        foreach ($mission->getMaterialLines() as $line) {
            if ((float) $line->getQuantity() > 0) {
                ++$activeMaterialLines;
            }
        }

        $execution = $mission->getExecution();
        $hasActuals = $execution !== null && (
            $execution->getActualStartAt() !== null
            || $execution->getActualEndAt() !== null
            || $execution->getActualDurationMinutes() !== null
        );

        return new EncodingStateFacts(
            status: $mission->getStatus(),
            endAt: $mission->getEndAt(),
            encodingStartedAt: $mission->getEncodingStartedAt(),
            invoiceGeneratedAt: $mission->getInvoiceGeneratedAt(),
            interventionCount: $mission->getInterventions()->count(),
            activeMaterialLineCount: $activeMaterialLines,
            hasExecutionActuals: $hasActuals,
        );
    }

    public function resolveForMission(Mission $mission, \DateTimeImmutable $now): EncodingState
    {
        return $this->resolve($this->factsFromMission($mission), $now);
    }
}
