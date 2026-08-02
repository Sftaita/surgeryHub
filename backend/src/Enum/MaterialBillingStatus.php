<?php

namespace App\Enum;

/**
 * Refonte Catalogue/Prestations (D-092) — distingue explicitement "volontairement non
 * facturé" de "tarif pas encore configuré", deux états jusqu'ici indiscernables (les
 * deux se traduisaient par une absence de PricingRule MATERIAL_FEE active).
 *
 * UNSPECIFIED : état par défaut/legacy — aucune décision commerciale explicite prise.
 *               L'absence de PricingRule dans ce cas reste traitée comme aujourd'hui
 *               (anomalie MISSING_FIRM_MATERIAL_RATE au calcul, jamais un 0€ silencieux).
 * BILLABLE    : le manager attend un tarif actif. Auto-promu depuis UNSPECIFIED/
 *               NOT_BILLABLE dès qu'une première PricingRule MATERIAL_FEE est créée
 *               (PricingRuleWriteService::create()) — pas de double geste manager requis
 *               dans le cas courant. Absence de règle active malgré ce statut = anomalie
 *               bloquante (comportement inchangé).
 * NOT_BILLABLE: décision commerciale explicite qu'aucun tarif ne s'applique. L'absence
 *               de PricingRule est alors un état valide — aucune ligne FIRM_MATERIAL_FEE
 *               n'est générée, aucune anomalie. Ne peut être posé que si aucune
 *               PricingRule MATERIAL_FEE active n'existe (MaterialCatalogController).
 */
enum MaterialBillingStatus: string
{
    case UNSPECIFIED = 'UNSPECIFIED';
    case BILLABLE = 'BILLABLE';
    case NOT_BILLABLE = 'NOT_BILLABLE';
}
