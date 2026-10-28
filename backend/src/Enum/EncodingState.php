<?php

namespace App\Enum;

/**
 * Suivi des encodages (D-118) — état DÉRIVÉ, jamais persisté, jamais un MissionStatus.
 *
 * Répond à une seule question, celle du manager au quotidien : "où en est l'encodage de
 * cette mission ?". Volontairement distinct de MissionStatus, qui mélange trois axes
 * (cycle de vie de l'affectation : DRAFT/OPEN/ASSIGNED/REJECTED/CANCELLED, fenêtre
 * horaire écoulée : IN_PROGRESS via D-064, et cycle d'encodage : ENCODING_IN_PROGRESS/
 * SUBMITTED/VALIDATED/CLOSED via D-070).
 *
 * La dérivation canonique — et unique — vit dans EncodingStateResolver. Ne jamais
 * réimplémenter cette logique ailleurs, ni en SQL, ni côté frontend.
 */
enum EncodingState: string
{
    /** Mission pas encore terminée chronologiquement, aucun encodage commencé. */
    case UPCOMING = 'UPCOMING';

    /** Mission terminée chronologiquement, aucun encodage commencé — action instrumentiste attendue. */
    case TO_ENCODE = 'TO_ENCODE';

    /** Encodage commencé (statut explicite ou données déjà saisies) mais pas encore soumis. */
    case IN_PROGRESS = 'IN_PROGRESS';

    /** L'instrumentiste déclare son encodage terminé — action manager attendue (valider/rejeter). */
    case SUBMITTED = 'SUBMITTED';

    /**
     * Manager a validé l'encodage. encodingLockedAt est posé, mais la mission reste
     * réouvrable (reopen()) : ce n'est PAS un verrouillage comptable.
     */
    case VALIDATED = 'VALIDATED';

    /**
     * Verrouillage réellement irréversible : une facture existe (invoiceGeneratedAt) ou
     * la mission est CLOSED (reopen() la refuse explicitement, D-070).
     */
    case LOCKED = 'LOCKED';

    /**
     * Statuts qui ne participent pas au cycle d'encodage (DRAFT, OPEN, REJECTED,
     * CANCELLED) : aucun encodage n'est ni possible ni attendu. Exclu des KPI
     * "à encoder"/"à traiter" — sinon une mission annulée resterait éternellement
     * comptée comme un retard d'encodage.
     */
    case NOT_APPLICABLE = 'NOT_APPLICABLE';

    /**
     * D-172 — mission terminée, publiée sans instrumentiste (OPEN), dont aucune activité n'a
     * été déclarée réalisée (D-155) : rien n'indique qu'elle a eu lieu ni qu'elle a été
     * encodée. Jamais déduit « réalisée » de sa seule date. Comme NOT_APPLICABLE, n'entre pas
     * dans les KPI d'encodage attendu (aucune instrumentiste à relancer) ; seul le manager peut
     * déclarer l'activité puis l'encoder.
     */
    case NOT_DECLARED = 'NOT_DECLARED';

    /** Libellé français centralisé — ne jamais réécrire ces libellés côté frontend. */
    public function label(): string
    {
        return match ($this) {
            self::UPCOMING       => 'À venir',
            self::TO_ENCODE      => 'À encoder',
            self::IN_PROGRESS    => 'En cours',
            self::SUBMITTED      => 'Soumis',
            self::VALIDATED      => 'Validé',
            self::LOCKED         => 'Verrouillé',
            self::NOT_APPLICABLE => 'Sans objet',
            self::NOT_DECLARED   => 'Aucun encodage',
        };
    }

    /**
     * L'encodage de cette mission est-il attendu (a-t-il un sens de le réclamer) ?
     * Sert de dénominateur aux KPI opérationnels : une mission NOT_APPLICABLE n'est
     * jamais un manquement.
     */
    public function isEncodingExpected(): bool
    {
        return $this !== self::NOT_APPLICABLE && $this !== self::NOT_DECLARED;
    }

    /** Nécessite une action du manager (et non de l'instrumentiste). */
    public function requiresManagerAction(): bool
    {
        return $this === self::SUBMITTED;
    }

    /** Nécessite une action de l'instrumentiste. */
    public function requiresInstrumentistAction(): bool
    {
        return $this === self::TO_ENCODE || $this === self::IN_PROGRESS;
    }
}
