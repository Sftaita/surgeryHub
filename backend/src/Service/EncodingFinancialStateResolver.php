<?php

namespace App\Service;

use App\Dto\MissionFinancialFacts;
use App\Enum\EncodingFinancialState;
use App\Enum\EncodingState;

/**
 * Suivi des encodages (D-118) — dérive le statut financier synthétique affiché sur le
 * cockpit d'encodage. Ne lit que des faits déjà persistés : ne relance jamais
 * FinancialCalculationService, ne résout aucun tarif, ne calcule aucun solde.
 */
final class EncodingFinancialStateResolver
{
    public function resolve(EncodingState $encodingState, MissionFinancialFacts $facts): EncodingFinancialState
    {
        // L'avancement réel prime : dès qu'un document est émis, la mission a dépassé le
        // stade du calcul, quel que soit son statut d'encodage courant.
        if ($facts->hasIssuedDocument) {
            return $facts->allIssuedDocumentsPaid
                ? EncodingFinancialState::PAID
                : EncodingFinancialState::DOCUMENTED;
        }

        // Avant CALCULATED : un recalcul échoué laisse l'ancien calcul actif (D-073). Sans
        // cette priorité, une mission dont la revalorisation vient d'échouer s'afficherait
        // sereinement "Calculé" alors qu'elle réclame une action.
        if ($facts->hasUnresolvedCalculationFailure) {
            return EncodingFinancialState::ANOMALY;
        }

        if ($facts->hasActiveCalculation) {
            return EncodingFinancialState::CALCULATED;
        }

        // Seul VALIDATED est facturable (MissionEncodingWorkflowService::isBillable()).
        // Une mission LOCKED sans artefact financier est CLOSED sans facturation : plus
        // rien n'est à calculer, ce n'est pas un travail en attente.
        return $encodingState === EncodingState::VALIDATED
            ? EncodingFinancialState::TO_CALCULATE
            : EncodingFinancialState::NOT_CALCULABLE;
    }
}
