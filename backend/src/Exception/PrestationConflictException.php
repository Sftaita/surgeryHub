<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-156 — refus explicite (409) d'une opération sur une prestation regroupée ou sur les
 * données d'encodage d'une mission, avec un code stable et le contexte utile au client
 * (missions concernées, composition actuelle du groupe…) pour proposer un rafraîchissement
 * ou la correction contrôlée adéquate. Jamais une modification partielle silencieuse.
 */
final class PrestationConflictException extends ConflictHttpException
{
    /** La composition du groupe a changé depuis l'ouverture de l'écran. */
    public const PRESTATION_CHANGED = 'PRESTATION_CHANGED';
    /** La présence a été modifiée entre-temps (révision différente). */
    public const PRESENCE_REVISION_CHANGED = 'PRESENCE_REVISION_CHANGED';
    /** Une mission du groupe a déjà ses propres heures : conversion explicite requise. */
    public const PRESTATION_HAS_OWN_HOURS = 'PRESTATION_HAS_OWN_HOURS';
    /** Les missions du groupe sont liées à des présences différentes (correction manager). */
    public const PRESTATION_PRESENCE_CONFLICT = 'PRESTATION_PRESENCE_CONFLICT';
    /** Une mission liée a un calcul financier approuvé ou verrouillé. */
    public const PRESENCE_FINANCIALLY_SETTLED = 'PRESENCE_FINANCIALLY_SETTLED';
    /** Une mission liée est verrouillée / facturée. */
    public const PRESENCE_ENCODING_LOCKED = 'PRESENCE_ENCODING_LOCKED';
    /** Détacher la dernière mission laisserait une présence orpheline. */
    public const PRESENCE_LAST_MISSION = 'PRESENCE_LAST_MISSION';
    /** Heures saisies mission par mission sur une mission d'une prestation regroupée : à saisir une seule fois sur la prestation. */
    public const PRESTATION_REQUIRES_COMMON_HOURS = 'PRESTATION_REQUIRES_COMMON_HOURS';
    /** Soumission mission par mission d'une mission d'une prestation regroupée : soumettre la prestation. */
    public const PRESTATION_REQUIRES_COMMON_SUBMIT = 'PRESTATION_REQUIRES_COMMON_SUBMIT';
    /** Réaffectation / annulation / changement d'horaire d'une mission qui porte déjà des données d'encodage. */
    public const MISSION_HAS_ENCODING_DATA = 'MISSION_HAS_ENCODING_DATA';

    /** @param array<string,mixed> $extra */
    public function __construct(private readonly string $errorCode, string $message, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function getExtra(): array
    {
        return $this->extra;
    }
}
