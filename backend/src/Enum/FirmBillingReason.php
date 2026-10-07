<?php

namespace App\Enum;

/**
 * D-133 — catalogue STABLE des motifs de la worklist « Facturation firmes » (reasonCode).
 * Chaque motif appartient à exactement un FirmBillingStatus. Les codes d'anomalie du moteur
 * financier (FinancialCalculationService) y sont repris À L'IDENTIQUE, pour qu'un échec
 * audité se traduise sans table de correspondance cachée ; un code moteur inconnu devient
 * CALCULATION_FAILED (jamais le message technique brut).
 *
 * label()         : libellé court (badge, colonne « Motif », titre d'anomalie) ;
 * defaultDetail() : explication française générique — le service la remplace par un texte
 *                   contextualisé (firme, date, élément) quand il dispose du contexte.
 */
enum FirmBillingReason: string
{
    // ── Facturable / facturé ────────────────────────────────────────────────
    case BILLABLE = 'BILLABLE';
    /** D-135 — valorisée, placée dans un brouillon pas encore généré : toujours « facturable ». */
    case IN_DRAFT = 'IN_DRAFT';
    case INVOICED = 'INVOICED';

    // ── Non facturable : exclusions métier normales ─────────────────────────
    /** Forfait neutralisé par la présence du délégué de la firme (FirmServiceOffering). */
    case REPRESENTATIVE_PRESENT = 'REPRESENTATIVE_PRESENT';
    /** FirmServiceOffering.feeApplicable = false : la firme ne prévoit aucun forfait. */
    case FEE_NOT_APPLICABLE = 'FEE_NOT_APPLICABLE';
    /** MaterialItem.billingStatus = NOT_BILLABLE : décision catalogue explicite. */
    case MATERIAL_NOT_BILLABLE = 'MATERIAL_NOT_BILLABLE';
    /** Tarif actif mais montant nul (prix unitaire 0 ou quantité 0). */
    case ZERO_AMOUNT = 'ZERO_AMOUNT';

    // ── À vérifier : étape de workflow ──────────────────────────────────────
    case CALCULATION_REQUIRED = 'CALCULATION_REQUIRED';
    case CALCULATION_PENDING_APPROVAL = 'CALCULATION_PENDING_APPROVAL';
    /** Élément encodé (ou modifié) après le calcul actif : il n'y figure pas. */
    case RECALCULATION_REQUIRED = 'RECALCULATION_REQUIRED';
    /** Encodage rouvert après le calcul : la mission n'est plus validée. */
    case ENCODING_REOPENED = 'ENCODING_REOPENED';
    /** L'élément est correct mais une autre anomalie de la mission bloque tout le calcul. */
    case CALCULATION_BLOCKED = 'CALCULATION_BLOCKED';
    /** D-135 — le brouillon contient une version périmée de la ligne (calcul modifié depuis l'ajout). */
    case DRAFT_LINE_STALE = 'DRAFT_LINE_STALE';

    // ── À vérifier : anomalies du moteur (codes identiques au moteur) ───────
    case MISSING_FIRM_INTERVENTION_RATE = 'MISSING_FIRM_INTERVENTION_RATE';
    case MISSING_FIRM_MATERIAL_RATE = 'MISSING_FIRM_MATERIAL_RATE';
    case MISSING_INSTRUMENTIST_RATE = 'MISSING_INSTRUMENTIST_RATE';
    case MISSING_PRIMARY_FIRM = 'MISSING_PRIMARY_FIRM';
    case MISSING_INTERVENTION_TYPE = 'MISSING_INTERVENTION_TYPE';
    case MISSING_REPRESENTATIVE_PRESENCE_ANSWER = 'MISSING_REPRESENTATIVE_PRESENCE_ANSWER';
    case MISSING_REQUIRED_CHOICE_ANSWER = 'MISSING_REQUIRED_CHOICE_ANSWER';
    case INVALID_EFFECTIVE_DURATION = 'INVALID_EFFECTIVE_DURATION';
    /** Repli pour tout code moteur inconnu de ce catalogue. */
    case CALCULATION_FAILED = 'CALCULATION_FAILED';

    public static function fromEngineCode(string $code): self
    {
        $reason = self::tryFrom($code);

        return $reason !== null && $reason->isEngineAnomaly() ? $reason : self::CALCULATION_FAILED;
    }

    public function status(): FirmBillingStatus
    {
        return match ($this) {
            self::BILLABLE, self::IN_DRAFT => FirmBillingStatus::BILLABLE,
            self::INVOICED => FirmBillingStatus::INVOICED,
            self::REPRESENTATIVE_PRESENT, self::FEE_NOT_APPLICABLE, self::MATERIAL_NOT_BILLABLE, self::ZERO_AMOUNT => FirmBillingStatus::NOT_BILLABLE,
            default => FirmBillingStatus::TO_REVIEW,
        };
    }

    public function isEngineAnomaly(): bool
    {
        return in_array($this, [
            self::MISSING_FIRM_INTERVENTION_RATE, self::MISSING_FIRM_MATERIAL_RATE, self::MISSING_INSTRUMENTIST_RATE,
            self::MISSING_PRIMARY_FIRM, self::MISSING_INTERVENTION_TYPE, self::MISSING_REPRESENTATIVE_PRESENCE_ANSWER,
            self::MISSING_REQUIRED_CHOICE_ANSWER, self::INVALID_EFFECTIVE_DURATION, self::CALCULATION_FAILED,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::BILLABLE => 'Facturable',
            self::IN_DRAFT => 'Dans un brouillon',
            self::DRAFT_LINE_STALE => 'Ligne obsolète dans un brouillon',
            self::INVOICED => 'Déjà facturé',
            self::REPRESENTATIVE_PRESENT => 'Délégué présent',
            self::FEE_NOT_APPLICABLE => 'Aucun forfait prévu',
            self::MATERIAL_NOT_BILLABLE => 'Matériel non facturable',
            self::ZERO_AMOUNT => 'Tarif à 0 €',
            self::CALCULATION_REQUIRED => 'Calcul financier à effectuer',
            self::CALCULATION_PENDING_APPROVAL => 'Calcul à approuver',
            self::RECALCULATION_REQUIRED => 'Calcul à mettre à jour',
            self::ENCODING_REOPENED => 'Encodage rouvert',
            self::CALCULATION_BLOCKED => 'Calcul bloqué',
            self::MISSING_FIRM_INTERVENTION_RATE => "Tarif d'intervention manquant",
            self::MISSING_FIRM_MATERIAL_RATE => 'Tarif matériel manquant',
            self::MISSING_INSTRUMENTIST_RATE => 'Tarif instrumentiste manquant',
            self::MISSING_PRIMARY_FIRM => 'Firme non renseignée',
            self::MISSING_INTERVENTION_TYPE => 'Prestation hors référentiel',
            self::MISSING_REPRESENTATIVE_PRESENCE_ANSWER => 'Présence du délégué non renseignée',
            self::MISSING_REQUIRED_CHOICE_ANSWER => 'Choix obligatoire non renseigné',
            self::INVALID_EFFECTIVE_DURATION => 'Durée de mission invalide',
            self::CALCULATION_FAILED => 'Calcul financier en erreur',
        };
    }

    public function defaultDetail(): string
    {
        return match ($this) {
            self::BILLABLE => 'Ligne valorisée par le calcul financier approuvé, pas encore facturée.',
            self::IN_DRAFT => 'Ligne placée dans un brouillon de facture, pas encore générée.',
            self::DRAFT_LINE_STALE => "Le calcul de cette ligne a changé depuis son ajout au brouillon : retirez-la du brouillon, puis ajoutez la ligne à jour.",
            self::INVOICED => 'Cette ligne figure déjà sur une facture.',
            self::REPRESENTATIVE_PRESENT => "Cette prestation n'est pas facturée par SurgicalHub : le délégué de la firme était présent.",
            self::FEE_NOT_APPLICABLE => "La firme ne prévoit aucun forfait pour cette prestation (décision commerciale) : rien n'est facturé.",
            self::MATERIAL_NOT_BILLABLE => 'Ce matériel est marqué « non facturable » dans le catalogue de la firme.',
            self::ZERO_AMOUNT => 'Le tarif applicable donne un montant nul : rien à facturer.',
            self::CALCULATION_REQUIRED => "La mission est validée mais n'a pas encore été valorisée.",
            self::CALCULATION_PENDING_APPROVAL => 'Les montants sont calculés mais doivent être approuvés avant facturation.',
            self::RECALCULATION_REQUIRED => "Cet élément a été encodé après le dernier calcul financier : il n'y figure pas encore.",
            self::ENCODING_REOPENED => "L'encodage de la mission a été rouvert après le calcul : il doit être revalidé avant facturation.",
            self::CALCULATION_BLOCKED => 'Cet élément est correct, mais une autre anomalie de la mission empêche tout le calcul.',
            self::MISSING_FIRM_INTERVENTION_RATE => "Aucun tarif applicable n'est configuré pour cette prestation à la date de la mission.",
            self::MISSING_FIRM_MATERIAL_RATE => "Aucun tarif applicable n'est configuré pour ce matériel à la date de la mission.",
            self::MISSING_INSTRUMENTIST_RATE => "Aucun tarif instrumentiste actif à la date de la mission : tant qu'il manque, aucune ligne de la mission (firmes comprises) ne peut être calculée.",
            self::MISSING_PRIMARY_FIRM => "L'intervention n'a pas de firme : impossible de savoir à qui la facturer.",
            self::MISSING_INTERVENTION_TYPE => "L'intervention n'est rattachée à aucune prestation du référentiel.",
            self::MISSING_REPRESENTATIVE_PRESENCE_ANSWER => "L'encodage doit indiquer si le délégué de la firme était présent : cela détermine le forfait.",
            self::MISSING_REQUIRED_CHOICE_ANSWER => "Une réponse obligatoire manque dans l'encodage de cette intervention : elle détermine le forfait.",
            self::INVALID_EFFECTIVE_DURATION => 'La durée réelle de la mission est nulle ou négative : corrigez les horaires.',
            self::CALCULATION_FAILED => 'Le calcul financier a échoué pour une raison non reconnue. Le détail technique est disponible dans les journaux.',
        };
    }
}
