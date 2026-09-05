<?php

namespace App\Message;

final class SendTemplatedEmailMessage
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly string $htmlTemplate,
        public readonly array $context = [],
        public readonly ?string $textTemplate = null,
        /**
         * D-084 — id of the OutboundNotification row already persisted (QUEUED) before
         * this message was dispatched, so the handler/failure listener update the SAME
         * row across retries instead of creating a new one each time. Null for callers
         * predating D-084 (invitations, absences, billing emails) that don't track history yet.
         */
        public readonly ?int $outboundNotificationId = null,
        /**
         * Communication des absences chirurgiens, Lot A (D-114) — id de la
         * SurgeonAbsenceCommunicationDelivery déjà persistée (statut SCHEDULED) avant ce
         * dispatch, pour que le handler/listener de cette même livraison passent réellement
         * à SENT/FAILED — jamais un statut posé de manière optimiste au moment du dispatch,
         * qui ne prouve rien côté SMTP. Indépendant de $outboundNotificationId (deux
         * journaux distincts, jamais les deux à la fois sur un même message).
         */
        public readonly ?int $absenceCommunicationDeliveryId = null,
        /**
         * Communication des absences chirurgiens, Lot B (D-114) — emails « gestion du
         * bloc » : adresses CC configurées par site + le chirurgien concerné (déduplication
         * faite en amont par AbsenceCommunicationJournalService::resolveRecipients()).
         * Jamais utilisé par Lot A (un email individuel par collègue n'a pas de CC).
         *
         * @var list<string>
         */
        public readonly array $cc = [],
        /**
         * Communication des absences chirurgiens, Lot B (D-114) — Reply-To du chirurgien
         * concerné pour les emails « gestion du bloc » : si le bloc répond, la réponse doit
         * naturellement atteindre le chirurgien, sans jamais usurper le From SMTP
         * (l'expéditeur reste l'adresse SurgicalHub configurée). Aucun précédent dans ce
         * message avant ce lot.
         */
        public readonly ?string $replyTo = null,
    ) {
    }
}