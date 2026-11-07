<?php

namespace App\Service\MedVue;

/**
 * D-140 — refus ou échec d'une opération d'association MedVue. `reason` est un code stable,
 * renvoyé tel quel au frontend et consigné dans l'audit : il ne contient jamais le code saisi,
 * ni une réponse brute de MedVue, ni un détail technique.
 */
final class MedVueLinkException extends \RuntimeException
{
    public const NOT_CONFIGURED               = 'medvue_not_configured';
    public const INVALID_CODE_FORMAT          = 'invalid_code_format';
    public const RATE_LIMITED                 = 'rate_limited';
    public const ALREADY_LINKED_LOCALLY       = 'already_linked';
    public const INVALID_CODE                 = 'invalid_code';
    public const SURGICALHUB_ACCOUNT_TAKEN    = 'surgicalhub_account_linked_elsewhere';
    public const MEDVUE_ACCOUNT_TAKEN         = 'medvue_account_already_linked';
    public const LINK_ID_CONFLICT             = 'link_conflict';
    public const UNAVAILABLE                  = 'medvue_unavailable';
    public const NOT_LINKED                   = 'not_linked';

    private const MESSAGES = [
        self::NOT_CONFIGURED            => "L'intégration MedVue n'est pas configurée sur ce serveur.",
        self::INVALID_CODE_FORMAT       => "Ce code n'a pas le bon format (12 caractères, par exemple K7QM-2XPA-9DRT).",
        self::RATE_LIMITED              => 'Trop de tentatives. Réessayez dans quelques minutes.',
        self::ALREADY_LINKED_LOCALLY    => 'Ce compte est déjà associé à un compte MedVue. Dissociez-le d\'abord.',
        self::INVALID_CODE              => 'Code invalide ou expiré. Générez un nouveau code dans MedVue.',
        self::SURGICALHUB_ACCOUNT_TAKEN => 'Ce compte SurgicalHub est encore associé à un autre compte MedVue. Dissociez-le dans MedVue, ou réessayez dans 30 minutes si vous venez de le dissocier ici.',
        self::MEDVUE_ACCOUNT_TAKEN      => 'Ce compte MedVue est déjà associé à un autre compte SurgicalHub. Il doit d\'abord être dissocié dans MedVue.',
        self::LINK_ID_CONFLICT          => 'Association impossible : elle est déjà rattachée à un autre compte SurgicalHub.',
        self::UNAVAILABLE               => 'MedVue est momentanément injoignable. Réessayez plus tard ; votre code reste valable jusqu\'à son expiration.',
        self::NOT_LINKED                => 'Aucune association MedVue active pour ce compte.',
    ];

    private const STATUS = [
        self::NOT_CONFIGURED            => 503,
        self::INVALID_CODE_FORMAT       => 422,
        self::RATE_LIMITED              => 429,
        self::ALREADY_LINKED_LOCALLY    => 409,
        self::INVALID_CODE              => 422,
        self::SURGICALHUB_ACCOUNT_TAKEN => 409,
        self::MEDVUE_ACCOUNT_TAKEN      => 409,
        self::LINK_ID_CONFLICT          => 409,
        self::UNAVAILABLE               => 502,
        self::NOT_LINKED                => 404,
    ];

    public function __construct(public readonly string $reason, public readonly ?int $retryAfter = null)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'Erreur d\'intégration MedVue.');
    }

    public function httpStatus(): int
    {
        return self::STATUS[$this->reason] ?? 500;
    }
}
