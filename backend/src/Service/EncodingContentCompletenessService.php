<?php

namespace App\Service;

use App\Entity\MaterialItemRequest;
use App\Entity\Mission;
use App\Entity\MissionInterventionDraft;
use App\Exception\EncodingIncompleteException;

/**
 * D-146 bis — complément d'EncodingCompletenessService (heures réelles, D-143) : le
 * CONTENU d'un encodage doit lui aussi être complet avant sa soumission et, surtout, sa
 * validation définitive — sinon une mission est validée puis bloquée au calcul financier
 * (audit D-146 : firmes manquantes, réponses délégué absentes, choix Globus non
 * renseignés). Même format de refus (EncodingIncompleteException → ENCODING_INCOMPLETE,
 * `missing[{code, label, section}]`) : le frontend affiche exactement ce qui manque.
 *
 * Règles (évaluées contre le catalogue ACTUEL, jamais contre celui de l'encodage) :
 *  - SOUMISSION (instrumentiste ou manager) : chaque intervention réelle a une firme, les
 *    réponses obligatoires (délégué, système) sont données, aucun matériel incompatible
 *    avec le système choisi. Les demandes catalogue en attente NE bloquent PAS la
 *    soumission : c'est précisément au manager de les traiter pendant la phase SUBMITTED.
 *  - VALIDATION (manager) : mêmes règles, PLUS aucune demande en attente (demande
 *    d'intervention ou de matériel) — chacune conditionne le contenu définitif de la
 *    mission (une intervention ou une ligne de matériel peut encore y être ajoutée), qui
 *    ne doit jamais être verrouillé tant qu'elle n'est pas résolue ou ignorée.
 */
final class EncodingContentCompletenessService
{
    public const PENDING_INTERVENTION_REQUEST = 'PENDING_INTERVENTION_REQUEST';
    public const PENDING_MATERIAL_REQUEST = 'PENDING_MATERIAL_REQUEST';

    public function __construct(private readonly InterventionRequirementsService $requirements) {}

    /** @return list<array{code: string, label: string, section: string, missionInterventionId?: int}> */
    public function missingContent(Mission $mission, bool $forValidation): array
    {
        $missing = [];

        foreach ($mission->getInterventions() as $intervention) {
            foreach ($this->requirements->missingFor($intervention) as $code) {
                $missing[] = [
                    'code' => $code,
                    'label' => InterventionRequirementsService::label($code, $intervention),
                    'section' => 'interventions',
                    'missionInterventionId' => (int) $intervention->getId(),
                ];
            }
        }

        if ($forValidation) {
            foreach ($mission->getMissionInterventionDrafts() as $draft) {
                if ($draft->getStatus() === MissionInterventionDraft::STATUS_OPEN) {
                    $missing[] = [
                        'code' => self::PENDING_INTERVENTION_REQUEST,
                        'label' => sprintf('Demande d\'intervention « %s » encore en attente : résolvez-la ou ignorez-la dans Demandes', $draft->getLabel()),
                        'section' => 'requests',
                    ];
                }
            }
            foreach ($mission->getMaterialItemRequests() as $request) {
                if ($request->getStatus() === MaterialItemRequest::STATUS_PENDING) {
                    $missing[] = [
                        'code' => self::PENDING_MATERIAL_REQUEST,
                        'label' => sprintf('Demande de matériel « %s » encore en attente : résolvez-la ou ignorez-la dans Demandes', $request->getLabel()),
                        'section' => 'requests',
                    ];
                }
            }
        }

        return $missing;
    }

    /** @throws EncodingIncompleteException */
    public function assertComplete(Mission $mission, bool $forValidation, string $message): void
    {
        $missing = $this->missingContent($mission, $forValidation);
        if ($missing !== []) {
            throw new EncodingIncompleteException($message, $missing);
        }
    }
}
