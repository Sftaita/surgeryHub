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
         * journaux distincts). H5b (D-132) : une livraison D-114 porte désormais LES DEUX
         * identifiants — son journal spécialisé et sa ligne outbound_notification (couche
         * basse commune) —, mis à jour ensemble par le handler et le listener d'échec.
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
        /**
         * H3 (D-129) — inline base64 PDF attachments. Superseded by $documents (H5a):
         * kept only so messages already queued before the H5a deployment are still sent.
         *
         * @var list<array{base64: string, filename: string}>
         */
        public readonly array $attachments = [],
        /**
         * H5a (§16) — attachments by reference (DocumentReference::toArray()): the handler
         * loads the stored GeneratedDocument and verifies its sha256. Never bytes here.
         *
         * @var list<array{documentId: int, documentType: string, filename: string, sha256: string, byteSize: int}>
         */
        public readonly array $documents = [],
        /**
         * H5a — false for an email carrying a secret (invitation token): the rendered body is
         * then never copied into the outbound_notification row.
         */
        public readonly bool $storeRenderedBody = true,
    ) {
    }
}