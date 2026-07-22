<?php

namespace App\Enum;

enum AuditEventType: string
{
    case MISSION_DECLARED = 'MISSION_DECLARED';
    case MISSION_DECLARED_APPROVED = 'MISSION_DECLARED_APPROVED';
    case MISSION_DECLARED_REJECTED = 'MISSION_DECLARED_REJECTED';

    // Planning generation & deployment
    case PLANNING_GENERATED              = 'PLANNING_GENERATED';
    case PLANNING_UNASSIGNED_HANDLED     = 'PLANNING_UNASSIGNED_HANDLED';
    case PLANNING_DEPLOYED               = 'PLANNING_DEPLOYED';
    case PLANNING_ARCHIVED               = 'PLANNING_ARCHIVED';
    case MISSION_CREATED_FROM_PLANNING   = 'MISSION_CREATED_FROM_PLANNING';
    case MISSION_OPENED_FROM_PLANNING    = 'MISSION_OPENED_FROM_PLANNING';
    case MISSION_ASSIGNED_FROM_PLANNING  = 'MISSION_ASSIGNED_FROM_PLANNING';

    // Planning V2 alerts (Batch 4) — only written on an actual state change, never on an
    // idempotent no-op repeat of the same transition.
    case PLANNING_ALERT_ACKNOWLEDGED = 'PLANNING_ALERT_ACKNOWLEDGED';
    case PLANNING_ALERT_RESOLVED     = 'PLANNING_ALERT_RESOLVED';
    case PLANNING_ALERT_IGNORED      = 'PLANNING_ALERT_IGNORED';

    // Planning V2 alert actions (Batch 5) — the actual Mission mutation, paired with the
    // alert resolution it always triggers.
    case PLANNING_ALERT_REASSIGNED          = 'PLANNING_ALERT_REASSIGNED';
    case PLANNING_ALERT_OPENED_AS_AVAILABLE = 'PLANNING_ALERT_OPENED_AS_AVAILABLE';

    // Post-deploy living planning transitions (Batch 15A — dispatched in 15B+) ─
    case MISSION_RELEASED_TO_POOL          = 'MISSION_RELEASED_TO_POOL';
    case MISSION_CANCELLED_POST_DEPLOY     = 'MISSION_CANCELLED_POST_DEPLOY';
    case MISSION_REASSIGNED_POST_DEPLOY    = 'MISSION_REASSIGNED_POST_DEPLOY';
    case MISSION_TIME_CHANGED_POST_DEPLOY  = 'MISSION_TIME_CHANGED_POST_DEPLOY';
    case MISSION_ADDED_POST_DEPLOY         = 'MISSION_ADDED_POST_DEPLOY';
    case MISSION_CLAIMED_FROM_POOL         = 'MISSION_CLAIMED_FROM_POOL';

    // Automated (D-064) — MissionStartDueCommand, ASSIGNED -> IN_PROGRESS on startAt.
    case MISSION_STARTED = 'MISSION_STARTED';

    // Encoding workflow (Lot 7, D-070) — MissionEncodingWorkflowService.
    case MISSION_ENCODING_STARTED   = 'MISSION_ENCODING_STARTED';
    case MISSION_ENCODING_COMPLETED = 'MISSION_ENCODING_COMPLETED';
    case MISSION_ENCODING_REOPENED  = 'MISSION_ENCODING_REOPENED';
    case MISSION_ENCODING_VALIDATED = 'MISSION_ENCODING_VALIDATED';
    case MISSION_ENCODING_REJECTED  = 'MISSION_ENCODING_REJECTED';

    // Exécution & Valorisation, Lot 1 — MissionExecutionService. Le réalisé, distinct
    // du planifié (Mission) et de la future valorisation financière (FinancialCalculation).
    case MISSION_EXECUTION_CREATED           = 'MISSION_EXECUTION_CREATED';
    case MISSION_EXECUTION_UPDATED           = 'MISSION_EXECUTION_UPDATED';
    case MISSION_EXECUTION_DURATION_CHANGED  = 'MISSION_EXECUTION_DURATION_CHANGED';
    case MISSION_EXECUTION_DISPUTE_OPENED    = 'MISSION_EXECUTION_DISPUTE_OPENED';
    case MISSION_EXECUTION_DISPUTE_RESOLVED  = 'MISSION_EXECUTION_DISPUTE_RESOLVED';
    case MISSION_EXECUTION_DISPUTE_REJECTED  = 'MISSION_EXECUTION_DISPUTE_REJECTED';

    // Exécution & Valorisation, Lot 2 (D-072) — historisation des tarifs.
    // PricingRuleVersioningService / InstrumentistRateService. Non rattachés à une
    // Mission — voir AuditService::recordGlobal() (AuditEvent.mission désormais
    // nullable pour ces événements catalogue/tarifaires uniquement).
    case PRICING_RULE_CREATED           = 'PRICING_RULE_CREATED';
    case PRICING_RULE_SCHEDULED         = 'PRICING_RULE_SCHEDULED';
    case PRICING_RULE_REPLACED          = 'PRICING_RULE_REPLACED';
    case PRICING_RULE_FUTURE_UPDATED    = 'PRICING_RULE_FUTURE_UPDATED';
    case PRICING_RULE_FUTURE_CANCELLED  = 'PRICING_RULE_FUTURE_CANCELLED';

    case INSTRUMENTIST_RATE_CREATED           = 'INSTRUMENTIST_RATE_CREATED';
    case INSTRUMENTIST_RATE_SCHEDULED         = 'INSTRUMENTIST_RATE_SCHEDULED';
    case INSTRUMENTIST_RATE_REPLACED          = 'INSTRUMENTIST_RATE_REPLACED';
    case INSTRUMENTIST_RATE_FUTURE_UPDATED    = 'INSTRUMENTIST_RATE_FUTURE_UPDATED';
    case INSTRUMENTIST_RATE_FUTURE_CANCELLED  = 'INSTRUMENTIST_RATE_FUTURE_CANCELLED';

    // Exécution & Valorisation, Lot 3 (D-073) — FinancialCalculationService. Mission-
    // scopés (contrairement aux événements tarifaires du Lot 2) : AuditService::record()
    // classique, jamais recordGlobal().
    case FINANCIAL_CALCULATION_CREATED       = 'FINANCIAL_CALCULATION_CREATED';
    case FINANCIAL_CALCULATION_RECALCULATED  = 'FINANCIAL_CALCULATION_RECALCULATED';
    case FINANCIAL_CALCULATION_APPROVED      = 'FINANCIAL_CALCULATION_APPROVED';
    case FINANCIAL_CALCULATION_LOCKED        = 'FINANCIAL_CALCULATION_LOCKED';
    case FINANCIAL_CALCULATION_SUPERSEDED    = 'FINANCIAL_CALCULATION_SUPERSEDED';
    case FINANCIAL_CALCULATION_CANCELLED     = 'FINANCIAL_CALCULATION_CANCELLED';
    case FINANCIAL_CALCULATION_FAILED        = 'FINANCIAL_CALCULATION_FAILED';

    // Exécution & Valorisation, Lot 4 (D-074) — bascule des documents financiers vers
    // les lignes figées de FinancialCalculation. CREATED_FROM_CALCULATION est émis
    // uniquement par le nouveau chemin (createFromEligibleLines()) — le chemin legacy
    // (generate()) n'émettait déjà aucun audit avant ce lot, comportement inchangé.
    // ISSUED est émis sur la transition GENERATED → SENT existante (markSent()), pour
    // les documents legacy ET nouveaux : c'est le vrai point de bascule "engagé vis-à-vis
    // du tiers" dans ce produit, pas la création. Pas d'événements FINANCIAL_LINES_
    // RESERVED/RELEASED séparés : CREATED_FROM_CALCULATION/CANCELLED portent déjà la
    // liste des lignes consommées/libérées dans leur payload (éviter un audit redondant).
    case FIRM_INVOICE_CREATED_FROM_CALCULATION = 'FIRM_INVOICE_CREATED_FROM_CALCULATION';
    case FIRM_INVOICE_ISSUED                   = 'FIRM_INVOICE_ISSUED';
    case FIRM_INVOICE_CANCELLED                = 'FIRM_INVOICE_CANCELLED';

    case INSTRUMENTIST_STATEMENT_CREATED_FROM_CALCULATION = 'INSTRUMENTIST_STATEMENT_CREATED_FROM_CALCULATION';
    case INSTRUMENTIST_STATEMENT_ISSUED                    = 'INSTRUMENTIST_STATEMENT_ISSUED';
    case INSTRUMENTIST_STATEMENT_CANCELLED                 = 'INSTRUMENTIST_STATEMENT_CANCELLED';

    // Exécution & Valorisation, Lot 5 (D-075) — DocumentPaymentService, générique aux
    // deux types de document (Payment.documentType les distingue dans le payload). Pas
    // de DOCUMENT_SENT séparé : la transition GENERATED → SENT réutilise
    // FIRM_INVOICE_ISSUED/INSTRUMENTIST_STATEMENT_ISSUED (Lot 4) — créés pour ce fait
    // précis mais jamais câblés jusqu'ici, câblés dans ce lot (voir issue()) plutôt que
    // de dupliquer un événement pour la même transition.
    case DOCUMENT_PAYMENT_RECORDED  = 'DOCUMENT_PAYMENT_RECORDED';
    case DOCUMENT_PARTIALLY_PAID    = 'DOCUMENT_PARTIALLY_PAID';
    case DOCUMENT_FULLY_PAID        = 'DOCUMENT_FULLY_PAID';

    // Exécution & Valorisation, Lot 6 (D-076) — FinancialCorrectionService, générique
    // aux deux familles de document. FINANCIAL_CORRECTION_ISSUED est distinct de
    // FIRM_INVOICE_ISSUED/INSTRUMENTIST_STATEMENT_ISSUED (Lot 4/5, toujours émis en
    // parallèle sur la transition GENERATED→SENT du document correctif lui-même) :
    // celui-ci porte le contexte propre à la correction (document racine, solde avant/
    // après) — pas un doublon, deux faits liés mais distincts.
    case CREDIT_NOTE_CREATED            = 'CREDIT_NOTE_CREATED';
    case DEBIT_NOTE_CREATED             = 'DEBIT_NOTE_CREATED';
    case FINANCIAL_CORRECTION_ISSUED    = 'FINANCIAL_CORRECTION_ISSUED';
    case REFUND_RECORDED                = 'REFUND_RECORDED';
    case DOCUMENT_NET_BALANCE_CHANGED   = 'DOCUMENT_NET_BALANCE_CHANGED';

    /**
     * EPIC Revue instrumentiste, Lot 3 — MissionInterventionDraftService::
     * createForRequest(). Un seul événement pour toute l'action utilisateur (créer une
     * InterventionTypeRequest ET son MissionInterventionDraft, atomique) — pas de
     * INTERVENTION_TYPE_REQUEST_CREATED distinct : aucune obligation d'audit
     * pré-existante ne portait sur la création d'une InterventionTypeRequest avant ce
     * lot (ni InterventionTypeRequestService ni MaterialItemRequestService — son
     * miroir structurel — n'auditaient leur création), donc rien à préserver en
     * doublon ici. Le payload porte interventionTypeRequestId ET draftId : les deux
     * identifiants sont retrouvables depuis ce seul événement.
     */
    case MISSION_INTERVENTION_DRAFT_CREATED = 'MISSION_INTERVENTION_DRAFT_CREATED';

    /**
     * EPIC Revue instrumentiste, Lot 3, commit 5 — MissionInterventionDraftService::
     * resolve(). Un seul événement pour toute la résolution (création de la
     * MissionIntervention réelle + transition InterventionTypeRequest→RESOLVED +
     * transition MissionInterventionDraft→CONVERTED + repointage de tout le matériel),
     * même principe que MISSION_INTERVENTION_DRAFT_CREATED : une seule action manager,
     * un seul fait à auditer. Le payload porte les deux compteurs de matériel repointé
     * (materialLinesMovedCount/materialItemRequestsMovedCount) pour que l'audit seul
     * suffise à vérifier qu'aucune ligne n'a été oubliée, sans recharger les entités.
     */
    case MISSION_INTERVENTION_DRAFT_RESOLVED = 'MISSION_INTERVENTION_DRAFT_RESOLVED';
}